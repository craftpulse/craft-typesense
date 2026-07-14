<?php

namespace craftpulse\typesense\helpers;

use craftpulse\typesense\Typesense;

class ProjectConfigDataHelper
{
    /**
     * Return a rebuilt project config array
     */
    public static function rebuildProjectConfig(): array
    {
        return self::_getCollectionData();
    }

    /**
     * Return collection data config array.
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
