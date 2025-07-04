<?php

namespace percipiolondon\typesense\helpers;

use craft\base\Element;
use craft\helpers\Json;
use percipiolondon\typesense\Typesense;
use percipiolondon\typesense\TypesenseCollectionIndex;

/**
 * Class CollectionHelper
 *
 * @package percipiolondon\typesense\helpers
 */
class CollectionHelper
{
    public static function getCollection(string $name): ?TypesenseCollectionIndex
    {
        $indexes = Typesense::$plugin->getSettings()->collections;

        foreach ($indexes as $index) {
            if ($index->indexName === $name) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Find all collections that an element is included in by executing the collection element query with the element ID
     */
    public static function getAllCollectionsElementIsIndexedIn(Element $element)
    {
        $matchingCollections = [];
        $indexes = Typesense::$plugin->getSettings()->collections;

        foreach ($indexes as $index) {
            if ($index->criteria->id($element->id)->count() > 0) {
                $matchingCollections[] = $index;
            }
        }
        return $matchingCollections;
    }

    public static function convertDocumentsToArray(string $index): array
    {
        $documents = Typesense::$plugin->getClient()->client()->collections[$index]->documents->export();
        $jsonDocs = explode("\n", $documents);
        $documents = [];

        foreach ($jsonDocs as $document) {
            $documents[] = Json::decode($document);
        }

        return $documents;
    }
}
