---
title: Fluent config
description: Authoring collections and fields in config/typesense.php with the fluent builders
---
# Fluent config

Collections are authored in `config/typesense.php` with two fluent builders,
`Collection` and `Field`. Builders emit plain Typesense schema arrays; the
runtime concerns (element queries, the document transform) are held as closures
and never serialize to project config.

```php
use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\builders\Field;
use craft\elements\Entry;
use craft\elements\db\EntryQuery;

return [
    'collections' => [
        Collection::make('heroes')
            ->fields(
                Field::string('title')->sort(),
                Field::string('slug')->facet(),
                Field::int64('post_date_timestamp'),
            )
            ->defaultSortingField('post_date_timestamp')
            ->elementType(Entry::class)
            ->elementQuery(fn(EntryQuery $query) => $query->section('heroes'))
            ->transform(fn(Entry $entry, callable $registerDependency) => [
                'id' => (string)$entry->id,
                'title' => $entry->title,
                'slug' => $entry->slug,
                'post_date_timestamp' => $entry->postDate ? (int)$entry->postDate->format('U') : 0,
            ]),
    ],
];
```

## The Field builder

Create a field with a type factory (`Field::string()`, `int32()`, `int64()`,
`float()`, `bool()`, `stringArray()`, `geopoint()`, `object()`, `objectArray()`,
`image()`, `auto()`, `wildcard()`, `vector()`) or `Field::make($name, $type)` for
any type string.

Chainable properties (each maps to the Typesense schema key of the same idea):

| Method | Typesense key |
| --- | --- |
| `facet()` | `facet` |
| `optional()` | `optional` |
| `index(false)` | `index` |
| `store(false)` | `store` |
| `sort()` | `sort` |
| `infix()` | `infix` |
| `stem()` | `stem` |
| `stemDictionary($name)` | `stem_dictionary` |
| `locale($code)` | `locale` |
| `rangeIndex()` | `range_index` |
| `tokenSeparators([...])` | `token_separators` |
| `symbolsToIndex([...])` | `symbols_to_index` |
| `reference('authors.id')` | `reference` |
| `asyncReference()` | `async_reference` |
| `cascadeDelete()` | `cascade_delete` (needs server v30.0+) |
| `numDim($n)` / `vecDist($m)` / `hnswParams([...])` | vector tuning |
| `embedFrom([...])` + `model($name, $config)` | `embed` |
| `raw($key, $value)` | any future key |

### Vector fields

```php
Field::vector('embedding', 384)
    ->embedFrom(['title', 'description'])
    ->model('ts/all-MiniLM-L12-v2');
```

### Object fields

When a collection contains any `object` or `object[]` field, the builder sets
`enable_nested_fields: true` on the collection automatically.

### JOIN references (fluent-config only)

`Field::reference('authors.id')` declares a cross-collection reference (JOIN).
JOINs are a fluent-config-only power feature: the Pro mapping UI deliberately
never surfaces cross-collection references. This is an intentional boundary, not
a gap.

## The Collection builder

| Method | Purpose |
| --- | --- |
| `fields(...)` / `addField()` | The collection fields. |
| `defaultSortingField($field)` | Default sort field (must be int32 or float). |
| `tokenSeparators([...])` / `symbolsToIndex([...])` / `metadata([...])` | Top-level schema params. |
| `elementType($class)` | The Craft element class (default Entry). |
| `elementQuery($fn)` / `elementQueries([$fn, ...])` | One or more query callables. |
| `transform($fn)` | Document transform: `fn($element, callable $registerDependency): array`. |
| `activeStatuses([...])` | Element statuses treated as live. |
| `pageSize($n)` | Batch page size. |
| `autoSync(false)` | Turn off event-driven sync for this collection. |
| `multisite($strategy)` | `MultisiteStrategy::CollectionPerSite` or `SharedWithSiteFilter`. |
| `synonyms($managedBy)` / `curation($managedBy)` | Per-collection ownership override (`config` or `cp`). |
| `preset([...])` | Per-collection search preset. |
| `computedFields('name', ...)` | Attach registered computed fields by name. |
| `raw($key, $value)` | Any future top-level key. |

## Multisite strategies

- `CollectionPerSite`: one Typesense collection per Craft site.
- `SharedWithSiteFilter`: one shared collection carrying a site field that
  searches filter on.

## Version-aware validation

Builder features that require a specific server version validate against the
detected `ServerCapabilities` and fail with the required version named (for
example `cascade_delete` requires v30.0 or later). Servers below v28.0 are
unsupported and v30.0 and v30.1 are refused.

## Registration events

Other plugins and project code extend the fluent layer through events:

- `Collections::EVENT_REGISTER_COLLECTIONS`: register `Collection` builders. A
  config-file collection of the same name wins and flags the name as overridden.
- `Schema::EVENT_DEFINE_TYPE_MAP`: extend the Craft-field-class to
  Typesense-type derivation map (for example a maps plugin mapping its field to
  `geopoint`).
- `Schema::EVENT_REGISTER_COMPUTED_FIELDS`: register named value closures that
  surface as computed-field cards.

## Environment prefix

The collection-name prefix setting is applied by a single resolver
(`Collections::resolveName()`, backed by the client) wherever a Typesense target
name is produced, so staging and production can share one cluster.
