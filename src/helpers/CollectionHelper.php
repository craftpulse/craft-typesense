<?php

namespace percipiolondon\typesense\helpers;

use Craft;

use craft\web\Request;
use craft\helpers\DateTimeHelper;
use craft\helpers\StringHelper;

use percipiolondon\typesense\models\CollectionModel as Collection;
use percipiolondon\typesense\Typesense;
use percipiolondon\typesense\TypesenseCollectionIndex;
use Typesense\Client as TypesenseClient;

/**
 * Class CollectionHelper
 *
 * @package percipiolondon\typesense\helpers
 */
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
//    public static function collectionToSync(Request $request = null): Collection
//    {
//
//        if ($request === null) {
//            $request = Craft::$app->getRequest();
//        }
//
//        $collectionId = $request->getBodyParam('collectionId');
//
//        if ($collectionId) {
//            $collection = Typesense::getCollections()->getCollectionById($collectionId);
//
//            if (!$collection) {
//                throw new NotFoundHttpException(Craft::t('typesense', 'No collection with the ID “{id}”', ['id' => $collectionId]));
//            }
//        } else {
//            $collection = new Collection();
//            $collection->dateCreated = DateTimeHelper::toDateTime(DateTimeHelper::currentTimeStamp());
//            $collection->handle = $request->getBodyParam('handle');
//            $collection->sectionId = $request->getBodyParam('sectionId');
//            $collection->uid = StringHelper::UUID();
//        }
//
//        return $collection;
//    }

    /**
     * @param string $name
     * @return TypesenseCollectionIndex|null
     */
    public static function getCollection(string $name): ?TypesenseCollectionIndex
    {
        $indexes = Typesense::$plugin->getSettings()->collections;

        foreach( $indexes as $index) {
            if ($index->indexName === $name) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param string $documents
     * @return array
     */
    public static function convertDocumentsToArray(string $index): array
    {
        $documents = Craft::$container->get(TypesenseClient::class)->collections[$index]->documents->export();
        $jsonDocs = explode("\n",$documents);
        $documents = [];

        foreach($jsonDocs as $document) {
            $documents[] = json_decode($document);
        }

        return $documents;
    }
}


