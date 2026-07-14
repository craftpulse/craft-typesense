<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\records;

use craft\db\ActiveRecord;
use craftpulse\typesense\db\Table;

/**
 * Records that a source element's document depends on another element, so the
 * source re-indexes when the dependency changes.
 *
 * @property int $id
 * @property string $collectionHandle
 * @property int $siteId
 * @property int $sourceElementId
 * @property int $dependencyElementId
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class SyncDependencyRecord extends ActiveRecord
{
    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return Table::SYNC_DEPENDENCIES;
    }
}
