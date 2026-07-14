<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Schema-emission matrix for the Field builder: every type factory and every
 * field property emits the exact Typesense schema key, plus vector/embed and
 * version-aware validation.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\typesense\builders\Field;

it('emits each field type', function(string $method, string $expectedType) {
    expect(Field::{$method}('f')->toArray())->toBe(['name' => 'f', 'type' => $expectedType]);
})->with([
    ['string', 'string'],
    ['stringArray', 'string[]'],
    ['int32', 'int32'],
    ['int64', 'int64'],
    ['float', 'float'],
    ['bool', 'bool'],
    ['geopoint', 'geopoint'],
    ['object', 'object'],
    ['objectArray', 'object[]'],
    ['image', 'image'],
    ['auto', 'auto'],
]);

it('emits an explicit-type field via make()', function() {
    expect(Field::make('coords', 'geopoint[]')->toArray())->toBe(['name' => 'coords', 'type' => 'geopoint[]']);
});

it('emits a wildcard auto-schema field', function() {
    expect(Field::wildcard()->toArray())->toBe(['name' => '.*', 'type' => 'auto']);
});

it('emits scalar property toggles under their Typesense keys', function() {
    $schema = Field::string('title')
        ->facet()
        ->optional()
        ->index(false)
        ->store(false)
        ->sort()
        ->infix()
        ->rangeIndex()
        ->toArray();

    expect($schema)->toBe([
        'name' => 'title',
        'type' => 'string',
        'facet' => true,
        'optional' => true,
        'index' => false,
        'store' => false,
        'sort' => true,
        'infix' => true,
        'range_index' => true,
    ]);
});

it('emits stemming, locale, and dictionary keys', function() {
    $schema = Field::string('body')->stem()->locale('nl')->stemDictionary('nl_custom')->toArray();

    expect($schema['stem'])->toBeTrue()
        ->and($schema['locale'])->toBe('nl')
        ->and($schema['stem_dictionary'])->toBe('nl_custom');
});

it('emits field-level token separators and symbols', function() {
    $schema = Field::string('sku')->tokenSeparators(['-', '/'])->symbolsToIndex(['+', '#'])->toArray();

    expect($schema['token_separators'])->toBe(['-', '/'])
        ->and($schema['symbols_to_index'])->toBe(['+', '#']);
});

it('emits reference and reference options', function() {
    $schema = Field::string('author_id')->reference('authors.id')->asyncReference()->cascadeDelete(false)->toArray();

    expect($schema['reference'])->toBe('authors.id')
        ->and($schema['async_reference'])->toBeTrue()
        ->and($schema['cascade_delete'])->toBeFalse();
});

it('emits a manual vector field', function() {
    $schema = Field::vector('embedding', 384)->vecDist('l2')->hnswParams(['M' => 16])->toArray();

    expect($schema)->toBe([
        'name' => 'embedding',
        'type' => 'float[]',
        'num_dim' => 384,
        'vec_dist' => 'l2',
        'hnsw_params' => ['M' => 16],
    ]);
});

it('emits an auto-embedding vector field', function() {
    $schema = Field::vector('embedding', 384)
        ->embedFrom(['title', 'description'])
        ->model('ts/all-MiniLM-L12-v2')
        ->toArray();

    expect($schema['embed'])->toBe([
        'from' => ['title', 'description'],
        'model_config' => ['model_name' => 'ts/all-MiniLM-L12-v2'],
    ]);
});

it('merges provider-specific model config', function() {
    $schema = Field::vector('embedding', 1536)
        ->embedFrom(['title'])
        ->model('openai/text-embedding-3-small', ['api_key' => '$OPENAI_KEY'])
        ->toArray();

    expect($schema['embed']['model_config'])->toBe([
        'model_name' => 'openai/text-embedding-3-small',
        'api_key' => '$OPENAI_KEY',
    ]);
});

it('supports a raw escape hatch for unmodelled keys', function() {
    expect(Field::string('x')->raw('some_future_key', 'v')->toArray()['some_future_key'])->toBe('v');
});

it('flags object types as nested', function() {
    expect(Field::object('meta')->isNested())->toBeTrue()
        ->and(Field::objectArray('rows')->isNested())->toBeTrue()
        ->and(Field::string('title')->isNested())->toBeFalse();
});

it('exposes name and type accessors', function() {
    $field = Field::int64('post_date_timestamp');

    expect($field->getName())->toBe('post_date_timestamp')
        ->and($field->getType())->toBe('int64');
});

it('rejects cascade_delete on a server below v30', function() {
    $errors = Field::string('author_id')->reference('authors.id')->cascadeDelete()->validate(caps('28.0'));

    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toContain('30.0');
});

it('accepts cascade_delete on v30.2', function() {
    $errors = Field::string('author_id')->reference('authors.id')->cascadeDelete()->validate(caps('30.2'));

    expect($errors)->toBe([]);
});
