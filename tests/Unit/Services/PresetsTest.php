<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The search-preset service. A collection's preset name is derived from its
 * resolved target, and a declared preset is mirrored into a global Typesense
 * preset (single API shape across versions). Runs against the real server.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\Typesense;

const PRESETS_TEST_COLLECTION = 'ts_presets_test';

it('derives the preset name from the resolved target', function() {
    $collection = Collection::make(PRESETS_TEST_COLLECTION);

    expect(Typesense::$plugin->getPresets()->presetName($collection))
        ->toContain(PRESETS_TEST_COLLECTION)
        ->toEndWith('_preset');
});

it('mirrors a declared preset into Typesense', function() {
    $collection = Collection::make(PRESETS_TEST_COLLECTION)
        ->preset(['query_by' => 'title', 'num_typos' => 2]);
    $presets = Typesense::$plugin->getPresets();
    $name = $presets->presetName($collection);

    try {
        $presets->seedFromConfig($collection);

        $stored = Typesense::$plugin->getClient()->request('GET', '/presets/' . $name);
        expect($stored['name'] ?? null)->toBe($name)
            ->and($stored['value']['query_by'] ?? null)->toBe('title');
    } finally {
        Typesense::$plugin->getClient()->request('DELETE', '/presets/' . $name);
    }
});

it('does nothing when no preset is declared', function() {
    $collection = Collection::make(PRESETS_TEST_COLLECTION);
    $presets = Typesense::$plugin->getPresets();

    $presets->seedFromConfig($collection);

    $stored = Typesense::$plugin->getClient()->request('GET', '/presets/' . $presets->presetName($collection));
    expect($stored)->not->toHaveKey('name');
});
