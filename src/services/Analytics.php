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
use craftpulse\typesense\models\Settings;
use craftpulse\typesense\Typesense;
use Throwable;

/**
 * Search analytics service.
 *
 * Manages the analytics rules (popular queries, no-hit queries, counters, and
 * log rules) and their destination collections through the analytics API, sends
 * click and conversion events, and reads the destination collections back for
 * the dashboard. Analytics is opt-in: the plugin never collects anything unless
 * the operator turns it on (settings default OFF) and the server itself is
 * started with search analytics enabled. User-id association is off by default.
 *
 * The analytics rule/event endpoints are driven over raw HTTP so the shape is
 * stable across the supported server versions; the destination collections are
 * read through the ordinary search path.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class Analytics extends Component
{
    // Constants
    // =========================================================================

    /**
     * @var string A popular-queries rule (counts the most frequent queries).
     */
    public const TYPE_POPULAR_QUERIES = 'popular_queries';

    /**
     * @var string A no-hits rule (counts queries that returned nothing).
     */
    public const TYPE_NOHITS_QUERIES = 'nohits_queries';

    /**
     * @var string A counter rule (increments a document field on events).
     */
    public const TYPE_COUNTER = 'counter';

    /**
     * @var string A log rule (raw event logging).
     */
    public const TYPE_LOG = 'log';

    // Public Methods
    // =========================================================================

    /**
     * Wires native personalization for a collection by creating the analytics
     * `log` rule that records the interaction events personalization models learn
     * from. Version-gated (v30.2+); returns false on servers without native
     * personalization (hide, never badge). Personalization is undocumented
     * upstream and its rule shape shifts across v30 builds, so the gate is
     * authoritative and the log-rule wiring is best-effort (fail-soft).
     * `$collection` and `$destination` are logical names; `ensureDestination()`
     * and `upsertRule()` resolve them to their physical, prefixed names.
     *
     * @param string $collection The source collection handle.
     * @param string $destination The log destination collection.
     * @return bool
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function configurePersonalizationLog(string $collection, string $destination): bool
    {
        if (!(Typesense::$plugin->getClient()->getServerCapabilities()?->personalizationModels() ?? false)) {
            return false;
        }

        $this->ensureDestination($destination);
        $this->upsertRule("{$collection}_personalization_log", self::TYPE_LOG, [
            'source' => ['collections' => [$collection]],
            'destination' => ['collection' => $destination],
        ]);

        return true;
    }

    /**
     * Deletes an analytics rule (fail-soft).
     *
     * @param string $name
     * @return void
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function deleteRule(string $name): void
    {
        Typesense::$plugin->getClient()->request('DELETE', '/analytics/rules/' . $name);
    }

    /**
     * Ensures a destination collection exists for an aggregation rule (the
     * `q`/`count` shape popular and no-hit rules write to). The name is resolved
     * through `Client::prefixedCollectionName()` before it touches the server, the
     * same seam Search and Sync use, so an install with a collection prefix
     * creates the destination under its physical, prefixed name rather than a
     * bare logical one.
     *
     * @param string $name
     * @return void
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function ensureDestination(string $name): void
    {
        $client = Typesense::$plugin->getClient();
        $sdkClient = $client->client();
        $name = $client->prefixedCollectionName($name);

        if ($sdkClient === null) {
            return;
        }

        try {
            $sdkClient->collections[$name]->retrieve();

            return;
        } catch (Throwable) {
            // Not present: create it below.
        }

        try {
            $sdkClient->collections->create([
                'name' => $name,
                'fields' => [
                    ['name' => 'q', 'type' => 'string'],
                    ['name' => 'count', 'type' => 'int32'],
                ],
            ]);
        } catch (Throwable $e) {
            Craft::error("Could not create analytics destination '{$name}': {$e->getMessage()}", 'typesense');
        }
    }

    /**
     * Whether analytics collection is opted into in the plugin settings.
     *
     * @return bool
     * @author CraftPulse
     */
    public function isEnabled(): bool
    {
        return $this->_settings()->analyticsEnabled;
    }

    /**
     * Whether the Typesense server was started with search analytics enabled.
     * Detected by probing the rules endpoint (which 400s when analytics is off).
     *
     * @return bool
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function isServerEnabled(): bool
    {
        $client = Typesense::$plugin->getClient();

        if (!$client->isConfigured()) {
            return false;
        }

        // The rules endpoint answers with a `rules` key when analytics is enabled
        // and 400s (fail-soft to an empty array) when it is not.
        return array_key_exists('rules', $client->request('GET', '/analytics/rules'));
    }

    /**
     * The most frequent queries recorded in a destination collection.
     *
     * @param string $destination
     * @param int $limit
     * @return array<int, array{q: string, count: int}>
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function popularQueries(string $destination, int $limit = 20): array
    {
        return $this->_readCounts($destination, $limit);
    }

    /**
     * The queries that returned no hits, recorded in a destination collection.
     *
     * @param string $destination
     * @param int $limit
     * @return array<int, array{q: string, count: int}>
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function noHitsQueries(string $destination, int $limit = 20): array
    {
        return $this->_readCounts($destination, $limit);
    }

    /**
     * The analytics rules on the server.
     *
     * @return array<int, array<string, mixed>>
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function rules(): array
    {
        return array_values(Typesense::$plugin->getClient()->request('GET', '/analytics/rules')['rules'] ?? []);
    }

    /**
     * Sends an analytics event (click or conversion). User-id is only attached
     * when the operator opts into user-id association. Fail-soft.
     *
     * @param string $type One of search, click, conversion, visit.
     * @param string $name The rule/event name.
     * @param array<string, mixed> $data The event payload (doc_id, q, ...).
     * @return bool
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function sendEvent(string $type, string $name, array $data): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        if (!$this->_settings()->analyticsUserIdEnabled) {
            unset($data['user_id']);
        }

        $response = Typesense::$plugin->getClient()->request('POST', '/analytics/events', [
            'type' => $type,
            'name' => $name,
            'data' => $data,
        ]);

        return ($response['ok'] ?? false) === true;
    }

    /**
     * Creates or updates an analytics rule. The rule name is an opaque analytics
     * identifier and is sent as-is, but the collection references embedded in
     * `$params` (`source.collections` and `destination.collection`) are resolved
     * through `Client::prefixedCollectionName()` first, the same seam Search and
     * Sync use, so an install with a collection prefix writes the rule against
     * its physical, prefixed collections rather than bare logical ones that may
     * not exist (or belong to another environment sharing the same server).
     *
     * @param string $name
     * @param string $type One of the TYPE_* constants.
     * @param array<string, mixed> $params The rule params (source, destination, limit, ...).
     * @return bool
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function upsertRule(string $name, string $type, array $params): bool
    {
        $response = Typesense::$plugin->getClient()->request('PUT', '/analytics/rules/' . $name, [
            'type' => $type,
            'params' => $this->_prefixRuleCollections($params),
        ]);

        return ($response['name'] ?? null) === $name;
    }

    // Private Methods
    // =========================================================================

    /**
     * Reads a `q`/`count` destination collection, most frequent first. The
     * destination is resolved through `Client::prefixedCollectionName()` first,
     * matching the physical name `ensureDestination()` and `upsertRule()` create
     * and write to.
     *
     * @param string $destination
     * @param int $limit
     * @return array<int, array{q: string, count: int}>
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    private function _readCounts(string $destination, int $limit): array
    {
        $client = Typesense::$plugin->getClient();
        $sdkClient = $client->client();
        $destination = $client->prefixedCollectionName($destination);

        if ($sdkClient === null) {
            return [];
        }

        try {
            $result = $sdkClient->collections[$destination]->documents->search([
                'q' => '*',
                'query_by' => 'q',
                'sort_by' => 'count:desc',
                'per_page' => max(1, min(250, $limit)),
            ]);
        } catch (Throwable) {
            return [];
        }

        $rows = [];

        foreach ($result['hits'] ?? [] as $hit) {
            $document = $hit['document'] ?? [];
            $rows[] = ['q' => (string)($document['q'] ?? ''), 'count' => (int)($document['count'] ?? 0)];
        }

        return $rows;
    }

    /**
     * Rewrites the source and destination collection references embedded in a
     * rule's params to their prefixed, physical Typesense names. Analytics rules
     * are written over the raw HTTP API and otherwise bypass
     * `Client::prefixedCollectionName()` entirely, the seam Search and Sync
     * resolve every collection name through, so on any install using a
     * collection prefix the rule would target a nonexistent collection (or
     * another environment's, if it shares the same server).
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    private function _prefixRuleCollections(array $params): array
    {
        $client = Typesense::$plugin->getClient();

        if (isset($params['source']['collections']) && is_array($params['source']['collections'])) {
            $params['source']['collections'] = array_map(
                static fn(mixed $collection): string => $client->prefixedCollectionName((string)$collection),
                $params['source']['collections'],
            );
        }

        if (isset($params['destination']['collection']) && is_string($params['destination']['collection'])) {
            $params['destination']['collection'] = $client->prefixedCollectionName($params['destination']['collection']);
        }

        return $params;
    }

    /**
     * @return Settings
     * @author CraftPulse
     */
    private function _settings(): Settings
    {
        /** @var Settings $settings */
        $settings = Typesense::$plugin->getSettings();

        return $settings;
    }
}
