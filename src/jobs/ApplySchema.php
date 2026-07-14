<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\jobs;

use Craft;
use craft\queue\BaseJob;
use craftpulse\typesense\Typesense;
use Throwable;
use Typesense\Exceptions\ObjectNotFound;

/**
 * Applies additive schema changes to a live collection, queued and polled.
 *
 * Schema changes block writes cluster-wide, so this always runs on the queue
 * (never a synchronous save) and polls the collection until the new fields
 * appear before returning. Only field additions are applied here; removals and
 * type changes require a flush and rebuild.
 *
 * @property \craft\queue\Queue $queue
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class ApplySchema extends BaseJob
{
    // Constants
    // =========================================================================

    /**
     * @var int The maximum number of poll attempts (one per second).
     */
    public const MAX_POLLS = 60;

    // Public Properties
    // =========================================================================

    /**
     * @var string The logical collection name.
     */
    public string $collectionHandle = '';

    /**
     * @var int The site ID.
     */
    public int $siteId = 0;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @throws Throwable
     */
    public function execute($queue): void
    {
        $sync = Typesense::$plugin->getSync();
        $collection = $sync->getCollection($this->collectionHandle);
        $client = Typesense::$plugin->getClient()->client();

        if ($collection === null || $client === null) {
            return;
        }

        $registry = Typesense::$plugin->getCollectionRegistry();
        $target = $registry->resolveName($collection, $this->siteId);
        $declared = $registry->getCreateSchema($collection, $this->siteId)['fields'];

        try {
            $liveNames = array_column($client->collections[$target]->retrieve()['fields'] ?? [], 'name');
        } catch (ObjectNotFound) {
            // No collection yet: a full sync will create it with the full schema.
            return;
        }

        $missing = array_values(array_filter(
            $declared,
            static fn(array $field): bool => !in_array($field['name'], $liveNames, true),
        ));

        if ($missing === []) {
            return;
        }

        // Additive schema change (blocks writes cluster-wide while it runs).
        $client->collections[$target]->update(['fields' => $missing]);
        $this->_pollUntilApplied($target, array_column($missing, 'name'));
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('typesense', 'Applying schema changes to {collection}', [
            'collection' => $this->collectionHandle,
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Polls the collection until the new field names appear.
     *
     * @param string $target
     * @param array<int, string> $expectedNames
     * @return void
     * @author CraftPulse
     */
    private function _pollUntilApplied(string $target, array $expectedNames): void
    {
        $client = Typesense::$plugin->getClient()->client();

        if ($client === null) {
            return;
        }

        for ($attempt = 0; $attempt < self::MAX_POLLS; $attempt++) {
            try {
                $liveNames = array_column($client->collections[$target]->retrieve()['fields'] ?? [], 'name');

                if (array_diff($expectedNames, $liveNames) === []) {
                    return;
                }
            } catch (Throwable $e) {
                Craft::error("Polling schema change for '{$target}' failed: {$e->getMessage()}", 'typesense');
            }

            sleep(1);
        }
    }
}
