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

use percipiolondon\typesense\controllers\DocumentsController;
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

    public function execute($queue): void
    {
        $upsertIds = [];
        $collection = CollectionHelper::getCollection($this->criteria['index']);
        $collectionTypesense = Typesense::$plugin->getCollections()->getCollectionByCollectionRetrieve($this->criteria['index']);
        $client = Typesense::$plugin->getClient()->client();
        $documentsController = new DocumentsController('documents-controller', Craft::$app);

        if ($client !== false && !is_null($collection)) {

            // delete collections if the action is flush
            if ($collectionTypesense !== [] && $this->criteria['type'] === 'Flush') {
                Typesense::$plugin->getClient()->client()?->collections[$this->criteria['index']]->delete();
                $collectionTypesense = null;
            }

            //create a new schema if a collection has been flushed
            if (!$collectionTypesense) {
                $collectionTypesense = $client->collections->create($collection->schema);
            }

            if ($collectionTypesense !== []) {
                $entries = $collection->criteria->all();
                $totalEntries = count($entries);

                //fetch each document of entry to update
                foreach ($entries as $i => $entry) {

                    $resolver = $collection->schema['resolver']($entry);

                    if ($resolver) {

                        // Trigger the before upsert event
                        $documentsController->triggerBeforeUpsert($this->criteria['index'], $resolver['id']);

                        // upsert
                        $doc = $client->collections[$this->criteria['index']]
                            ->documents
                            ->upsert($resolver);

                        // Trigger the after upsert event
                        $documentsController->triggerAfterUpsert($this->criteria['index'], $resolver['id']);

                        // add to array to show queue progress
                        $upsertIds[] = $doc['id'];
                    }

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
                    if (isset($document['id']) && !in_array($document['id'], $upsertIds)) {
                        $client->collections[$this->criteria['index']]->documents->delete(['filter_by' => 'id: ' . $document['id']]);
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
