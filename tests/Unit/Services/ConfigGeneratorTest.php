<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Covers the config generator: a legacy config generates a fluent config file
 * that round-trips (running it back through the registry produces the same
 * runtime schema map the shim produced), and a registry Collection reverse-
 * migrates to a fluent config.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\Typesense;

it('generates a fluent config file from a legacy index', function() {
    $generator = Typesense::$plugin->getConfigGenerator();
    $php = $generator->file([$generator->fromLegacy(legacyIndexFixture())]);

    expect($php)->toContain("Collection::make('shim_heroes')")
        ->and($php)->toContain('->elementType(Entry::class)')
        ->and($php)->toContain("Field::make('title', 'string')->sort()")
        ->and($php)->toContain("->elementQuery(fn(\$query) => \$query->section('heroes')->type('hero'))")
        ->and($php)->toContain('REVIEW');
});

it('round-trips a generated config to the same schema map the shim produces', function() {
    $legacy = legacyIndexFixture();
    $shimSchema = Typesense::$plugin->getLegacyConfig()->adapt($legacy)->toSchema();

    $generator = Typesense::$plugin->getConfigGenerator();
    $php = $generator->file([$generator->fromLegacy($legacy)]);

    $path = Craft::$app->getPath()->getTempPath() . '/ts_gen_' . uniqid() . '.php';
    file_put_contents($path, $php);

    try {
        $config = require $path;
        $generated = $config['collections'][0];

        expect($generated)->toBeInstanceOf(Collection::class)
            ->and($generated->getName())->toBe('shim_heroes')
            ->and($generated->getElementType())->toBe(\craft\elements\Entry::class)
            ->and($generated->toSchema())->toBe($shimSchema);
    } finally {
        @unlink($path);
    }
});

it('reverse-migrates a registry collection to a fluent config', function() {
    $collection = Collection::make('cp_managed')
        ->elementType(\craft\elements\Entry::class)
        ->fields(\craftpulse\typesense\builders\Field::string('title')->facet());

    $php = Typesense::$plugin->getConfigGenerator()->fromCollection($collection);

    expect($php)->toContain("Collection::make('cp_managed')")
        ->and($php)->toContain("Field::make('title', 'string')->facet()");
});
