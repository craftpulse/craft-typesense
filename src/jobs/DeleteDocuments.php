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
 * Deletes the documents for specific elements from a collection and site.
 *
 * @property \craft\queue\Queue $queue
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class DeleteDocuments extends BaseJob
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

    /**
     * @var array<int, int> The element IDs whose documents to delete.
     */
    public array $elementIds = [];

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

        if ($collection === null || !$sync->isEnabled()) {
            return;
        }

        $sync->deleteDocuments($collection, $this->siteId, $this->elementIds);
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('typesense', 'Deleting {count} document(s) from {collection}', [
            'count' => count($this->elementIds),
            'collection' => $this->collectionHandle,
        ]);
    }
}
