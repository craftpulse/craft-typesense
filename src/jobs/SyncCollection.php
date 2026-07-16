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
 * Full sync of one collection for one site, processed in bounded slices.
 *
 * The job walks the element query in page-sized slices to keep memory flat, and
 * re-queues a continuation slice if it approaches the Craft Cloud 15-minute job
 * cap, so a large sync survives across multiple job runs.
 *
 * @property \craft\queue\Queue $queue
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class SyncCollection extends BaseJob
{
    // Constants
    // =========================================================================

    /**
     * @var int Seconds after which the job re-queues a continuation slice (below the Cloud 15-minute cap).
     */
    public const MAX_SECONDS = 780;

    // Public Properties
    // =========================================================================

    /**
     * @var string The logical collection name.
     */
    public string $collectionHandle = '';

    /**
     * @var int The site ID being synced.
     */
    public int $siteId = 0;

    /**
     * @var int The element-query offset to resume from.
     */
    public int $cursor = 0;

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

        $sync->ensureCollectionExists($collection, $this->siteId);

        $offset = $this->cursor;
        $total = max(1, $sync->countForSync($collection, $this->siteId));
        $pageSize = $collection->getPageSize();
        $startedAt = time();

        while (true) {
            $processed = $sync->syncSlice($collection, $this->siteId, $offset, $pageSize);

            if ($processed === 0) {
                $sync->finalizeSync($collection, $this->siteId);
                break;
            }

            $offset += $processed;
            $this->setProgress($queue, min(1, $offset / $total));

            if ((time() - $startedAt) > self::MAX_SECONDS) {
                Craft::$app->getQueue()->priority($sync->getQueuePriority())->push(new self([
                    'collectionHandle' => $this->collectionHandle,
                    'siteId' => $this->siteId,
                    'cursor' => $offset,
                ]));

                return;
            }
        }
    }

    /**
     * @inheritdoc
     */
    public function getTtr(): int
    {
        return 900;
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('typesense', 'Syncing {collection} (site {site})', [
            'collection' => $this->collectionHandle,
            'site' => $this->siteId,
        ]);
    }
}
