<?php
/**
 * @author Percipio Global Ltd. <support@percipio.london>
 * @since 1.0.0
 */

namespace percipiolondon\typesense\services;

use craft\base\MemoizableArray;
use craft\helpers\ArrayHelper;

use yii\base\Component;
use yii\base\Exception;

class CollectionsService extends Component
{
    /**
     * @event CollectionEvent The event that is triggered before a collection is saved.
     */
    const EVENT_BEFORE_SAVE_COLLECTION = 'beforeSaveCollection';

    /**
     * @event SectionEvent The event that is triggered after a collection is deleted.
     */
    const EVENT_AFTER_DELETE_COLLECTION = 'afterDeleteCollection';

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
     * @return MemoizableArray<Section>
     */
    private function _collections(): MemoizableArray
    {
        if ($this->_collections === null) {
            $collections = [];


        }

        return $this->_collections;
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
}
