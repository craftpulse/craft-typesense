---
title: Front-end search (Datastar)
description: The fragment endpoint, render builders, and example templates
---
# Front-end search

The plugin ships a complete, progressively-enhanced front-end search built on
[Datastar](https://data-star.dev/) (pinned to **v1.0.2**), a ~10kb hypermedia
library that drives requests from `data-*` attributes and morphs server-rendered
HTML fragments into the page. There is no client-side templating and no build
step: the admin API key never approaches the browser, the search runs
server-side, and the whole thing works with JavaScript disabled.

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

`POST`/`GET` to the `typesense/search/results` action. It is anonymous (it
serves the public front end) and only ever reads. Every parameter is bounded in
the search service, so untrusted input cannot widen the query.

| Parameter | Purpose |
| --- | --- |
| `collection` | The collection handle to search (required). |
| `q` | The query string (defaults to `*`, capped at 256 chars). |
| `queryBy` | Comma-separated fields to match against. |
| `facetBy` | The field to aggregate as the facet sidebar. |
| `facets` | The selected facet values (array), turned into a `filter_by`. |
| `sort` | A Typesense `sort_by` clause. |
| `page` / `perPage` | Pagination (page size capped at 100). |

The response is `text/html` containing three top-level elements, `#ts-results`,
`#ts-facets`, and `#ts-pagination`, which Datastar morphs into the page by id.

## Render builders

| Builder | Returns |
| --- | --- |
| `craft.typesense.searchForm(config)` | The complete widget (form, input, facets, results, pagination). |
| `craft.typesense.results(config)` | Just the results region, for a custom layout. |
| `craft.typesense.facetList(config)` | Just the facet sidebar, for a custom layout. |
| `craft.typesense.searchEndpoint()` | The fragment endpoint URL, for hand-rolled Datastar forms. |
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
- Facet checkboxes carry `name="facets[]"` and pagination controls are real
  submit buttons, so a no-JS submit carries the full state.
- Datastar layers search-as-you-type (debounced `data-on:input`), live facet
  toggling, and morphing on top, intercepting the submit only when present.

Search results are fully server-rendered on first load, so they are indexable
and appear instantly without waiting for JavaScript.

## Datastar version

The example layout loads Datastar from a CDN, pinned:

```html
<script type="module" src="https://cdn.jsdelivr.net/gh/starfederation/datastar@v1.0.2/bundles/datastar.js"></script>
```

Host it yourself for production. The builders emit standard `data-*` attributes,
so any Datastar v1.x runtime works; pin a version you have tested against.
