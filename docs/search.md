---
title: Searching
description: The search layer, Twig helpers, GraphQL, stopwords and stemming
---
# Searching

The Search service is the front-end entry point. It resolves a collection's
site-aware target name, enriches the query with the collection's preset, synonym
set (v30.2+), and stopwords, then runs it. Every path is fail-soft: an unreachable
server or an unknown collection returns a well-formed empty result, never an
error.

## In Twig

```twig
{% set results = craft.typesense.search('products', {
    q: 'winter coat',
    query_by: 'title,body',
    filter_by: 'siteId:=' ~ currentSite.id,
}) %}

{% for hit in results.hits %}
    {{ hit.document.title }}
{% endfor %}
```

Multi-search unions several collections in one round trip:

```twig
{% set results = craft.typesense.multiSearch([
    { collection: 'products', q: 'coat', query_by: 'title' },
    { collection: 'articles', q: 'coat', query_by: 'heading' },
]) %}
```

You never pass a preset or synonym set by hand: if the collection declares a
preset it is applied, and on v30.2+ the collection's synonym set is attached when
synonyms are declared. Anything you pass explicitly wins.

## In PHP

```php
use craftpulse\typesense\Typesense;

$results = Typesense::$plugin->getSearch()->search('products', [
    'q' => 'coat',
    'query_by' => 'title',
]);
```

## GraphQL (Free)

A `typesenseSearch` query is available as a developer tool. It runs entirely
server-side through the fail-soft search layer and returns the Typesense response
as a JSON string, so no API key is ever exposed to the client.

```graphql
{
    typesenseSearch(collection: "products", q: "coat", queryBy: "title", perPage: 10)
}
```

Arguments mirror the search surface (`q`, `queryBy`, `filterBy`, `sortBy`,
`facetBy`, `preset`, `page`, `perPage`) and map to Typesense's snake_case
parameters.

## Stopwords

Declare stopwords on a collection and they are seeded on creation, then attached
to every search automatically.

```php
Collection::make('products')
    ->stopwords(['the', 'a', 'an', 'of']);
```

## Stemming dictionaries

Import a stemming dictionary so a field's `stem_dictionary` reference resolves:

```php
Typesense::$plugin->getDictionaries()->importStemmingDictionary('en-plurals', [
    ['word' => 'shoes', 'root' => 'shoe'],
    ['word' => 'boots', 'root' => 'boot'],
]);
```

## Keeping keys off the client

For browser-side search, never ship the admin key. Derive a scoped search key
instead: see [Scoped search keys](scoped-keys.md).
