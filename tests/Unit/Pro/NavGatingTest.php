<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Hide-not-badge (P13 audit): the control-panel nav renders NO Pro sub-items in
 * the Free edition (even for an admin who holds every permission), and renders
 * them in Pro. Server-capability gating is pinned at its source: on the v28
 * playground the version-locked capabilities are off, so the surfaces that gate
 * on them (MMR, cloning, analytics tags, NL search, personalization) are hidden.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\User;
use craftpulse\typesense\Typesense;

function aNavAdmin(): User
{
    $suffix = bin2hex(random_bytes(4));
    $admin = new User();
    $admin->admin = true;
    $admin->username = "ts_nav_{$suffix}";
    $admin->email = "ts_nav_{$suffix}@example.test";
    Craft::$app->getElements()->saveElement($admin);

    return $admin;
}

function navSubnavKeys(string $edition, User $admin): array
{
    $plugin = Typesense::$plugin;
    $original = $plugin->edition;
    $plugin->edition = $edition;
    Craft::$app->getUser()->setIdentity($admin);

    try {
        $navItem = $plugin->getCpNavItem();

        return array_keys($navItem['subnav'] ?? []);
    } finally {
        $plugin->edition = $original;
        Craft::$app->getUser()->setIdentity(null);
    }
}

it('renders no Pro nav items in Free, even for an admin', function() {
    $admin = aNavAdmin();
    $keys = navSubnavKeys(Typesense::EDITION_FREE, $admin);

    // Free shows only the always-available settings sub-item.
    expect($keys)->toBe(['settings']);
});

it('renders the Pro nav items in Pro for a permitted admin', function() {
    $admin = aNavAdmin();
    $keys = navSubnavKeys(Typesense::EDITION_PRO, $admin);

    // Relevance, Vector / AI (Fix 8), Synonyms, and Curation (Fix 9) are now
    // sections of the collection edit screen, not top-level subnav items, so they
    // are absent from the subnav keys. Dictionaries stays top-level (stopwords
    // and stemming are server-global resources).
    foreach (['collections', 'dictionaries', 'keys', 'aliases', 'experiments', 'analytics', 'settings'] as $expected) {
        expect($keys)->toContain($expected);
    }

    foreach (['relevance', 'synonyms', 'curation'] as $moved) {
        expect($keys)->not->toContain($moved);
    }
});

it('pins the v28 server-capability gates (hide-not-badge at the source)', function() {
    $capabilities = Typesense::$plugin->getClient()->getServerCapabilities();

    // v28 supports text-match buckets and hybrid reranking...
    expect($capabilities?->textMatchBuckets())->toBeTrue()
        ->and($capabilities?->rerankHybridMatches())->toBeTrue()
        // ...but not the newer, version-locked features, so their surfaces hide.
        ->and($capabilities?->mmr())->toBeFalse()
        ->and($capabilities?->collectionCloning())->toBeFalse()
        ->and($capabilities?->analyticsTags())->toBeFalse()
        ->and($capabilities?->nlSearch())->toBeFalse()
        ->and($capabilities?->personalizationModels())->toBeFalse();
});
