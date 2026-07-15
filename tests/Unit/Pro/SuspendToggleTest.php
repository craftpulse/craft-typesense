<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The sync-suspend toggle is runtime database state, not project config, so it
 * must succeed even when allowAdminChanges is disabled (the exact case that
 * blocked the old settings-backed switch). Covers the global utility toggle and
 * the per-collection cockpit toggle, both with allowAdminChanges off.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\helpers\UrlHelper;
use craftpulse\typesense\Typesense;

it('toggles the global suspend from the utility with allowAdminChanges disabled', function() {
    $general = Craft::$app->getConfig()->getGeneral();
    $original = $general->allowAdminChanges;
    $general->allowAdminChanges = false;
    $suspend = Typesense::$plugin->getSyncSuspend();
    $suspend->resume();

    try {
        $this->actingAsAdmin()
            ->post(UrlHelper::actionUrl('typesense/utility/toggle-suspend'), [
                'redirect' => Craft::$app->getSecurity()->hashData('utilities/typesense'),
            ])
            ->assertRedirect();

        expect($suspend->isGloballySuspended())->toBeTrue();
    } finally {
        $suspend->resume();
        $general->allowAdminChanges = $original;
    }
});

it('toggles a per-collection suspend from the cockpit with allowAdminChanges disabled', function() {
    $general = Craft::$app->getConfig()->getGeneral();
    $original = $general->allowAdminChanges;
    $general->allowAdminChanges = false;
    $suspend = Typesense::$plugin->getSyncSuspend();
    $suspend->resume('heroes');

    try {
        // A redirect (not a 403) is the success path for a non-ajax POST; the
        // database state is the authoritative proof the toggle took effect.
        $this->actingAsAdmin()
            ->post(UrlHelper::actionUrl('typesense/collections/toggle-suspend'), [
                'collection' => 'heroes',
            ])
            ->assertRedirect();

        expect($suspend->isCollectionSuspended('heroes'))->toBeTrue();
    } finally {
        $suspend->resume('heroes');
        $general->allowAdminChanges = $original;
    }
});
