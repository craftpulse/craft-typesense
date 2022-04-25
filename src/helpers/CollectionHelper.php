<?php

namespace percipiolondon\typesense\helpers;

use Craft;

use craft\web\Request;
use craft\helpers\DateTimeHelper;
use craft\helpers\StringHelper;

use percipiolondon\typesense\models\CollectionModel as Collection;
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
    public static function collectionToSync(Request $request = null): Collection
    {

        if ($request === null) {
            $request = Craft::$app->getRequest();
        }

        $collectionId = $request->getBodyParam('collectionId');

        if ($collectionId) {
            $collection = Typesense::getCollections()->getCollectionById($collectionId);

            if (!$collection) {
                throw new NotFoundHttpException(Craft::t('typesense', 'No collection with the ID “{id}”', ['id' => $collectionId]));
            }
        } else {
            $collection = new Collection();
            $collection->dateCreated = DateTimeHelper::toDateTime(DateTimeHelper::currentTimeStamp());
            $collection->handle = $request->getBodyParam('handle');
            $collection->sectionId = $request->getBodyParam('sectionId');
            $collection->uid = StringHelper::UUID();
        }

        return $collection;
    }
}


