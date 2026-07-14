---
title: Extending (for plugin authors)
description: Events other plugins and modules can hook
---
# Extending

Typesense exposes events so other plugins, modules, and project code can register
their own collections, teach the mapper about custom field types, add computed
document fields, and shape documents before they are indexed.

## Register collections

Register collections in code instead of (or alongside) the config file. Useful
for companion plugins that own their own element types.

```php
use craftpulse\typesense\services\Collections;
use craftpulse\typesense\events\RegisterCollectionsEvent;
use craftpulse\typesense\builders\Collection;
use craft\elements\Entry;
use yii\base\Event;

Event::on(
    Collections::class,
    Collections::EVENT_REGISTER_COLLECTIONS,
    function(RegisterCollectionsEvent $event) {
        $event->collections[] = Collection::make('products')
            ->elementType(Entry::class)
            ->elementQuery(fn($query) => $query->section('products'));
    }
);
```

Config-file collections win over event-registered ones on a name collision (the
overridden name is flagged).

## Teach the mapper a field type

The generated-document path derives a Typesense type from each Craft field. Add
or override mappings for custom field types:

```php
use craftpulse\typesense\services\Schema;
use craftpulse\typesense\events\DefineTypeMapEvent;
use yii\base\Event;

Event::on(
    Schema::class,
    Schema::EVENT_DEFINE_TYPE_MAP,
    function(DefineTypeMapEvent $event) {
        $event->types[\my\plugin\fields\ColorField::class] = 'string';
    }
);
```

## Add a computed field

Computed fields add values to a document that are not backed by a Craft field.

```php
use craftpulse\typesense\services\Schema;
use craftpulse\typesense\events\RegisterComputedFieldsEvent;
use craftpulse\typesense\models\ComputedField;
use yii\base\Event;

Event::on(
    Schema::class,
    Schema::EVENT_REGISTER_COMPUTED_FIELDS,
    function(RegisterComputedFieldsEvent $event) {
        $event->computedFields[] = new ComputedField([
            'name' => 'popularity',
            'type' => 'int32',
            'value' => fn($element) => $element->viewCount ?? 0,
        ]);
    }
);
```

Reference a computed field on a collection with `->computedFields('popularity')`.

## Shape a document before or after indexing

`EVENT_BEFORE_INDEX_DOCUMENT` and `EVENT_AFTER_INDEX_DOCUMENT` carry the element,
the collection, the site ID, and the document array. Mutate `$event->document`
in the before-event to enrich or redact what gets indexed.

```php
use craftpulse\typesense\services\Documents;
use craftpulse\typesense\events\IndexDocumentEvent;
use yii\base\Event;

Event::on(
    Documents::class,
    Documents::EVENT_BEFORE_INDEX_DOCUMENT,
    function(IndexDocumentEvent $event) {
        $event->document['indexed_at'] = time();
    }
);
```

## Search from your own code

Use the search layer rather than talking to the client directly, so your queries
inherit the preset, synonym set, stopwords, and fail-soft contract:

```php
use craftpulse\typesense\Typesense;

$results = Typesense::$plugin->getSearch()->search('products', [
    'q' => 'coat',
    'query_by' => 'title',
]);
```
