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

/**
 * Reconciles a collection and site: deletes indexed documents whose source
 * element is no longer returned by the collection's element query (replacing the
 * legacy export-diff-delete with a tracked-ID batch reconciliation).
 *
 * @property \craft\queue\Queue $queue
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class ReconcileCollection extends BaseJob
{
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
     * @throws \Throwable
     */
    public function execute($queue): void
    {
        $sync = Typesense::$plugin->getSync();
        $collection = $sync->getCollection($this->collectionHandle);

        if ($collection === null || !$sync->isCollectionSyncable($this->collectionHandle)) {
            return;
        }

        $sync->reconcileCollection($collection, $this->siteId);
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('typesense', 'Reconciling {collection} (site {site})', [
            'collection' => $this->collectionHandle,
            'site' => $this->siteId,
        ]);
    }
}
