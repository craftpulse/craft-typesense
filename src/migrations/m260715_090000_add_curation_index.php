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
 * Adds the curation lookup table (element-to-rule), so the entry sidebar never
 * scans every override to find an element's pins.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class m260715_090000_add_curation_index extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        Install::createCurationIndexTable($this);

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::CURATION_INDEX);

        return true;
    }
}
