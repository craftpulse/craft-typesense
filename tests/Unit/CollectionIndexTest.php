<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Harness-proof coverage of the legacy fluent config shape (src/config.php):
 * the TypesenseCollectionIndex builder parses a collection definition into an
 * object with the expected properties, defaults the criteria to the primary
 * site, and rejects a non-element element type.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\Entry;
use craftpulse\typesense\TypesenseCollectionIndex;

it('builds a collection index from the fluent config shape', function() {
    $index = TypesenseCollectionIndex::create([
        'name' => 'schools',
        'section' => 'schools.default',
        'fields' => [
            ['name' => 'title', 'type' => 'string', 'sort' => true],
        ],
        'default_sorting_field' => 'post_date_timestamp',
    ])
        ->elementType(Entry::class)
        ->criteria(fn($query) => $query->section('schools'));

    expect($index)->toBeInstanceOf(TypesenseCollectionIndex::class)
        ->and($index->indexName)->toBe('schools')
        ->and($index->section)->toBe('schools.default')
        ->and($index->elementType)->toBe(Entry::class)
        ->and($index->schema['default_sorting_field'])->toBe('post_date_timestamp');
});

it('defaults the criteria site to the primary site', function() {
    $index = TypesenseCollectionIndex::create([
        'name' => 'schools',
        'section' => 'schools.default',
    ])->criteria(fn($query) => $query->section('schools'));

    expect($index->criteria->siteId)->toBe(Craft::$app->getSites()->getPrimarySite()->id);
});

it('rejects a non-element element type', function() {
    $build = fn() => TypesenseCollectionIndex::create([
        'name' => 'schools',
        'section' => 'schools.default',
    ])->elementType(stdClass::class);

    expect($build)->toThrow(Exception::class);
});
