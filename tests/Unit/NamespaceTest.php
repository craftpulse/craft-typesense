<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * P0 scaffold gate anchor. Proves the 5.9.0 namespace move landed cleanly: the
 * plugin boots under `craftpulse\typesense`, its services resolve through the
 * component container under the new namespace, and the pre-5.9.0
 * `percipiolondon\typesense` class names still resolve through the
 * backwards-compatibility shim so existing config files keep working.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 * @since     5.9.0
 */

use craftpulse\typesense\services\Client;
use craftpulse\typesense\Typesense;
use craftpulse\typesense\TypesenseCollectionIndex;

it('boots the plugin under the craftpulse namespace', function() {
    $plugin = Craft::$app->getPlugins()->getPlugin('typesense');

    expect($plugin)->toBeInstanceOf(Typesense::class)
        ->and($plugin::class)->toStartWith('craftpulse\\typesense');
});

it('resolves a service under the new namespace', function() {
    /** @var Typesense $plugin */
    $plugin = Craft::$app->getPlugins()->getPlugin('typesense');

    expect($plugin->getClient())->toBeInstanceOf(Client::class);
});

it('aliases the legacy percipiolondon class names to the craftpulse namespace', function() {
    $legacyClass = 'percipiolondon\\typesense\\TypesenseCollectionIndex';

    expect(class_exists($legacyClass))->toBeTrue();

    $index = $legacyClass::create([
        'name' => 'heroes',
        'section' => 'heroes.hero',
    ]);

    expect($index)->toBeInstanceOf(TypesenseCollectionIndex::class);
});
