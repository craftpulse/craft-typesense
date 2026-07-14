---
title: Multi-site
description: How collections map to Craft's sites
---
# Multi-site

Every collection declares how it maps to Craft's sites through a multi-site
strategy. Pick the one that matches how your front end queries Typesense.

## Strategies

| Strategy | Behaviour |
| --- | --- |
| `SharedWithSiteFilter` | One Typesense collection holds documents from every site. Each document carries a reserved `siteId` field, so the front end filters with `filter_by: 'siteId:=<id>'`. Best when sites share an index and you filter at query time. |
| `CollectionPerSite` | One Typesense collection per site, named `<collection>_<siteHandle>`. Best when sites are fully independent or have different schemas per locale. |

```php
use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\enums\MultisiteStrategy;

Collection::make('products')
    ->multisite(MultisiteStrategy::SharedWithSiteFilter)
    // ...
    ;
```

## Reserved fields

Every document carries two reserved fields the plugin manages for you:

- `elementId`: the Craft element ID, used for reliable deletion.
- `siteId`: the Craft site ID, used for `SharedWithSiteFilter` filtering.

Do not declare these yourself; they are added to the schema automatically.

## Searching a specific site

With `SharedWithSiteFilter`, scope the front end to the current site:

```twig
{% set results = craft.typesense.search('products', {
    q: 'coat',
    query_by: 'title',
    filter_by: 'siteId:=' ~ currentSite.id,
}) %}
```

The search layer resolves `CollectionPerSite` target names to the current site
automatically, so `craft.typesense.search('products', ...)` hits the right
per-site collection without a suffix in your template.

## Syncing

`craft typesense/sync/all` syncs every collection across every site the
collection targets, and `craft typesense/sync/collection <handle>` syncs a
single collection across its sites:

```bash
php craft typesense/sync/collection products
```

Each site is processed as its own batched job. Reconciliation is site-scoped:
reconciling one site never deletes another site's documents from a shared
collection.
