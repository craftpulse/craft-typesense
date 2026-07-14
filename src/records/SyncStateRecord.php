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
 * Per collection and site sync state: continuation cursor, content checksum,
 * indexed document count, and last sync time.
 *
 * @property int $id
 * @property string $collectionHandle
 * @property int $siteId
 * @property int|null $cursor
 * @property string|null $checksum
 * @property int $documentCount
 * @property string|null $lastSyncedAt
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class SyncStateRecord extends ActiveRecord
{
    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return Table::SYNC_STATE;
    }
}
