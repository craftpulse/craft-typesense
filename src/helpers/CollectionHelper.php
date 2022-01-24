<?php

namespace percipiolondon\typesense\helpers;

use Craft;

use craft\web\Request;

use percipiolondon\typesense\models\CollectionsModel as Collection;
use percipiolondon\typesense\Typesense;

class CollectionHelper
{
    /**
     * Instantiates the collection specified by the post data.
     *
     * @param Request|null $request
     * @return CollectionsModel
     * @throws NotFoundHttpException
     * @since 1.0.0
     */
    public static function collectionToSync(Request $request = null): CollectionsModel
    {

        if ($request === null) {
            $request = Craft::$app->getRequest();
        }

        $collectionId = $request->getBodyParam('collectionId');

        Craft::dd($request->getBodyParam('handle'));

        if ($collectionId) {
            $collection = Typesense::getCollections()->getCollectionById($collectionId);

            if (!$collection) {
               throw new NotFoundHttpException(Craft::t('typesense', 'No collection with the ID “{id}”', ['id' => $collectionId]));
            }
        } else {
            $collection = new Collection();
            $collection->handle = $request->getBodyParam('handle');
            $collection->sectionId = $request->getBodyParam('sectionId');
        }

        // 'id' => $this->primaryKey(),
        // 'sectionId' => $this->integer()->notNull(),
        // 'dateCreated' => $this->dateTime()->notNull(),
        // 'dateSynced' => $this->dateTime(),
        // 'handle' => $this->string()->notNull(),
        // 'uid' => $this->uid()->notNull(),

        return $collection;
    }
}


