<?php

namespace percipiolondon\typesense\helpers;

use percipiolondon\typesense\Typesense;

class ProjectConfigDataHelper
{
    /**
     * Return a rebuilt project config array
     *
     * @return array
     */
    public static function rebuildProjectConfig(): array
    {
        return self::_getCollectionData();
    }

    /**
     * Return collection data config array.
     *
     * @return array
     */
    private static function _getCollectionData(): array
    {
        $data = [];
        foreach (Typesense::$plugin->getSettings()->collections as $collection) {
            $data[$collection->uid] = $collection->getConfig();
        }
        return $data;
    }
}
