<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use craftpulse\typesense\exceptions\UnsupportedServerException;
use craftpulse\typesense\models\ServerCapabilities;
use craftpulse\typesense\models\Settings;
use craftpulse\typesense\Typesense;
use Throwable;
use Typesense\Client as TypesenseClient;

/**
 * Typesense client service.
 *
 * Owns the typesense-php client: builds it from the plugin settings (single node,
 * self-managed cluster, or Typesense Cloud with a nearest node), applies the
 * resilience options (timeouts, retries, retry backoff, health-check interval),
 * and exposes health, server-version detection, and the derived ServerCapabilities.
 *
 * Every read that touches the network is fail-soft: connection problems yield a
 * well-formed empty response (or null), never an uncaught exception. The single
 * hard failure is an unsupported server version, surfaced through
 * requireSupportedServer() for write paths that must refuse to run.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class Client extends Component
{
    // Private Properties
    // =========================================================================

    /**
     * @var TypesenseClient|null The memoized typesense-php client.
     */
    private ?TypesenseClient $_client = null;

    /**
     * @var bool Whether a client build has been attempted this request.
     */
    private bool $_clientAttempted = false;

    /**
     * @var ServerCapabilities|null The memoized server capabilities.
     */
    private ?ServerCapabilities $_capabilities = null;

    // Public Methods
    // =========================================================================

    /**
     * Returns the typesense-php client, or null when the plugin is not configured
     * or the client cannot be built.
     *
     * @return TypesenseClient|null
     * @author CraftPulse
     */
    public function client(): ?TypesenseClient
    {
        if ($this->_clientAttempted) {
            return $this->_client;
        }

        $this->_clientAttempted = true;

        if (!$this->isConfigured()) {
            Craft::warning('Typesense is not configured: provide an admin API key in the plugin settings.', 'typesense');

            return null;
        }

        try {
            $this->_client = new TypesenseClient($this->_config());
        } catch (Throwable $e) {
            Craft::error("Could not build the Typesense client: {$e->getMessage()}", 'typesense');
            $this->_client = null;
        }

        return $this->_client;
    }

    /**
     * Returns whether the plugin has the minimum configuration to build a client.
     *
     * @return bool
     * @author CraftPulse
     */
    public function isConfigured(): bool
    {
        return (bool)App::parseEnv($this->_settings()->apiKey);
    }

    /**
     * Makes a raw HTTP request to the Typesense API, for endpoints the bundled SDK
     * does not model (synonym_sets, curation_sets, presets, stopwords, stemming).
     * Fail-soft: returns an empty array on error.
     *
     * @param string $method
     * @param string $path
     * @param array<string, mixed>|null $json
     * @param string|null $body
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     * @author CraftPulse
     */
    public function request(string $method, string $path, ?array $json = null, ?string $body = null, array $query = []): array
    {
        $settings = $this->_settings();
        $base = sprintf(
            '%s://%s:%s',
            (string)App::parseEnv($settings->protocol),
            (string)App::parseEnv($settings->server),
            (string)App::parseEnv($settings->port),
        );

        $options = ['headers' => ['X-TYPESENSE-API-KEY' => App::parseEnv($settings->apiKey)]];

        if ($json !== null) {
            $options['json'] = $json;
        }

        if ($body !== null) {
            $options['body'] = $body;
        }

        if ($query !== []) {
            $options['query'] = $query;
        }

        try {
            $response = Craft::createGuzzleClient(['base_uri' => $base])->request($method, $path, $options);

            return (array)json_decode((string)$response->getBody(), true);
        } catch (Throwable $e) {
            Craft::error("Typesense request failed ({$method} {$path}): {$e->getMessage()}", 'typesense');

            return [];
        }
    }

    /**
     * Streams a conversational (RAG) answer token-by-token, invoking the callback
     * with each message chunk as the server generates it. Uses Typesense's
     * `conversation_stream` multi_search (a Server-Sent Events response, v29+),
     * which the bundled SDK does not model, so this reads the streamed body
     * directly through Guzzle. Fail-soft: on any error the callback is simply not
     * invoked further, and the caller is responsible for a graceful close. The
     * admin key stays server-side.
     *
     * @param string $collection the (prefixed at call time) collection handle
     * @param string $modelId the conversation model id
     * @param array<string, mixed> $search the base search params (q, query_by, ...)
     * @param callable $onChunk fn(string $message): void, one message chunk at a time
     * @return void
     * @author CraftPulse
     */
    public function streamConversation(string $collection, string $modelId, array $search, callable $onChunk): void
    {
        $settings = $this->_settings();
        $base = sprintf(
            '%s://%s:%s',
            (string)App::parseEnv($settings->protocol),
            (string)App::parseEnv($settings->server),
            (string)App::parseEnv($settings->port),
        );

        $payload = ['searches' => [array_merge(['collection' => $this->prefixedCollectionName($collection)], $search)]];

        try {
            $response = Craft::createGuzzleClient(['base_uri' => $base])->request('POST', '/multi_search', [
                'headers' => ['X-TYPESENSE-API-KEY' => App::parseEnv($settings->apiKey)],
                'query' => [
                    'conversation' => 'true',
                    'conversation_model_id' => $modelId,
                    'conversation_stream' => 'true',
                ],
                'json' => $payload,
                'stream' => true,
            ]);
        } catch (Throwable $e) {
            Craft::error("Typesense conversation stream failed: {$e->getMessage()}", 'typesense');

            return;
        }

        $body = $response->getBody();
        $buffer = '';

        // Read the SSE body as it arrives and emit each complete `data:` event's
        // conversation message. Events are separated by a blank line.
        while (!$body->eof()) {
            $buffer .= $body->read(1024);

            while (($break = strpos($buffer, "\n\n")) !== false) {
                $event = substr($buffer, 0, $break);
                $buffer = substr($buffer, $break + 2);
                $message = $this->_conversationChunkMessage($event);

                if ($message !== null) {
                    $onChunk($message);
                }
            }
        }
    }

    /**
     * Returns the server health payload, fail-soft.
     *
     * @return array{ok: bool}
     * @author CraftPulse
     */
    public function getHealth(): array
    {
        $client = $this->client();

        if ($client === null) {
            return ['ok' => false];
        }

        try {
            $health = $client->health->retrieve();

            return ['ok' => (bool)($health['ok'] ?? false)];
        } catch (Throwable $e) {
            Craft::error("Typesense health check failed: {$e->getMessage()}", 'typesense');

            return ['ok' => false];
        }
    }

    /**
     * Returns the server metrics (memory, disk, CPU, request counts) from
     * `/metrics.json`, fail-soft.
     *
     * @return array<string, mixed>
     * @author CraftPulse
     */
    public function metrics(): array
    {
        return $this->request('GET', '/metrics.json');
    }

    /**
     * Returns the server per-endpoint latency stats from `/stats.json`,
     * fail-soft.
     *
     * @return array<string, mixed>
     * @author CraftPulse
     */
    public function stats(): array
    {
        return $this->request('GET', '/stats.json');
    }

    /**
     * Returns whether the server is reachable and healthy.
     *
     * @return bool
     * @author CraftPulse
     */
    public function isConnected(): bool
    {
        return $this->getHealth()['ok'];
    }

    /**
     * Returns the detected server version string, or null when unreachable.
     *
     * @return string|null
     * @author CraftPulse
     */
    public function getServerVersion(): ?string
    {
        $capabilities = $this->getServerCapabilities();

        return $capabilities?->version ?: null;
    }

    /**
     * Returns the derived server capabilities, or null when the version cannot be
     * detected (unreachable server).
     *
     * @return ServerCapabilities|null
     * @author CraftPulse
     */
    public function getServerCapabilities(): ?ServerCapabilities
    {
        if ($this->_capabilities !== null) {
            return $this->_capabilities;
        }

        $version = $this->_detectVersion();

        if ($version === null) {
            return null;
        }

        $capabilities = new ServerCapabilities();
        $capabilities->version = $version;
        $this->_capabilities = $capabilities;

        return $this->_capabilities;
    }

    /**
     * Throws when the connected server version is unsupported. Write paths call
     * this to refuse to run against v30.0 / v30.1 or a below-floor server.
     *
     * @return void
     * @throws UnsupportedServerException
     * @author CraftPulse
     */
    public function requireSupportedServer(): void
    {
        $capabilities = $this->getServerCapabilities();

        if ($capabilities === null) {
            return;
        }

        if (!$capabilities->isSupported()) {
            throw new UnsupportedServerException((string)$capabilities->getUnsupportedReason());
        }
    }

    /**
     * Returns a status summary for the control panel and console: configuration,
     * connectivity, detected version, support state, and capability flags.
     *
     * @return array<string, mixed>
     * @author CraftPulse
     */
    public function getStatus(): array
    {
        $configured = $this->isConfigured();
        $capabilities = $configured ? $this->getServerCapabilities() : null;

        return [
            'configured' => $configured,
            'connected' => $configured && $this->isConnected(),
            'version' => $capabilities?->version,
            'supported' => $capabilities?->isSupported() ?? false,
            'unsupportedReason' => $capabilities?->getUnsupportedReason(),
            'flags' => $capabilities?->getFlags() ?? [],
        ];
    }

    /**
     * Runs a search against a collection, fail-soft: on any connection or query
     * failure it returns a well-formed empty result rather than raising.
     *
     * @param string $collection
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @author CraftPulse
     */
    public function search(string $collection, array $params): array
    {
        $client = $this->client();

        if ($client !== null) {
            try {
                return $client->collections[$this->prefixedCollectionName($collection)]->documents->search($params);
            } catch (Throwable $e) {
                Craft::error("Typesense search on '{$collection}' failed: {$e->getMessage()}", 'typesense');
            }
        }

        return $this->_emptySearchResult($params);
    }

    /**
     * Returns the configured collection-name prefix, with env variables resolved.
     *
     * @return string
     * @author CraftPulse
     */
    public function getCollectionPrefix(): string
    {
        return (string)App::parseEnv($this->_settings()->collectionPrefix);
    }

    /**
     * Applies the configured prefix to a collection name.
     *
     * @param string $name
     * @return string
     * @author CraftPulse
     */
    public function prefixedCollectionName(string $name): string
    {
        $prefix = $this->getCollectionPrefix();

        if ($prefix === '' || str_starts_with($name, $prefix)) {
            return $name;
        }

        return $prefix . $name;
    }

    // Private Methods
    // =========================================================================

    /**
     * Extracts the conversation message from a single Server-Sent Events block of
     * the `conversation_stream` response (a `data: {"conversation": {"message":
     * "..."}}` line), or null when the block carries no message chunk.
     *
     * @param string $event one SSE event block
     * @return string|null
     * @author CraftPulse
     */
    private function _conversationChunkMessage(string $event): ?string
    {
        foreach (explode("\n", $event) as $line) {
            $line = trim($line);

            if (!str_starts_with($line, 'data:')) {
                continue;
            }

            $decoded = json_decode(trim(substr($line, 5)), true);
            $message = is_array($decoded) ? ($decoded['conversation']['message'] ?? null) : null;

            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        return null;
    }

    /**
     * Returns the plugin settings.
     *
     * @return Settings
     * @author CraftPulse
     */
    private function _settings(): Settings
    {
        /** @var Settings $settings */
        $settings = Typesense::$plugin->getSettings();

        return $settings;
    }

    /**
     * Builds the typesense-php client configuration array from the settings.
     *
     * @return array<string, mixed>
     * @author CraftPulse
     */
    private function _config(): array
    {
        $settings = $this->_settings();

        $config = [
            'api_key' => App::parseEnv($settings->apiKey),
            'nodes' => $this->_nodes(),
            'connection_timeout_seconds' => $this->_number($settings->connectionTimeoutSeconds, 2),
            'healthcheck_interval_seconds' => $this->_number($settings->healthcheckIntervalSeconds, 60),
            'num_retries' => $this->_number($settings->numRetries, 3),
            'retry_interval_seconds' => $this->_number($settings->retryIntervalSeconds, 1),
        ];

        $nearestNode = $this->_nearestNode();

        if ($nearestNode !== null) {
            $config['nearest_node'] = $nearestNode;
        }

        return $config;
    }

    /**
     * Builds the node list from the settings, honoring the connection shape.
     *
     * @return array<int, array<string, string>>
     * @author CraftPulse
     */
    private function _nodes(): array
    {
        $settings = $this->_settings();

        if ($settings->serverType === Settings::SERVER_TYPE_SINGLE) {
            return [
                [
                    'host' => (string)App::parseEnv($settings->server),
                    'port' => (string)App::parseEnv($settings->port),
                    'protocol' => (string)App::parseEnv($settings->protocol),
                ],
            ];
        }

        $hosts = array_filter(array_map('trim', explode(';', (string)App::parseEnv($settings->cluster))));
        $port = (string)App::parseEnv($settings->clusterPort);
        $nodes = [];

        foreach ($hosts as $host) {
            $nodes[] = [
                'host' => $host,
                'port' => $port,
                'protocol' => 'https',
            ];
        }

        return $nodes;
    }

    /**
     * Builds the nearest-node config for Typesense Cloud, or null when unset.
     *
     * @return array<string, string>|null
     * @author CraftPulse
     */
    private function _nearestNode(): ?array
    {
        $settings = $this->_settings();

        if ($settings->serverType !== Settings::SERVER_TYPE_CLOUD) {
            return null;
        }

        $host = (string)App::parseEnv($settings->nearestNode);

        if ($host === '') {
            return null;
        }

        return [
            'host' => $host,
            'port' => (string)App::parseEnv($settings->clusterPort),
            'protocol' => 'https',
        ];
    }

    /**
     * Detects the server version via GET /debug, cached for the health-check
     * interval. Fail-soft: returns null when the server is unreachable.
     *
     * @return string|null
     * @author CraftPulse
     */
    private function _detectVersion(): ?string
    {
        $client = $this->client();

        if ($client === null) {
            return null;
        }

        try {
            $debug = $client->debug->retrieve();
            $version = $debug['version'] ?? null;

            return is_string($version) && $version !== '' ? $version : null;
        } catch (Throwable $e) {
            Craft::error("Could not detect the Typesense server version: {$e->getMessage()}", 'typesense');

            return null;
        }
    }

    /**
     * Resolves an env-aware numeric setting to a number, falling back to a default.
     *
     * @param string|null $raw
     * @param int|float $default
     * @return int|float
     * @author CraftPulse
     */
    private function _number(?string $raw, int|float $default): int|float
    {
        $value = App::parseEnv($raw);

        if ($value === null || $value === '' || !is_numeric($value)) {
            return $default;
        }

        return $value + 0;
    }

    /**
     * Returns a well-formed empty search result mirroring Typesense's shape.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @author CraftPulse
     */
    private function _emptySearchResult(array $params): array
    {
        return [
            'found' => 0,
            'out_of' => 0,
            'page' => (int)($params['page'] ?? 1),
            'hits' => [],
            'facet_counts' => [],
            'search_time_ms' => 0,
            'request_params' => $params,
        ];
    }
}
