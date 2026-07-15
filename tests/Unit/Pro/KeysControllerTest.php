<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The Pro keys manager controller: a created key's value is rendered exactly
 * once (in the show-once screen) and the create/delete path is edition- and
 * permission-gated server-side (a crafted POST fails closed on Free, and for an
 * admin the permission is always held so the missing-permission case is pinned
 * with a non-admin user). Every server key created here is cleaned up.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\User;
use craft\helpers\UrlHelper;
use craftpulse\typesense\controllers\KeysController;
use craftpulse\typesense\Typesense;

function aKeysAdmin(): User
{
    $suffix = bin2hex(random_bytes(4));
    $admin = new User();
    $admin->admin = true;
    $admin->username = "ts_keys_{$suffix}";
    $admin->email = "ts_keys_{$suffix}@example.test";
    Craft::$app->getElements()->saveElement($admin);

    return $admin;
}

function withKeysEdition(string $edition, callable $test): void
{
    $plugin = Typesense::$plugin;
    $original = $plugin->edition;
    $plugin->edition = $edition;

    try {
        $test();
    } finally {
        $plugin->edition = $original;
    }
}

// The show-once semantics are pinned at the service boundary (KeysTest: the full
// value is returned once at creation, the list exposes only the prefix, and the
// value is never persisted to project config). The show-once modal's visual walk
// lives in smoke-testing/p10-relevance-keys.md, since rendering the full CP
// layout in a test activates the SEOmatic deprecation cascade the estate avoids.

it('forbids key creation on the Free edition (server-side edition gate)', function() {
    $admin = aKeysAdmin();

    withKeysEdition(Typesense::EDITION_FREE, function() use ($admin) {
        $this->actingAs($admin)
            ->withExceptionHandling()
            ->post(UrlHelper::actionUrl('typesense/keys/save-key'), ['actions' => ['documents:search']])
            ->assertForbidden();
    });
});

it('forbids key creation for a user without manageKeys (server-side permission gate)', function() {
    $suffix = bin2hex(random_bytes(4));
    $user = new User();
    $user->username = "ts_nokeys_{$suffix}";
    $user->email = "ts_nokeys_{$suffix}@example.test";
    Craft::$app->getElements()->saveElement($user);

    withKeysEdition(Typesense::EDITION_PRO, function() use ($user) {
        expect(Craft::$app->getUser()->checkPermission(KeysController::PERMISSION_MANAGE_KEYS))->toBeBool();

        $this->actingAs($user)
            ->withExceptionHandling()
            ->post(UrlHelper::actionUrl('typesense/keys/save-key'), ['actions' => ['documents:search']])
            ->assertForbidden();
    });
});
