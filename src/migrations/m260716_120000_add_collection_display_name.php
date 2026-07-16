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
 * Backfills the friendly display name onto control-panel-managed collections.
 * The managed-collection store is project config (typesense.managedCollections),
 * so this reads each entry and, when it has no `displayName`, re-saves it with
 * the display name seeded from the existing index name. Idempotent: an entry
 * that already has a display name is skipped.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.5
 */
class m260716_120000_add_collection_display_name extends Migration
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

        foreach ($collections as $uid => $config) {
            if (!is_array($config) || !empty($config['displayName'])) {
                continue;
            }

            // getByUid() backfills displayName from the index name on read, and
            // getConfig() then emits it, so the re-save persists the display name.
            $definition = $managed->getByUid((string)$uid);

            if ($definition === null) {
                continue;
            }

            $projectConfig->set(ManagedCollections::CONFIG_KEY . '.' . $uid, $definition->getConfig());
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m260716_120000_add_collection_display_name cannot be reverted.\n";

        return false;
    }
}
