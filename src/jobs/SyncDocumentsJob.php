<?php
/**
 * Typesense plugin for Craft CMS 3.x
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
class SyncDocumentsJob extends BaseJob
{
    // Public Properties
    // =========================================================================

    /**
     * @var array
     */
    public array $criteria = [];

    // Public Methods
    // =========================================================================

    public function execute($queue)
    {
        $upsertIds = [];
        $collection = CollectionHelper::getCollection($this->criteria['index']);
        $collectionTypesense = Typesense::$plugin->getCollections()->getCollectionByCollectionRetrieve($this->criteria['index']);
        $client = Typesense::$plugin->getClient()->client();

        if($client !== false && !is_null($collection)) {

            //create a new schema if a collection has been flushed
            if (!$collectionTypesense) {
                $collectionTypesense = $client->collections->create($collection->schema);
            }

            if ($collectionTypesense) {
                $entries = $collection->criteria->all();
                $totalEntries = count($entries);

                //fetch each document of entry to update
                foreach ($entries as $i => $entry) {
                    $client->collections[$this->criteria['index']]
                        ->documents
                        ->upsert($collection->schema['resolver']($entry));

                    $upsertIds[] = $entry->id;

                    $this->setProgress(
                        $queue,
                        $i / $totalEntries,
                        \Craft::t('app', '{step, number} of {total, number}', [
                            'step' => $i + 1,
                            'total' => $totalEntries,
                        ])
                    );
                }


                // convert documents into an array
                $documents = CollectionHelper::convertDocumentsToArray($this->criteria['index']);

                // delete documents that aren't existing anymore
                foreach ($documents as $document) {
                    if (isset($document['id'])) {
                        if (!in_array($document['id'], $upsertIds)) {
                            $client->collections[$this->criteria['index']]->documents->delete(['filter_by' => 'id: ' . $document['id']]);
                        }
                    }
                }
            }
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
        return Craft::t('typesense', ($this->criteria['type'] ?? 'Unkown') . ' documents for ' . $this->criteria['index']);
    }
}
