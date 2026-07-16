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

use craftpulse\typesense\jobs\RebuildCollection;
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

it('unserializes a queue payload that carries a legacy class name', function() {
    // A queue message already in the database, enqueued before the upgrade, holds
    // the serialized job under its old FQCN. Simulate that exact string and prove
    // it unserializes and runs: the fallback autoloader aliases the legacy class
    // during unserialize(), so the payload resolves to the new job class.
    $newClass = RebuildCollection::class;
    $legacyClass = 'percipiolondon\\typesense\\jobs\\RebuildCollection';

    $job = new RebuildCollection();
    $job->collectionHandle = 'heroes';
    $job->siteId = 1;

    // Rewrite the class token in the serialized payload to the legacy FQCN,
    // recomputing the length prefix so the string stays valid.
    $legacyPayload = str_replace(
        sprintf('O:%d:"%s"', strlen($newClass), $newClass),
        sprintf('O:%d:"%s"', strlen($legacyClass), $legacyClass),
        serialize($job),
    );

    /** @var RebuildCollection $restored */
    $restored = unserialize($legacyPayload);

    expect($restored)->toBeInstanceOf($newClass)
        ->and($restored->collectionHandle)->toBe('heroes')
        ->and($restored->siteId)->toBe(1);
});
