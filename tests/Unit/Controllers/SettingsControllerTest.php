<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Covers the settings controller access path: the typesense:manage-settings
 * permission gate (admins in, unpermitted users out) and the write-axis
 * fail-closed behaviour when allowAdminChanges is disabled.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\User;
use craft\enums\CmsEdition;
use craft\helpers\UrlHelper;

beforeEach(function() {
    // Allow creating the fixture user the permission test needs.
    Craft::$app->edition = CmsEdition::Pro;
});

it('renders the settings screen for an admin', function() {
    $this->actingAsAdmin()
        ->get('/admin/typesense/settings')
        ->assertOk()
        ->assertSee('Server status');
});

it('forbids a user without the manage-settings permission', function() {
    $suffix = bin2hex(random_bytes(4));
    $user = new User();
    $user->username = "ts_noperm_{$suffix}";
    $user->email = "ts_noperm_{$suffix}@example.test";
    Craft::$app->getElements()->saveElement($user);

    $this->actingAs($user)
        ->withExceptionHandling()
        ->get('/admin/typesense/settings')
        ->assertForbidden();
});

it('fails closed on save when allowAdminChanges is disabled', function() {
    $general = Craft::$app->getConfig()->getGeneral();
    $original = $general->allowAdminChanges;
    $general->allowAdminChanges = false;

    try {
        $this->actingAsAdmin()
            ->withExceptionHandling()
            ->post(UrlHelper::actionUrl('typesense/settings/save'), [
                'settings' => ['apiKey' => 'tampered-key'],
            ])
            ->assertForbidden();
    } finally {
        $general->allowAdminChanges = $original;
    }
});
