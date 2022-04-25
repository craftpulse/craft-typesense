<?php
/**
 * @author Percipio Global Ltd. <support@percipio.london>
 * @since 1.0.0
 */

namespace percipiolondon\typesense\services;

use Craft;

use craft\base\MemoizableArray;
use craft\helpers\ArrayHelper;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\StringHelper;

use percipiolondon\typesense\db\Table;
use percipiolondon\typesense\events\CollectionEvent;
use percipiolondon\typesense\models\CollectionModel as Collection;

use yii\base\Component;
use yii\base\Exception;

class CollectionService extends Component
{
    /**
     * @event CollectionEvent The event that is triggered before a collection is saved.
     */
    const EVENT_BEFORE_SAVE_COLLECTION = 'beforeSaveCollection';

    /**
     * @event SectionEvent The event that is triggered after a collection is deleted.
     */
    const EVENT_AFTER_DELETE_COLLECTION = 'afterDeleteCollection';

    const CONFIG_COLLECTIONS_KEY = 'collections';

    /**
     * @var bool
     */
    private $_fetchedAllCollections = false;

    /**
     * @var Collection[]
     */
    private $_collectionsById;

    /**
     * @var MemoizableArray<Section>|null
     * @see _collections()
     */
    private $_collections;

    // Sections
    // -------------------------------------------------------------------------

    /**
     * Returns a memoizable array of all collections.
     *
     * @return MemoizableArray<Collection>
     */
    private function _collections(): MemoizableArray
    {
        if ($this->_collections === null) {
            $collections = [];
        }

        return $this->_collections;
    }

    /**
     * Returns all collections.
     *
     * ---
     *
     *
     * @return Collection[] An array of all collections.
     */
    public function getAllCollections(): array
    {
        if (!$this->_fetchedAllCollections) {
            $results = $this->_createCollectionQuery()->all();

            foreach ($results as $result) {
                $this->_memoizeCollection(new Collection($result));
            }

            $this->fetchedAllCollections = true;
        }

        return $this->_collectionsById ?: [];
    }

    /**
     * Returns all of the collection IDs.
     *
     * ---
     *
     * ```php
     * $collectionIds = Typesense::$app->sections->allCollectionIds;
     * ```
     *
     * @return int[] All the sections’ IDs.
     */
    public function getAllCollectionIds(): array
    {
        return ArrayHelper::getColumn($this->getAllCollections(), 'id', false);
    }

    /**
     * Returns all collections of a given type.
     *
     * ---
     *
     * ```php
     * use craft\models\Collection;
     *
     * $singles = Typesense::$app->collections->getCollectionsByType(Collection::TYPE_SINGLE);
     * ```
     *
     * @param string $type The section type (`single`, `channel`, or `structure`)
     * @return Collection[] All the collections of the given type.
     */
    public function getCollectionsByType(string $type): array
    {
        return $this->_collections()->where('type', $type, true)->all();
    }

    /**
     * Returns a collection by its ID.
     *
     * ---
     *
     * ```php
     * $section = Typesense::$app->collections->getCollectionById(1);
     * ```
     *
     * @param int $collectionId
     * @return Collection|null
     */
    public function getCollectionById(int $collectionId)
    {
        return $this->_collections()->firstWhere('id', $collectionId);
    }

    /**
     * Saves a collection.
     *
     * ---
     *
     * ```php
     * use craft\models\Collection;
     *
     * $success = Typesense::CollectionService->saveCollection($collection);
     * ```
     *
     * @param Collection $collection The section to be saved
     * @return bool
     * @throws CollectionNotFoundException if $collection->id is invalid
     * @throws \Throwable if reasons
     */

    public function saveCollection(Collection $collection): bool
    {
        $isNewCollection = !$collection->id;

        // Fire a 'beforeSaveCollection' event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_SAVE_COLLECTION)) {
            $this->trigger(self::EVENT_BEFORE_SAVE_COLLECTION, new CollectionEvent([
                'collection' => $collection,
                'isNew' => $isNewCollection,
            ]));
        }

        if ($isNewCollection) {
            $collection->uid = StringHelper::UUID();
        } else if (!$collection->uid) {
            $collection->uid = Db::uidById(Table::TYPESENSE, $collection->id);
        }

        // Assemble the section config
        // -----------------------------------------------------------------

        // Do everything that follows in a transaction so no DB changes will be
        // saved if an exception occurs that ends up preventing the project config
        // changes from getting saved
        $transaction = Craft::$app->getDb()->beginTransaction();

        try {
            // Save the collection config
            // -----------------------------------------------------------------

            $configPath = self::CONFIG_COLLECTIONS_KEY . '.' . $collection->uid;
            $configData = $collection->getConfig();
            Craft::$app->getProjectConfig()->set($configPath, $configData, "Save collection ”{$collection->handle}”");

            if ($isNewCollection) {
                $collection->id = Db::idByUid(Table::TYPESENSE, $collection->uid);
            }

            $transaction->commit();
        } catch (\Throwable $e) {
            $transaction->rollBack();
        }

        return true;
    }

    /**
     * Returns a Query object prepped for retrieving collections.
     *
     * @return Query The query object.
     */
    private function _createCollectionQuery(): Query
    {
        return (new Query())
            ->select([
                'collections.id',
                'collections.handle',
                'collections.sectionId',
                'collections.dateCreated',
                'collections.dateSynced',
                'collections.uid',
            ])
            ->from([Table::COLLECTIONS . ' collections']);
    }
}
