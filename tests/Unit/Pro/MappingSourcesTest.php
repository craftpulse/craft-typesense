<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The mapping source enumerator: it turns a collection definition's element
 * source into field descriptors carrying the server-derived Typesense type and
 * the allowed control set, and it adds cards for the file-derived asset pseudo
 * fields. Runs against the playground's real sections and volumes.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\Asset;
use craft\elements\Entry;
use craftpulse\typesense\models\CollectionDefinition;
use craftpulse\typesense\Typesense;

it('builds field descriptors with a derived type and allowed controls for a section', function() {
    $definition = new CollectionDefinition();
    $definition->elementType = Entry::class;
    $definition->source = 'heroes';

    $descriptors = Typesense::$plugin->getMappingSources()->descriptorsFor($definition);

    expect($descriptors)->not->toBeEmpty();

    foreach ($descriptors as $descriptor) {
        expect($descriptor)->toHaveKeys(['uid', 'label', 'derivedType', 'kind', 'controls'])
            ->and($descriptor['controls'])->not->toBeEmpty();

        $keys = array_column($descriptor['controls'], 'key');
        expect($keys)->toContain('indexable')
            ->and($keys)->toContain('description');
    }
});

it('adds the file-derived pseudo fields and the image-embed control for an asset source', function() {
    $definition = new CollectionDefinition();
    $definition->elementType = Asset::class;
    $definition->source = null;

    $descriptors = Typesense::$plugin->getMappingSources()->descriptorsFor($definition);
    $pseudo = array_values(array_filter($descriptors, static fn(array $d): bool => $d['kind'] === 'pseudo'));
    $handles = array_column($pseudo, 'handle');

    expect($handles)->toContain('filename')
        ->and($handles)->toContain('kind')
        ->and($handles)->toContain('size')
        ->and($handles)->toContain('width')
        ->and($handles)->toContain('height');

    $filename = $pseudo[array_search('filename', $handles, true)];
    $controlKeys = array_column($filename['controls'], 'key');

    expect($controlKeys)->toContain('imageEmbed');
});
