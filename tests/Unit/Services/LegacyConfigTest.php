<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Covers the compatibility shim: a legacy TypesenseCollectionIndex adapts into a
 * registry Collection with the same schema, element type, and resolver behavior,
 * uses the sharedWithSiteFilter strategy so the collection keeps its name, and
 * surfaces through the registry.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\Entry;
use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\enums\MultisiteStrategy;
use craftpulse\typesense\Typesense;
use craftpulse\typesense\TypesenseCollectionIndex;

function legacyIndexFixture(): TypesenseCollectionIndex
{
    return TypesenseCollectionIndex::create([
        'name' => 'shim_heroes',
        'section' => 'heroes.hero',
        'fields' => [
            ['name' => 'title', 'type' => 'string', 'sort' => true],
            ['name' => 'slug', 'type' => 'string', 'facet' => true],
            ['name' => 'post_date_timestamp', 'type' => 'int32'],
        ],
        'default_sorting_field' => 'post_date_timestamp',
        'resolver' => static fn(Entry $entry) => [
            'id' => (string)$entry->id,
            'title' => $entry->title,
            'slug' => $entry->slug,
        ],
    ])
        ->elementType(Entry::class)
        ->criteria(fn($query) => $query->section('heroes'));
}

it('adapts a legacy index into a registry collection', function() {
    $collection = Typesense::$plugin->getLegacyConfig()->adapt(legacyIndexFixture());

    expect($collection)->toBeInstanceOf(Collection::class)
        ->and($collection->getName())->toBe('shim_heroes')
        ->and($collection->getElementType())->toBe(Entry::class)
        ->and($collection->getMultisiteStrategy())->toBe(MultisiteStrategy::SharedWithSiteFilter);
});

it('preserves the legacy schema fields and default sorting field', function() {
    $schema = Typesense::$plugin->getLegacyConfig()->adapt(legacyIndexFixture())->toSchema();

    expect($schema['default_sorting_field'])->toBe('post_date_timestamp')
        ->and($schema['fields'])->toBe([
            ['name' => 'title', 'type' => 'string', 'sort' => true],
            ['name' => 'slug', 'type' => 'string', 'facet' => true],
            ['name' => 'post_date_timestamp', 'type' => 'int32'],
        ]);
});

it('reuses the legacy resolver as the transform', function() {
    $transform = Typesense::$plugin->getLegacyConfig()->adapt(legacyIndexFixture())->getTransform();

    $element = new class() extends Entry {
        public function getStatus(): ?string
        {
            return 'live';
        }
    };
    $element->id = 7;
    $element->title = 'Batman';
    $element->slug = 'batman';

    $document = $transform($element, fn() => null);

    expect($document)->toBe(['id' => '7', 'title' => 'Batman', 'slug' => 'batman']);
});

it('surfaces adapted legacy collections through the registry', function() {
    $settings = Typesense::$plugin->getSettings();
    $original = $settings->collections;
    $settings->collections = [legacyIndexFixture()];

    try {
        expect(Typesense::$plugin->getCollectionRegistry()->get('shim_heroes'))->toBeInstanceOf(Collection::class);
    } finally {
        $settings->collections = $original;
    }
});
