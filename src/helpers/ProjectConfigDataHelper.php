<?php

namespace percipiolondon\typesense\helpers;

use Craft;
use craft\typesense\db\Table;
use craft\typesense\Typesense;
use craft\db\Query;
use craft\helpers\Json;

class ProjectConfigDataHelper
{
    /**
     * Return a rebuilt project config array
     *
     * @return array
     */
    public static function rebuildProjectConfig(): array
    {
        $output = self::_getCollectionData();
    }

    /**
     * Return collection data config array.
     *
     * @return array
     */
    private static function _getCollectionData(): array
    {
        $data = [];
        foreach (Typesense::getInstance()->getCollection()->getAllCollections() as $collection) {
            $data[$collection->uid] = $collection->getConfig();
        }
        return $data;
    }
}
