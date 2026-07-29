<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The ops cockpit: the server metrics read is Free (surfaced in the utility),
 * while the snapshot, compaction, and cache-clear actions are Pro and gated by
 * the index-management permission, edition- and permission-checked server-side.
 * The disposable cache-clear action is exercised against the real server; the
 * destructive snapshot/compaction are only gate-checked (never run against the
 * seeded playground data).
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\User;
use craft\helpers\UrlHelper;
use craftpulse\typesense\Typesense;

function anOpsAdmin(): User
{
    $suffix = bin2hex(random_bytes(4));
    $admin = new User();
    $admin->admin = true;
    $admin->username = "ts_ops_{$suffix}";
    $admin->email = "ts_ops_{$suffix}@example.test";
    Craft::$app->getElements()->saveElement($admin);

    return $admin;
}

function withOpsEdition(string $edition, callable $test): void
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

it('reads server metrics (the Free ops read)', function() {
    $metrics = Typesense::$plugin->getClient()->metrics();

    // A live server returns memory metrics; the reader is fail-soft either way.
    expect($metrics)->toBeArray()
        ->and($metrics)->toHaveKey('system_memory_used_bytes');
});

it('clears the cache as a permitted admin in Pro', function() {
    $admin = anOpsAdmin();

    withOpsEdition(Typesense::EDITION_PRO, function() use ($admin) {
        $this->actingAs($admin)
            ->withExceptionHandling()
            ->post(UrlHelper::actionUrl('typesense/ops/clear-cache'))
            ->assertRedirect();
    });
});

it('forbids ops actions on the Free edition (server-side edition gate)', function() {
    $admin = anOpsAdmin();

    withOpsEdition(Typesense::EDITION_FREE, function() use ($admin) {
        $this->actingAs($admin)
            ->withExceptionHandling()
            ->post(UrlHelper::actionUrl('typesense/ops/compact'))
            ->assertForbidden();
    });
});

it('forbids ops actions for a user without manage-ops', function() {
    $suffix = bin2hex(random_bytes(4));
    $user = new User();
    $user->username = "ts_noops_{$suffix}";
    $user->email = "ts_noops_{$suffix}@example.test";
    Craft::$app->getElements()->saveElement($user);

    withOpsEdition(Typesense::EDITION_PRO, function() use ($user) {
        $this->actingAs($user)
            ->withExceptionHandling()
            ->post(UrlHelper::actionUrl('typesense/ops/snapshot'), ['snapshotPath' => '/tmp/nope'])
            ->assertForbidden();
    });
});
