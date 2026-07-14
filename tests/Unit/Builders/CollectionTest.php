<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Schema-emission and runtime coverage for the Collection builder: exact schema
 * arrays (including automatic enable_nested_fields), the held runtime callables
 * and options, and aggregated version-aware validation.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\Entry;
use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\builders\Field;
use craftpulse\typesense\enums\MultisiteStrategy;

it('emits a minimal collection schema', function() {
    $schema = Collection::make('heroes')
        ->fields(Field::string('title'), Field::int64('post_date_timestamp'))
        ->defaultSortingField('post_date_timestamp')
        ->toSchema();

    expect($schema)->toBe([
        'name' => 'heroes',
        'fields' => [
            ['name' => 'title', 'type' => 'string'],
            ['name' => 'post_date_timestamp', 'type' => 'int64'],
        ],
        'default_sorting_field' => 'post_date_timestamp',
    ]);
});

it('auto-enables nested fields when an object field is present', function() {
    $schema = Collection::make('c')->fields(Field::object('meta'))->toSchema();

    expect($schema['enable_nested_fields'])->toBeTrue();
});

it('does not set enable_nested_fields without object fields', function() {
    $schema = Collection::make('c')->fields(Field::string('title'))->toSchema();

    expect($schema)->not->toHaveKey('enable_nested_fields');
});

it('emits collection-level token separators, symbols, and metadata', function() {
    $schema = Collection::make('c')
        ->fields(Field::string('title'))
        ->tokenSeparators(['-'])
        ->symbolsToIndex(['+'])
        ->metadata(['env' => 'test'])
        ->toSchema();

    expect($schema['token_separators'])->toBe(['-'])
        ->and($schema['symbols_to_index'])->toBe(['+'])
        ->and($schema['metadata'])->toBe(['env' => 'test']);
});

it('supports a raw top-level escape hatch', function() {
    $schema = Collection::make('c')->fields(Field::string('title'))->raw('voice_query_model', ['x' => 1])->toSchema();

    expect($schema['voice_query_model'])->toBe(['x' => 1]);
});

it('holds element type, queries, and transform as runtime callables', function() {
    $query = fn($q) => $q;
    $transform = fn($element, $registerDependency) => ['id' => (string)$element->id];

    $collection = Collection::make('heroes')
        ->elementType(Entry::class)
        ->elementQueries([$query, $query])
        ->transform($transform);

    expect($collection->getElementType())->toBe(Entry::class)
        ->and($collection->getElementQueries())->toHaveCount(2)
        ->and($collection->getTransform())->toBe($transform);
});

it('wraps a single element query in the queries array', function() {
    $query = fn($q) => $q;
    $collection = Collection::make('heroes')->elementQuery($query);

    expect($collection->getElementQueries())->toBe([$query]);
});

it('holds sync options and defaults', function() {
    $collection = Collection::make('heroes');

    expect($collection->getPageSize())->toBe(100)
        ->and($collection->getAutoSync())->toBeTrue()
        ->and($collection->getMultisiteStrategy())->toBe(MultisiteStrategy::CollectionPerSite)
        ->and($collection->getActiveStatuses())->toBeNull();

    $collection->pageSize(500)->autoSync(false)
        ->multisite(MultisiteStrategy::SharedWithSiteFilter)
        ->activeStatuses(['live']);

    expect($collection->getPageSize())->toBe(500)
        ->and($collection->getAutoSync())->toBeFalse()
        ->and($collection->getMultisiteStrategy())->toBe(MultisiteStrategy::SharedWithSiteFilter)
        ->and($collection->getActiveStatuses())->toBe(['live']);
});

it('holds managedBy overrides, preset, and computed fields', function() {
    $collection = Collection::make('heroes')
        ->synonyms('cp')
        ->curation('config')
        ->preset(['per_page' => 20])
        ->computedFields('readingTime', 'popularity');

    expect($collection->getSynonymsManagedBy())->toBe('cp')
        ->and($collection->getCurationManagedBy())->toBe('config')
        ->and($collection->getPreset())->toBe(['per_page' => 20])
        ->and($collection->getComputedFieldNames())->toBe(['readingTime', 'popularity']);
});

it('aggregates field version validation', function() {
    $errors = Collection::make('c')
        ->fields(
            Field::string('title'),
            Field::string('author_id')->reference('authors.id')->cascadeDelete(),
        )
        ->validate(caps('28.0'));

    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toContain('cascade_delete');
});
