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
use craft\db\Query;
use craft\helpers\Db;
use craftpulse\typesense\db\Table;
use craftpulse\typesense\services\SyncSuspend;

/**
 * Moves the sync-suspend switch out of project config into runtime database
 * state: creates the suspend table, carries a currently-suspended install over
 * as a global suspend row, and drops the `syncSuspended` project-config setting.
 * Suspend is operational state, so it must never live in project config.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class m260715_140000_sync_suspend_state extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        Install::createSuspendTable($this);

        $projectConfig = Craft::$app->getProjectConfig();
        $suspended = (bool)$projectConfig->get('plugins.typesense.settings.syncSuspended');

        // Carry a currently-suspended install over as a global suspend row.
        if ($suspended && $this->db->getTableSchema(Table::SYNC_SUSPEND) !== null) {
            $exists = (new Query())
                ->from(Table::SYNC_SUSPEND)
                ->where(['collection' => SyncSuspend::GLOBAL_KEY])
                ->exists();

            if (!$exists) {
                Db::insert(Table::SYNC_SUSPEND, ['collection' => SyncSuspend::GLOBAL_KEY]);
            }
        }

        $projectConfig->remove('plugins.typesense.settings.syncSuspended');

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::SYNC_SUSPEND);

        return true;
    }
}
