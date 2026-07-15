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
use craftpulse\typesense\services\ManagedCollections;
use craftpulse\typesense\Typesense;

/**
 * Converts each control-panel-managed collection's legacy `mappings` array
 * (keyed by field UID) into a real field layout embedded in the collection's
 * project-config, and drops the old array. Idempotent: a collection that
 * already carries a `fieldLayout` is left alone.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class m260715_120000_mappings_to_field_layout extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $collections = $projectConfig->get(ManagedCollections::CONFIG_KEY);

        if (!is_array($collections)) {
            return true;
        }

        $managed = Typesense::$plugin->getManagedCollections();
        $sources = Typesense::$plugin->getMappingSources();

        foreach ($collections as $uid => $config) {
            if (!is_array($config)) {
                continue;
            }

            // Already migrated, or nothing to migrate.
            if (!empty($config['fieldLayout']) || empty($config['mappings'])) {
                continue;
            }

            $definition = $managed->getByUid((string)$uid);

            if ($definition === null) {
                continue;
            }

            $definition->setFieldLayout($sources->layoutFromLegacyMappings($definition));
            $definition->mappings = [];

            // getConfig() no longer emits `mappings`, so the write both adds the
            // field layout and drops the legacy array in one set.
            $projectConfig->set(ManagedCollections::CONFIG_KEY . '.' . $uid, $definition->getConfig());
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        // The legacy mappings array cannot be reconstructed from the layout with
        // full fidelity, so this migration is not reversible.
        echo "m260715_120000_mappings_to_field_layout cannot be reverted.\n";

        return false;
    }
}
