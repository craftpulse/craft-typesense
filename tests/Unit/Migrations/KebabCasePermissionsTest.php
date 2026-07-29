<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Covers the camelCase-to-kebab-case permission rename migration. Craft
 * lowercases a permission name both when it stores it and when it checks it, so a
 * code-only rename would silently stop matching every existing grant (and fail
 * invisibly, since admins hold everything implicitly). These tests fixture a
 * pre-rename install (the lowercased camelCase name granted to a user, to a
 * group, and listed in a group's project config), run the migration, and assert
 * the grants land on the kebab name, the old row is gone, Craft-owned and
 * third-party handles are untouched, the migration is idempotent, and it reverses
 * cleanly on the way down.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\User;
use craft\helpers\StringHelper;
use craft\records\UserGroup as UserGroupRecord;
use craftpulse\typesense\controllers\CollectionsController;
use craftpulse\typesense\controllers\KeysController;
use craftpulse\typesense\controllers\OpsController;
use craftpulse\typesense\controllers\SettingsController;
use craftpulse\typesense\migrations\m260729_120000_kebab_case_permissions;

/**
 * Grants a permission by its raw stored name, bypassing the UserPermissions
 * service so a pre-rename (camelCase, lowercased) name can be fixtured. The
 * service filters orphaned handles, which is exactly what makes it unusable here.
 *
 * @param string $name
 * @param int|null $userId
 * @param int|null $groupId
 * @return void
 */
function tsGrantRawPermission(string $name, ?int $userId = null, ?int $groupId = null): void
{
    $db = Craft::$app->getDb();
    $db->createCommand()->insert(CraftTable::USERPERMISSIONS, ['name' => $name])->execute();
    $permissionId = (int)$db->getLastInsertID(CraftTable::USERPERMISSIONS);

    if ($userId !== null) {
        $db->createCommand()
            ->insert(CraftTable::USERPERMISSIONS_USERS, ['permissionId' => $permissionId, 'userId' => $userId])
            ->execute();
    }

    if ($groupId !== null) {
        $db->createCommand()
            ->insert(CraftTable::USERPERMISSIONS_USERGROUPS, ['permissionId' => $permissionId, 'groupId' => $groupId])
            ->execute();
    }
}

/**
 * Returns the permission names granted directly to a user.
 *
 * @param int $userId
 * @return string[]
 */
function tsUserPermissionNames(int $userId): array
{
    return (new Query())
        ->select(['p.name'])
        ->from(['p' => CraftTable::USERPERMISSIONS])
        ->innerJoin(['pu' => CraftTable::USERPERMISSIONS_USERS], '[[pu.permissionId]] = [[p.id]]')
        ->where(['pu.userId' => $userId])
        ->column();
}

/**
 * Returns the permission names granted to a user group.
 *
 * @param int $groupId
 * @return string[]
 */
function tsGroupPermissionNames(int $groupId): array
{
    return (new Query())
        ->select(['p.name'])
        ->from(['p' => CraftTable::USERPERMISSIONS])
        ->innerJoin(['pg' => CraftTable::USERPERMISSIONS_USERGROUPS], '[[pg.permissionId]] = [[p.id]]')
        ->where(['pg.groupId' => $groupId])
        ->column();
}

/**
 * Returns every Typesense permission name currently stored.
 *
 * @return string[]
 */
function tsStoredPermissionNames(): array
{
    return (new Query())
        ->select(['name'])
        ->from([CraftTable::USERPERMISSIONS])
        ->where(['like', 'name', 'typesense:'])
        ->column();
}

/**
 * Creates a bare user for a grant fixture.
 *
 * @return User
 * @throws Throwable
 * @throws \craft\errors\ElementNotFoundException
 * @throws \yii\base\Exception
 */
function tsPermissionUser(): User
{
    $suffix = bin2hex(random_bytes(4));

    $user = new User();
    $user->username = "ts_perm_{$suffix}";
    $user->email = "ts_perm_{$suffix}@example.test";
    Craft::$app->getElements()->saveElement($user);

    return $user;
}

/**
 * Creates a throwaway user group record. Written straight to the record so the
 * fixture needs no particular Craft edition, and so the group's permission list
 * stays under this test's control.
 *
 * @return UserGroupRecord
 */
function tsPermissionGroup(): UserGroupRecord
{
    $handle = 'tsPerm' . bin2hex(random_bytes(4));

    $group = new UserGroupRecord();
    $group->name = $handle;
    $group->handle = $handle;
    $group->uid = StringHelper::UUID();
    $group->save(false);

    return $group;
}

it('moves a user grant from the camelCase name to the kebab name', function() {
    $user = tsPermissionUser();
    tsGrantRawPermission('typesense:managesettings', userId: (int)$user->id);

    expect(tsUserPermissionNames((int)$user->id))->toContain('typesense:managesettings');

    (new m260729_120000_kebab_case_permissions())->safeUp();

    expect(tsUserPermissionNames((int)$user->id))
        ->toContain(SettingsController::PERMISSION_MANAGE_SETTINGS)
        ->not->toContain('typesense:managesettings');
});

it('moves a group grant onto the kebab name and drops the old row', function() {
    $group = tsPermissionGroup();
    tsGrantRawPermission('typesense:manageops', groupId: (int)$group->id);

    (new m260729_120000_kebab_case_permissions())->safeUp();

    expect(tsGroupPermissionNames((int)$group->id))
        ->toContain(OpsController::PERMISSION_MANAGE_OPS)
        ->not->toContain('typesense:manageops')
        ->and(tsStoredPermissionNames())->not->toContain('typesense:manageops');
});

it('rewrites a group project-config permission list, leaving its other handles alone', function() {
    $projectConfig = Craft::$app->getProjectConfig();
    $path = sprintf('users.groups.%s.permissions', StringHelper::UUID());
    $projectConfig->set($path, ['accesscp', 'typesense:managekeys']);

    try {
        (new m260729_120000_kebab_case_permissions())->safeUp();

        expect($projectConfig->get($path))
            ->toContain(KeysController::PERMISSION_MANAGE_KEYS)
            ->toContain('accesscp')
            ->not->toContain('typesense:managekeys');
    } finally {
        $projectConfig->remove($path);
    }
});

it('is idempotent: running twice does not duplicate or drop grants', function() {
    $user = tsPermissionUser();
    $group = tsPermissionGroup();
    tsGrantRawPermission('typesense:managecollections', userId: (int)$user->id, groupId: (int)$group->id);

    $migration = new m260729_120000_kebab_case_permissions();
    $migration->safeUp();
    $migration->safeUp();

    expect(tsUserPermissionNames((int)$user->id))->toBe([CollectionsController::PERMISSION_MANAGE_COLLECTIONS])
        ->and(tsGroupPermissionNames((int)$group->id))->toBe([CollectionsController::PERMISSION_MANAGE_COLLECTIONS]);
});

it('leaves an install with no camelCase Typesense grants untouched', function() {
    $before = tsStoredPermissionNames();
    sort($before);

    (new m260729_120000_kebab_case_permissions())->safeUp();

    $after = tsStoredPermissionNames();
    sort($after);

    expect($after)->toBe($before);
});

it('reverses the rename on the way down', function() {
    $user = tsPermissionUser();
    tsGrantRawPermission('typesense:viewdiagnostics', userId: (int)$user->id);

    $migration = new m260729_120000_kebab_case_permissions();
    $migration->safeUp();

    expect(tsUserPermissionNames((int)$user->id))->toContain('typesense:view-diagnostics');

    $migration->safeDown();

    expect(tsUserPermissionNames((int)$user->id))
        ->toContain('typesense:viewdiagnostics')
        ->not->toContain('typesense:view-diagnostics');
});

it('does not touch permissions owned by Craft or other plugins', function() {
    $user = tsPermissionUser();
    tsGrantRawPermission('accesscp', userId: (int)$user->id);
    tsGrantRawPermission('utility:queue-manager', userId: (int)$user->id);

    (new m260729_120000_kebab_case_permissions())->safeUp();

    expect(tsUserPermissionNames((int)$user->id))
        ->toContain('accesscp')
        ->toContain('utility:queue-manager');
});
