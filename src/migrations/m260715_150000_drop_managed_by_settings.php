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

use Craft;
use craft\db\Migration;

/**
 * Drops the removed per-feature managedBy settings from project config. Feature
 * ownership is now presence-based (a collection that declares synonyms, curation
 * rules, or a preset in fluent config owns that domain, read-only in the control
 * panel; a silent collection is control-panel-owned), so `synonymsManagedBy`,
 * `curationManagedBy`, and `presetsManagedBy` no longer exist on the settings
 * model. Removing the stale keys stops them being replayed onto a model that no
 * longer declares those properties (which would raise an unknown-property error).
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class m260715_150000_drop_managed_by_settings extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();

        $projectConfig->remove('plugins.typesense.settings.synonymsManagedBy');
        $projectConfig->remove('plugins.typesense.settings.curationManagedBy');
        $projectConfig->remove('plugins.typesense.settings.presetsManagedBy');

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        // Ownership is presence-based; there is nothing to restore.
        return true;
    }
}
