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

/**
 * Rebuilds a collection with zero downtime, queued.
 *
 * A rebuild builds a fresh physical collection, copies the documents into it,
 * atomically swaps the alias, and drops the old physical collection. It always
 * runs on the queue (never a synchronous save), because building and swapping
 * must not block the request, and the alias keeps the logical name queryable
 * throughout.
 *
 * @property \craft\queue\Queue $queue
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class RebuildCollection extends BaseJob
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
     * @throws Throwable
     */
    public function execute($queue): void
    {
        $collection = Typesense::$plugin->getSync()->getCollection($this->collectionHandle);

        if ($collection === null) {
            return;
        }

        $this->setProgress($queue, 0.1, Craft::t('typesense', 'Building the new collection'));
        Typesense::$plugin->getAliases()->rebuild($collection, $this->siteId);
        $this->setProgress($queue, 1);
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('typesense', 'Rebuilding {collection} (zero downtime)', [
            'collection' => $this->collectionHandle,
        ]);
    }
}
