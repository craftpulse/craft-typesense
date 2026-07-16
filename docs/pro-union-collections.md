---
title: Union collections (Pro)
description: Index several element sources into one searchable collection, distinguished by an _elementType discriminator
---
# Union collections (Pro)

A regular collection indexes one element source. A **union collection** indexes
several member sources (sections, asset volumes, entry types, and other element
sources) into one Typesense collection, so a single search spans them all. Each
document carries a reserved `_elementType` field naming the member it came from,
so the front end can facet by type and render a different card per member.

## Two collection types

When you create a collection (Typesense, then Collections, then New collection),
you choose a **Collection type**:

- **Regular:** one element source. Nothing about regular collections changed.
- **Union:** many member sources.

The type is chosen once and **locked after creation** (the schema shape differs
fundamentally), so pick it up front. A union reveals a **Members** section in
place of the single Mapping section.

## Members

Each member is one element source with a unique **handle** (the `_elementType`
value for its documents) and its own field mapping. On the Members section:

1. Add each source in the members table (an element source per row, with a
   handle).
2. Save. Each member then gets its own mapping field-layout designer, visually
   separated, scoped to that member's source (its palette shows that member's
   fields).
3. Map each member's fields and save.

Keep the member set focused: every member's fields widen the union schema, which
has a memory cost on the server. A handful of members is the sweet spot; dozens
of members with many fields each will grow the collection's memory footprint.

## How fields combine

The members' field sets resolve into one union schema:

- **Explicit output handle wins.** A mapped field's output handle (its document
  key) is what you set on the field, so renaming a member's field renames its
  document key.
- **Shared fields merge.** When two members map a field to the **same document
  key and the same Typesense type** (for example both map a `title` string), they
  share one document field. This is the useful case for full-site search: a
  common `title`/`url`/`summary` across members.
- **Type mismatches namespace.** When two members map the same document key to
  **different types**, the later member's field is automatically namespaced to
  `<memberHandle>_<key>` so neither is lost, and the mapping UI notes it. Give the
  fields distinct handles to avoid the namespacing.

## The reserved fields

Every union document carries:

- `_elementType` (string, faceted): the member handle. Filter and facet by it.
- `_elementClass` (string, faceted): the source element's class short name (for
  example `Entry`, `Asset`), an informational sibling.

Regular collections do **not** get these fields; their schema and documents are
unchanged.

## Front end

Search a union collection exactly like any other. Facet by `_elementType` to let
visitors filter by member, and render a different card per member by copying a
`_result-card--<handle>.twig` into your front-end templates directory (see the
front-end theming guide). The default card is used for any member without one.

```twig
{% set results = craft.typesense.search('site-search', {
    q: query,
    query_by: 'title',
    facet_by: '_elementType',
}) %}
```

## Fluent config

A config-owned union declares its members with the fluent builder:

```php
use craftpulse\typesense\builders\Collection;
use craft\elements\Entry;
use craft\elements\Asset;

Collection::union('site-search')
    ->addMember(Entry::class, fn($query) => $query->section('news'), 'news')
    ->addMember(Asset::class, fn($query) => $query->volume('images'), 'images');
```
