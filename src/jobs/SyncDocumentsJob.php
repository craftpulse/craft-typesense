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

use percipiolondon\typesense\helpers\CollectionHelper;
use percipiolondon\typesense\Typesense;

use Craft;
use craft\queue\BaseJob;
use Typesense\Client as TypesenseClient;

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
        $isNew = isset($this->criteria['isNew']) ? $this->criteria['isNew'] : false;
        $collection = CollectionHelper::getCollection($this->criteria['index']);

        //create a new schema if a collection has been flushed
        if(!$collection && $isNew) {
            Craft::$container->get(TypesenseClient::class)->collections->create($collection->schema);
        }

        if($collection) {

            $entries = $collection->criteria->all();
            $totalEntries = count($entries);

            //fetch each document of entry to update
            foreach ($entries as $i => $entry) {
                Craft::$container->get(TypesenseClient::class)
                    ->collections[$this->criteria['index']]
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
            foreach($documents as $document) {
                if( !in_array($document['id'], $upsertIds) ) {
                    Craft::$container->get(TypesenseClient::class)->collections[$this->criteria['index']]->documents->delete(['filter_by' => 'id: '.$document['id']]);
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
        $isNew = isset($this->criteria['isNew']) ? $this->criteria['isNew'] : false;
        return Craft::t('typesense', ($isNew ? 'Flushing' : 'Syncing') . ' documents for '. $this->criteria['index']);
    }
}
