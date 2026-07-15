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
     * `q`/`count` shape popular and no-hit rules write to).
     *
     * @param string $name
     * @return void
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function ensureDestination(string $name): void
    {
        $client = Typesense::$plugin->getClient()->client();

        if ($client === null) {
            return;
        }

        try {
            $client->collections[$name]->retrieve();

            return;
        } catch (Throwable) {
            // Not present: create it below.
        }

        try {
            $client->collections->create([
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
     * Creates or updates an analytics rule.
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
            'params' => $params,
        ]);

        return ($response['name'] ?? null) === $name;
    }

    // Private Methods
    // =========================================================================

    /**
     * Reads a `q`/`count` destination collection, most frequent first.
     *
     * @param string $destination
     * @param int $limit
     * @return array<int, array{q: string, count: int}>
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    private function _readCounts(string $destination, int $limit): array
    {
        $client = Typesense::$plugin->getClient()->client();

        if ($client === null) {
            return [];
        }

        try {
            $result = $client->collections[$destination]->documents->search([
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
