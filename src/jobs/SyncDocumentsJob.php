<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://percipio.london
 * @copyright Copyright (c) 2022 percipiolondon
 */

namespace percipiolondon\typesense\jobs;

use Craft;
use craft\base\Batchable;
use craft\db\Query;
use craft\db\QueryBatcher;
use craft\queue\BaseBatchedJob;
use percipiolondon\typesense\helpers\CollectionHelper;
use percipiolondon\typesense\Typesense;
use percipiolondon\typesense\TypesenseCollectionIndex;
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
class SyncDocumentsJob extends BaseBatchedJob
{
    // Public Properties
    // =========================================================================

    /**
     * @var array
     */
    public array $criteria = [];

    /**
     * @var array<string, bool>
     */
    public array $staleDocumentIds = [];

    protected ?TypesenseCollectionIndex $collection = null;

    protected ?TypesenseClient $client = null;

    // Protected Methods
    // =========================================================================

    protected function loadData(): Batchable
    {
        $this->collection = CollectionHelper::getCollection($this->criteria['index']);
        $this->client = Typesense::$plugin->getClient()->client() ?: null;

        if (!$this->client || !$this->collection) {
            return new QueryBatcher((new Query())->from('{{%elements}}')->where('0=1'));
        }

        $query = clone $this->collection->criteria;

        return new QueryBatcher($query->orderBy('id ASC'));
    }

    protected function before(): void
    {
        if (!$this->client || !$this->collection) {
            return;
        }

        $collectionTypesense = Typesense::$plugin->getCollections()->getCollectionByCollectionRetrieve($this->criteria['index']);

        if ($collectionTypesense !== [] && ($this->criteria['type'] ?? null) === 'Flush') {
            $this->client->collections[$this->criteria['index']]->delete();
            $collectionTypesense = null;
        }

        if (!$collectionTypesense) {
            $this->client->collections->create($this->collection->schema);
        }

        $this->staleDocumentIds = [];

        if (($this->criteria['type'] ?? null) === 'Flush') {
            return;
        }

        foreach (CollectionHelper::convertDocumentsToArray($this->criteria['index']) as $document) {
            if (isset($document['id'])) {
                $this->staleDocumentIds[(string)$document['id']] = true;
            }
        }
    }

    protected function processItem(mixed $item): void
    {
        if (!$this->client || !$this->collection) {
            return;
        }

        $resolver = $this->collection->schema['resolver']($item);

        if (!$resolver) {
            return;
        }

        $doc = $this->client->collections[$this->criteria['index']]
            ->documents
            ->upsert($resolver);

        $id = (string)($doc['id'] ?? $resolver['id'] ?? '');

        if ($id !== '') {
            unset($this->staleDocumentIds[$id]);
        }
    }

    protected function after(): void
    {
        if (!$this->client) {
            return;
        }

        foreach (array_keys($this->staleDocumentIds) as $id) {
            $this->client->collections[$this->criteria['index']]->documents->delete(['filter_by' => 'id: ' . $id]);
        }
    }

    /**
     * Returns a default description for [[getDescription()]], if [[description]] isn’t set.
     *
     * @return string The default task description
     */
    protected function defaultDescription(): string
    {
        return Craft::t('typesense', ($this->criteria['type'] ?? 'Unknown') . ' documents for ' . $this->criteria['index']);
    }
}
