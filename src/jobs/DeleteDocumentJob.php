<?php
/**
 * Typesense plugin for Craft CMS 4.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://percipio.london
 * @copyright Copyright (c) 2022 percipiolondon
 */

namespace percipiolondon\typesense\jobs;

use Craft;
use craft\queue\BaseJob;

use percipiolondon\typesense\helpers\CollectionHelper;
use percipiolondon\typesense\Typesense;

/**
 * TypesenseTask job
 *
 * Upserts the documents in a collection
 *
 * use percipiolondon\typesense\jobs\SyncDocumentsTask;
 *
 * Queue::push(new SyncDocumentsTask([
 *   'criteria' => [
 *      'index' => 'index',
 *      'isNew' => true
 *   ]
 * ]));
 *
 * @author    percipiolondon
 * @package   Typesense
 * @since     1.0.0
 */
class DeleteDocumentJob extends BaseJob
{
    // Public Properties
    // =========================================================================

    /**
     * @var array
     */
    public array $criteria = [];

    // Public Methods
    // =========================================================================

    public function execute($queue): void
    {
        $collection = CollectionHelper::getCollectionBySection($this->criteria['section']);
        $client = Typesense::$plugin->getClient()->client();

        if ($client !== false && !is_null($collection)) {
            $client->collections[$collection->indexName]->documents->delete(['filter_by' => 'id: ' . $this->criteria['documentId']]);
        }
    }

    // Protected Methods
    // =========================================================================

    /**
     * Returns a default description for [[getDescription()]], if [[description]] isn’t set.
     *
     * @return string The default task description
     */
    protected function defaultDescription(): string
    {
        return Craft::t('typesense', 'Delete document ' . $this->criteria['documentId'] . ' from ' . $this->criteria['section']);
    }
}
