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
use craft\db\Table;
use craft\services\ProjectConfig as ProjectConfigService;
use craftpulse\typesense\controllers\AiProvidersController;
use craftpulse\typesense\controllers\AliasesController;
use craftpulse\typesense\controllers\AnalyticsController;
use craftpulse\typesense\controllers\CollectionsController;
use craftpulse\typesense\controllers\CurationController;
use craftpulse\typesense\controllers\DictionariesController;
use craftpulse\typesense\controllers\ExperimentsController;
use craftpulse\typesense\controllers\KeysController;
use craftpulse\typesense\controllers\OpsController;
use craftpulse\typesense\controllers\PlaygroundController;
use craftpulse\typesense\controllers\RelevanceController;
use craftpulse\typesense\controllers\SettingsController;
use craftpulse\typesense\controllers\SynonymsController;

/**
 * Renames every Typesense permission handle from camelCase to kebab-case
 * (`typesense:manageSettings` becomes `typesense:manage-settings`), carrying
 * existing grants over so nobody loses access.
 *
 * Craft lowercases a permission name both when it stores it and when it checks
 * it (see [[\craft\services\UserPermissions]]), so the database and project
 * config hold `typesense:managesettings` while the new code checks
 * `typesense:manage-settings`. A code-only rename would therefore stop matching
 * silently, and it would fail invisibly: an admin holds every permission
 * implicitly and would notice nothing, while every non-admin grantee would lose
 * the screen.
 *
 * For each renamed handle the migration moves the user grants
 * ([[Table::USERPERMISSIONS_USERS]]), the group grants
 * ([[Table::USERPERMISSIONS_USERGROUPS]]), and the project-config group lists
 * (`users.groups.<uid>.permissions`) onto the new name, then drops the old
 * permission row so no dead handle is left behind.
 *
 * The project config is written with events muted and the read-only flag
 * temporarily lifted (the same pairing [[\craft\services\ProjectConfig::rebuild()]]
 * uses): the grant rows are rewritten here directly, so the group-permission
 * change handler has nothing left to reconcile, and the rename must land even on
 * an install running with `allowAdminChanges` disabled.
 *
 * The migration is idempotent. Each handle is skipped unless the old permission
 * row actually exists, and a project-config list is only rewritten when it still
 * carries an old name.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.10
 */
class m260729_120000_kebab_case_permissions extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @throws \Throwable
     * @throws \yii\base\ErrorException
     * @throws \yii\base\Exception
     * @throws \yii\base\NotSupportedException
     * @throws \yii\db\Exception
     * @throws \yii\web\ServerErrorHttpException
     */
    public function safeUp(): bool
    {
        $this->_renamePermissions($this->_map());

        return true;
    }

    /**
     * @inheritdoc
     * @throws \Throwable
     * @throws \yii\base\ErrorException
     * @throws \yii\base\Exception
     * @throws \yii\base\NotSupportedException
     * @throws \yii\db\Exception
     * @throws \yii\web\ServerErrorHttpException
     */
    public function safeDown(): bool
    {
        $this->_renamePermissions(array_flip($this->_map()));

        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * The rename map, lowercased on both sides the way Craft stores permission
     * names. Keyed by the old camelCase handle, valued with the new kebab-case
     * handle taken from the controller constants that now own them.
     *
     * @return array<string, string>
     */
    private function _map(): array
    {
        $map = [
            'typesense:manageAiProviders' => AiProvidersController::PERMISSION_MANAGE_AI_PROVIDERS,
            'typesense:manageAliases' => AliasesController::PERMISSION_MANAGE_ALIASES,
            'typesense:manageCollections' => CollectionsController::PERMISSION_MANAGE_COLLECTIONS,
            'typesense:manageCuration' => CurationController::PERMISSION_MANAGE_CURATION,
            'typesense:manageDictionaries' => DictionariesController::PERMISSION_MANAGE_DICTIONARIES,
            'typesense:manageExperiments' => ExperimentsController::PERMISSION_MANAGE_EXPERIMENTS,
            'typesense:manageKeys' => KeysController::PERMISSION_MANAGE_KEYS,
            'typesense:manageOps' => OpsController::PERMISSION_MANAGE_OPS,
            'typesense:manageRelevance' => RelevanceController::PERMISSION_MANAGE_RELEVANCE,
            'typesense:manageSettings' => SettingsController::PERMISSION_MANAGE_SETTINGS,
            'typesense:manageSynonyms' => SynonymsController::PERMISSION_MANAGE_SYNONYMS,
            'typesense:viewAnalytics' => AnalyticsController::PERMISSION_VIEW_ANALYTICS,
            'typesense:viewDiagnostics' => PlaygroundController::PERMISSION_VIEW_DIAGNOSTICS,
        ];

        $lowercased = [];

        foreach ($map as $old => $new) {
            $lowercased[strtolower($old)] = strtolower($new);
        }

        return $lowercased;
    }

    /**
     * Moves every grant of each old permission name onto its new name, in the
     * grant tables and in the project-config group lists, then removes the old
     * permission row.
     *
     * @param array<string, string> $map Old permission name to new permission name, both lowercased.
     * @return void
     * @throws \Throwable
     * @throws \yii\base\ErrorException
     * @throws \yii\base\Exception
     * @throws \yii\base\NotSupportedException
     * @throws \yii\db\Exception
     * @throws \yii\web\ServerErrorHttpException
     */
    private function _renamePermissions(array $map): void
    {
        foreach ($map as $oldPermission => $newPermission) {
            $this->_renameGrants($oldPermission, $newPermission);
        }

        $this->_rewriteProjectConfig($map);
    }

    /**
     * Repoints one permission's user and group grants at the new name and drops
     * the old permission row. A no-op when the old permission is not present, so
     * the migration can run twice.
     *
     * @param string $oldPermission
     * @param string $newPermission
     * @return void
     * @throws \yii\db\Exception
     */
    private function _renameGrants(string $oldPermission, string $newPermission): void
    {
        $oldPermissionId = (new Query())
            ->select(['id'])
            ->from([Table::USERPERMISSIONS])
            ->where(['name' => $oldPermission])
            ->scalar($this->db);

        if ($oldPermissionId === false || $oldPermissionId === null) {
            return;
        }

        $userIds = array_unique((new Query())
            ->select(['userId'])
            ->from([Table::USERPERMISSIONS_USERS])
            ->where(['permissionId' => $oldPermissionId])
            ->column($this->db));

        $groupIds = array_unique((new Query())
            ->select(['groupId'])
            ->from([Table::USERPERMISSIONS_USERGROUPS])
            ->where(['permissionId' => $oldPermissionId])
            ->column($this->db));

        // Drop the old row first (cascading its grants away), then any row that
        // already carries the new name, so the insert below cannot collide with
        // a half-applied run.
        $this->delete(Table::USERPERMISSIONS, ['id' => $oldPermissionId]);
        $this->delete(Table::USERPERMISSIONS, ['name' => $newPermission]);

        $this->insert(Table::USERPERMISSIONS, ['name' => $newPermission]);
        $newPermissionId = $this->db->getLastInsertID(Table::USERPERMISSIONS);

        if (!empty($userIds)) {
            $this->batchInsert(
                Table::USERPERMISSIONS_USERS,
                ['permissionId', 'userId'],
                array_map(static fn(int|string $userId): array => [$newPermissionId, $userId], $userIds),
            );
        }

        if (!empty($groupIds)) {
            $this->batchInsert(
                Table::USERPERMISSIONS_USERGROUPS,
                ['permissionId', 'groupId'],
                array_map(static fn(int|string $groupId): array => [$newPermissionId, $groupId], $groupIds),
            );
        }
    }

    /**
     * Swaps the old permission names for the new ones in every user group's
     * project-config permission list, keeping Craft's stored shape (lowercased,
     * sorted ascending, sequentially keyed).
     *
     * @param array<string, string> $map Old permission name to new permission name, both lowercased.
     * @return void
     * @throws \Throwable
     * @throws \yii\base\ErrorException
     * @throws \yii\base\Exception
     * @throws \yii\base\NotSupportedException
     * @throws \yii\web\ServerErrorHttpException
     */
    private function _rewriteProjectConfig(array $map): void
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $groups = $projectConfig->get(ProjectConfigService::PATH_USER_GROUPS) ?? [];

        if (!is_array($groups)) {
            return;
        }

        $muteEvents = $projectConfig->muteEvents;
        $readOnly = $projectConfig->readOnly;
        $projectConfig->muteEvents = true;
        $projectConfig->readOnly = false;

        try {
            foreach ($groups as $uid => $group) {
                if (!is_array($group)) {
                    continue;
                }

                $permissions = $group['permissions'] ?? [];

                if (!is_array($permissions) || $permissions === []) {
                    continue;
                }

                $renamed = array_map(
                    static fn(mixed $permission): mixed => is_string($permission)
                        ? ($map[strtolower($permission)] ?? $permission)
                        : $permission,
                    $permissions,
                );

                if ($renamed === $permissions) {
                    continue;
                }

                $renamed = array_values(array_unique($renamed));
                sort($renamed);

                $projectConfig->set(
                    sprintf('%s.%s.permissions', ProjectConfigService::PATH_USER_GROUPS, $uid),
                    $renamed,
                    'Rename Typesense permission handles to kebab-case',
                );
            }
        } finally {
            $projectConfig->muteEvents = $muteEvents;
            $projectConfig->readOnly = $readOnly;
        }
    }
}
