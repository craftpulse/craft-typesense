<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The Fix 10 A0 regression pin: a config-file collection gets a name-keyed
 * collection screen, and its synonyms and curation stay control-panel-editable
 * whenever the config file is silent about that domain (presence-based ownership
 * from Fix 5). This asserts the screen resolution keys config collections by
 * name, and that a config collection silent on synonyms resolves as editable
 * (not config-owned).
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\User;
use craft\helpers\UrlHelper;
use craftpulse\typesense\controllers\CollectionsController;
use craftpulse\typesense\Typesense;

it('keys a config-file collection screen by name', function() {
    $screen = CollectionsController::resolveSectionScreen(null, 'heroes');

    expect($screen['editBase'])->toBe('typesense/collections/config/heroes')
        ->and($screen['editKey'])->toBe('config:heroes')
        ->and($screen['screenName'])->toBe('heroes')
        ->and($screen['disabled'])->toBeFalse()
        ->and($screen['collection'])->not->toBeNull();
});

it('resolves a config-collection screen key back to its runtime collection', function() {
    $collection = CollectionsController::collectionForScreenKey('config:heroes');

    expect($collection)->not->toBeNull()
        ->and($collection->getName())->toBe('heroes');
});

it('keeps a config-silent collection’s synonyms and curation control-panel-editable', function() {
    $collection = CollectionsController::collectionForScreenKey('config:heroes');

    // heroes declares no synonyms or curation in fluent config, so presence-based
    // ownership leaves both control-panel-owned (editable), which the section
    // screens require. This is the Fix 10 A0 regression the screen restores.
    expect(Typesense::$plugin->getSynonyms()->isConfigOwned($collection))->toBeFalse()
        ->and(Typesense::$plugin->getCuration()->isConfigOwned($collection))->toBeFalse();
});

it('forbids the config-collection overview for a user with no collection-screen permission', function() {
    $plugin = Typesense::$plugin;
    $original = $plugin->edition;
    $plugin->edition = Typesense::EDITION_PRO;

    $suffix = bin2hex(random_bytes(4));
    $user = new User();
    $user->username = "ts_cfg_{$suffix}";
    $user->email = "ts_cfg_{$suffix}@example.test";
    Craft::$app->getElements()->saveElement($user);

    try {
        $this->actingAs($user)
            ->withExceptionHandling()
            ->get(UrlHelper::actionUrl('typesense/collections/config-overview', ['name' => 'heroes']))
            ->assertForbidden();
    } finally {
        $plugin->edition = $original;
    }
});
