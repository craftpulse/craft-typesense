<?php

namespace percipiolondon\typesense\helpers;

use Craft;

use craft\helpers\DateTimeHelper;
use craft\helpers\Json;

use percipiolondon\typesense\models\CollectionModel as Collection;
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

    public static function getCollectionBySection(string $name): ?TypesenseCollectionIndex
    {
        $indexes = Typesense::$plugin->getSettings()->collections;

        foreach ($indexes as $index) {
            if ($index->section === $name || (is_array($index->section) && in_array($name, $index->section))) {
                return $index;
            }
        }

        return null;
    }

    public static function convertDocumentsToArray(string $index): array
    {
        $documents = Typesense::$plugin->getClient()->client()->collections[$index]->documents->export();
        $jsonDocs = explode("\n",$documents);
        $documents = [];

        foreach ($jsonDocs as $document) {
            $documents[] = Json::decode($document);
        }

        return $documents;
    }
}
