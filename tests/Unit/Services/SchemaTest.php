<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Covers the Schema service: Craft-field to Typesense-type derivation (exact,
 * instanceof, default, and EVENT_DEFINE_TYPE_MAP extension), geopoint value
 * normalization, and the computed-field registry.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\base\FieldInterface;
use craft\fields\Categories;
use craft\fields\Date;
use craft\fields\Lightswitch;
use craft\fields\Number;
use craft\fields\PlainText;
use craft\fields\Table;
use craftpulse\typesense\events\DefineTypeMapEvent;
use craftpulse\typesense\events\RegisterComputedFieldsEvent;
use craftpulse\typesense\models\ComputedField;
use craftpulse\typesense\services\Schema;
use craftpulse\typesense\Typesense;
use yii\base\Event;

function schema(): Schema
{
    return Typesense::$plugin->getSchema();
}

it('derives Typesense types from core field classes', function(string $fieldClass, string $expected) {
    expect(schema()->typesenseTypeForField(new $fieldClass()))->toBe($expected);
})->with([
    [PlainText::class, 'string'],
    [Number::class, 'float'],
    [Lightswitch::class, 'bool'],
    [Date::class, 'int64'],
    [Categories::class, 'string[]'],
    [Table::class, 'object[]'],
]);

it('falls back to the default type for unmapped fields', function() {
    $field = $this->createMock(FieldInterface::class);

    expect(schema()->typesenseTypeForField($field))->toBe(Schema::DEFAULT_TYPE);
});

it('resolves subclasses through the instanceof fallback', function() {
    $field = new class() extends PlainText {
    };

    expect(schema()->typesenseTypeForField($field))->toBe('string');
});

it('lets EVENT_DEFINE_TYPE_MAP extend the derivation map', function() {
    $handler = function(DefineTypeMapEvent $event) {
        $event->types['vendor\\maps\\fields\\LatLng'] = 'geopoint';
    };
    Event::on(Schema::class, Schema::EVENT_DEFINE_TYPE_MAP, $handler);

    try {
        expect(schema()->getTypeMap())->toHaveKey('vendor\\maps\\fields\\LatLng')
            ->and(schema()->getTypeMap()['vendor\\maps\\fields\\LatLng'])->toBe('geopoint');
    } finally {
        Event::off(Schema::class, Schema::EVENT_DEFINE_TYPE_MAP, $handler);
    }
});

it('normalizes geopoint shapes to [lat, lng]', function(mixed $value, ?array $expected) {
    expect(schema()->normalizeGeopoint($value))->toBe($expected);
})->with([
    'pair' => [[50.8503, 4.3517], [50.8503, 4.3517]],
    'lat/lng keys' => [['lat' => 50.85, 'lng' => 4.35], [50.85, 4.35]],
    'latitude/longitude keys' => [['latitude' => 50.85, 'longitude' => 4.35], [50.85, 4.35]],
    'zero coordinate skipped' => [[0, 0], null],
    'null coordinate skipped' => [['lat' => null, 'lng' => 4.35], null],
    'non-location' => ['not a point', null],
]);

it('normalizes an object with lat/lng properties', function() {
    $value = (object)['lat' => 51.5, 'lng' => -0.12];

    expect(schema()->normalizeGeopoint($value))->toBe([51.5, -0.12]);
});

it('registers and resolves computed fields', function() {
    $handler = function(RegisterComputedFieldsEvent $event) {
        $computed = new ComputedField();
        $computed->name = 'readingTime';
        $computed->type = 'int32';
        $computed->value = fn($element) => 5;
        $event->computedFields[] = $computed;
    };
    Event::on(Schema::class, Schema::EVENT_REGISTER_COMPUTED_FIELDS, $handler);

    try {
        $computed = schema()->getComputedField('readingTime');

        expect($computed)->not->toBeNull()
            ->and($computed->resolve(null))->toBe(5)
            ->and($computed->toField()->toArray())->toBe(['name' => 'readingTime', 'type' => 'int32']);
    } finally {
        Event::off(Schema::class, Schema::EVENT_REGISTER_COMPUTED_FIELDS, $handler);
    }
});
