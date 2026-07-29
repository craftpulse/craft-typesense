<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Covers the utility controller access gate: its operational actions (sync,
 * flush, suspend) require the typesense:manage-ops permission, not
 * typesense:manage-settings. A user who holds only manage-settings is still
 * forbidden, which pins the Fix-5 move of the gate off the settings handle.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\User;
use craft\enums\CmsEdition;
use craft\helpers\UrlHelper;
use craftpulse\typesense\controllers\SettingsController;

function aUtilityUser(): User
{
    $suffix = bin2hex(random_bytes(4));
    $user = new User();
    $user->username = "ts_util_{$suffix}";
    $user->email = "ts_util_{$suffix}@example.test";
    Craft::$app->getElements()->saveElement($user);

    return $user;
}

it('forbids the sync action for a user without manage-ops', function() {
    Craft::$app->edition = CmsEdition::Pro;

    $this->actingAs(aUtilityUser())
        ->withExceptionHandling()
        ->post(UrlHelper::actionUrl('typesense/utility/sync'))
        ->assertForbidden();
});

it('forbids the sync action for a user who holds only manage-settings (gate moved to manage-ops)', function() {
    Craft::$app->edition = CmsEdition::Pro;
    $user = aUtilityUser();
    Craft::$app->getUserPermissions()->saveUserPermissions(
        (int)$user->id,
        [SettingsController::PERMISSION_MANAGE_SETTINGS],
    );

    $this->actingAs($user)
        ->withExceptionHandling()
        ->post(UrlHelper::actionUrl('typesense/utility/sync'))
        ->assertForbidden();
});

it('queues a global sync and redirects', function() {
    Craft::$app->edition = CmsEdition::Pro;

    $this->actingAsAdmin()
        ->withExceptionHandling()
        ->post(UrlHelper::actionUrl('typesense/utility/sync'))
        ->assertRedirect();
});

it('requires a collection for the apply-schema action', function() {
    Craft::$app->edition = CmsEdition::Pro;

    $this->actingAsAdmin()
        ->withExceptionHandling()
        ->post(UrlHelper::actionUrl('typesense/utility/apply-schema'))
        ->assertStatus(400);
});

it('404s the apply-schema action for an unknown collection', function() {
    Craft::$app->edition = CmsEdition::Pro;

    $this->actingAsAdmin()
        ->withExceptionHandling()
        ->post(UrlHelper::actionUrl('typesense/utility/apply-schema'), ['collection' => 'does-not-exist'])
        ->assertNotFound();
});
