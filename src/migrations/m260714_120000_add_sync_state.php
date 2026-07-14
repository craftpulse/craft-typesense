<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\migrations;

use craft\db\Migration;
use craftpulse\typesense\db\Table;

/**
 * Adds the sync-state and sync-dependency tables for the redone sync engine.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class m260714_120000_add_sync_state extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        Install::createSyncTables($this);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::SYNC_DEPENDENCIES);
        $this->dropTableIfExists(Table::SYNC_STATE);

        return true;
    }
}
