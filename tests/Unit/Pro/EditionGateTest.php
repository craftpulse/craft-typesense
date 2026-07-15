<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The Pro edition foundation: the plugin declares Free and Pro, the Pro control
 * panel is hidden (not badged) in Free, and every Pro action is edition-checked
 * server-side, so a crafted POST from an admin still fails closed on Free.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\User;
use craft\helpers\UrlHelper;
use craftpulse\typesense\Typesense;

function withEdition(string $edition, callable $test): void
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

function anAdmin(): User
{
    $suffix = bin2hex(random_bytes(4));
    $user = new User();
    $user->admin = true;
    $user->username = "ts_pro_{$suffix}";
    $user->email = "ts_pro_{$suffix}@example.test";
    Craft::$app->getElements()->saveElement($user);

    return $user;
}

it('declares the Free and Pro editions', function() {
    expect(Typesense::editions())->toBe([Typesense::EDITION_FREE, Typesense::EDITION_PRO]);
});

it('reports the active edition through isPro', function() {
    withEdition(Typesense::EDITION_PRO, function() {
        expect(Typesense::$plugin->getIsPro())->toBeTrue();
    });

    withEdition(Typesense::EDITION_FREE, function() {
        expect(Typesense::$plugin->getIsPro())->toBeFalse();
    });
});

it('hides the Pro nav in Free and shows it in Pro (hide, not badge)', function() {
    $this->actingAs(anAdmin());

    withEdition(Typesense::EDITION_FREE, function() {
        $subnav = Typesense::$plugin->getCpNavItem()['subnav'] ?? [];
        expect($subnav)->not->toHaveKey('collections')
            ->and($subnav)->not->toHaveKey('dictionaries')
            ->and($subnav)->not->toHaveKey('analytics');
    });

    withEdition(Typesense::EDITION_PRO, function() {
        // Curation is now a section of the collection edit screen (Fix 9), so the
        // top-level subnav carries Collections, Dictionaries, and Analytics.
        $subnav = Typesense::$plugin->getCpNavItem()['subnav'] ?? [];
        expect($subnav)->toHaveKey('collections')
            ->and($subnav)->toHaveKey('dictionaries')
            ->and($subnav)->toHaveKey('analytics');
    });
});

it('forbids a crafted POST to a Pro action in Free, even for an admin', function() {
    $admin = anAdmin();

    withEdition(Typesense::EDITION_FREE, function() use ($admin) {
        $this->actingAs($admin)
            ->withExceptionHandling()
            ->post(UrlHelper::actionUrl('typesense/collections/save'))
            ->assertForbidden();
    });
});

it('allows the Pro action in Pro for a permitted admin', function() {
    $admin = anAdmin();

    withEdition(Typesense::EDITION_PRO, function() use ($admin) {
        // A permitted admin passes both the edition and the permission gate. The
        // delete of a non-existent collection is a safe no-op that redirects
        // (302), the opposite of the 403 the Free edition returns, and it never
        // writes project config.
        $this->actingAs($admin)
            ->withExceptionHandling()
            ->post(UrlHelper::actionUrl('typesense/collections/delete'), ['id' => 'does-not-exist'])
            ->assertRedirect();
    });
});
