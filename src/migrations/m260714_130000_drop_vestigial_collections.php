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
 * Drops the vestigial typesense_collections table.
 *
 * 5.8.3's install migration created this table on every install, but it was
 * never read or written. The 5.9.0 rebuild removes it from fresh installs and
 * drops it here for existing installs.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class m260714_130000_drop_vestigial_collections extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->dropTableIfExists(Table::COLLECTIONS);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        // The table was vestigial; it is not recreated.
        return false;
    }
}
