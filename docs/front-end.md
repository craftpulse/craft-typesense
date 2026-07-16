---
title: Front-end search (Datastar)
description: The fragment endpoint, render builders, and example templates
---
# Front-end search

The plugin ships a complete, progressively-enhanced front-end search built on
[Datastar](https://data-star.dev/) (pinned to **v1.0.2**), a ~10kb hypermedia
library that drives requests from `data-*` attributes and applies the patches
the server streams back. There is no client-side templating and no build step:
the admin API key never approaches the browser, the search runs server-side, and
the whole thing works with JavaScript disabled.

When Datastar drives the request, the endpoint answers with Server-Sent Events
(`text/event-stream`) composed by the official
[datastar-php](https://github.com/starfederation/datastar-php) SDK: one
`datastar-patch-elements` event per region (results, facets, pagination) morphed
in place by its id, plus a `datastar-patch-signals` event carrying the found
count, the elapsed milliseconds, and the searching flag, so the status line
updates without morphing a region. Any other request (a no-JS form GET, a
crawler, a direct hit) gets the plain `text/html` fragments instead. The result
data is identical; only the wrapper differs.

## The pieces

1. A **fragment endpoint** that runs a search server-side and returns the
   results, facet sidebar, and pagination as HTML fragments.
2. **Render builders** (`craft.typesense.*`) that emit the Datastar-wired markup.
3. **Example templates** you install with one command and adapt.

## Quick start

```bash
php craft typesense/example-templates
```

Copies the bundle to `templates/typesense/` (choose another folder when
prompted; internal paths are rewritten to match). Visit `/typesense/search`.

To build your own page, one builder call is enough:

```twig
{{ craft.typesense.searchForm({
    collection: 'products',
    queryBy: 'title,body',
    facetBy: 'brand',
    perPage: 12,
    placeholder: 'Search products...'|t,
}).render() }}
```

This renders a Datastar-signalled `<form>` around the search input, facet
sidebar, results, and pagination, server-rendered on first load and
morph-updated as the shopper types, toggles a facet, or pages.

## The fragment endpoint

`GET` (or `POST`) to the `typesense/search/results` action. It is anonymous (it
serves the public front end) and only ever reads. Every parameter is bounded in
the search service, and every referenced field must be a declared field of the
collection, so untrusted input cannot widen or pivot the query.

The fixed search config travels as top-level query params; the per-visitor state
(`q`, `page`, `facets`) is read from Datastar's client signals (`readSignals()`),
which Datastar nests under a single `datastar` param, and falls back to the
top-level param on the no-JS path.

| Parameter | Source | Purpose |
| --- | --- | --- |
| `collection` | query param | The collection handle to search (required). |
| `queryBy` | query param | Comma-separated fields to match against. |
| `facetBy` | query param | The field to aggregate as the facet sidebar. |
| `perPage` | query param | Page size (capped at 100). |
| `q` | signal / query param | The query string (defaults to `*`, capped at 256 chars). |
| `page` | signal / query param | The page number. |
| `facets` | signal / query param | The selected facet values, turned into a `filter_by`. |
| `sort` | query param | A Typesense `sort_by` clause. |

The three regions are always top-level elements with the ids `#ts-results`,
`#ts-facets`, and `#ts-pagination`. A Datastar request receives them as
`datastar-patch-elements` SSE events (morphed by id); any other request receives
them as concatenated `text/html`.

## Render builders

| Builder | Returns |
| --- | --- |
| `craft.typesense.searchForm(config)` | The complete widget (form, input, facets, results, pagination). |
| `craft.typesense.results(config)` | Just the results region, for a custom layout. |
| `craft.typesense.facetList(config)` | Just the facet sidebar, for a custom layout. |
| `craft.typesense.searchEndpoint(config)` | The fragment endpoint URL with the fixed config (collection, queryBy, facetBy, perPage) baked in, for hand-rolled Datastar forms. |
| `craft.typesense.frontendSearch(handle, options)` | The raw fail-soft result array, for a fully custom page. |

Call `.render()` on a builder in Twig. `results()` and `facetList()` render
standalone regions; wrap them in your own `<form data-signals>` when composing a
layout by hand (see the `autocomplete.twig` example).

## Overriding the markup

The endpoint and builders render three partials from the plugin's `_typesense`
site template root:

- `_typesense/results`
- `_typesense/facets`
- `_typesense/pagination`

Override any of them by creating a same-named template in your project:

```
templates/_typesense/results.twig
templates/_typesense/facets.twig
templates/_typesense/pagination.twig
```

A project template wins over the plugin's. Each partial receives `result` (the
Typesense response), `options` (the normalised request), and `endpoint` (the
fragment URL). Keep the top-level element ids (`ts-results`, `ts-facets`,
`ts-pagination`) so Datastar can morph them.

## Progressive enhancement contract

Every demo works with JavaScript disabled:

- The widget is a real `<form method="get">`; submitting reloads the page with
  the query in the URL, and the page re-renders the full results server-side.
- Facet checkboxes carry `name="facets[]"`, and pagination prev/next are real
  crawlable `<a href>` links carrying the full query, so a no-JS submit or a
  crawler follows the full state.
- Datastar layers search-as-you-type (debounced `data-on:input`), live facet
  toggling, and in-place morphing on top, intercepting the submit only when
  present. Because the SSE patches morph the server-rendered seed in place, the
  first paint stays put: there is no layout shift and no skeleton flash.

Search results are fully server-rendered on first load, so they are indexable
and appear instantly without waiting for JavaScript.

## Caching (Blitz)

The demo pages are plain `GET` documents, so a static cache such as Blitz can
cache them normally. The fragment endpoint (`typesense/search/results`) is a
dynamic action that must run per request; exclude it from static caching (it is
an `actions/` URL, which Blitz already bypasses by default).

The unfiltered first render (the empty-query browse view) is a good candidate
for a short server-side TTL cache on the search result, so a burst of first
loads does not each hit Typesense. This is not wired in yet: a clean version
needs the sync engine to bust the key on reindex, which couples the front-end
cache to the sync lifecycle. It is tracked as a follow-up rather than shipped
with a staleness window on the demo. For now, rely on Blitz (or your CDN) at the
page level and Typesense's own speed at the query level.

## Datastar version

The example layout loads Datastar from a CDN, pinned:

```html
<script type="module" src="https://cdn.jsdelivr.net/gh/starfederation/datastar@v1.0.2/bundles/datastar.js"></script>
```

Host it yourself for production. The builders emit standard `data-*` attributes,
so any Datastar v1.x runtime works; pin a version you have tested against.
