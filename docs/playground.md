---
title: Search playground and document browser (Pro)
description: A live query console and a document viewer in the control panel
---
# Search playground and document browser (Pro)

The Pro edition adds two inspection surfaces that make the tuning tools usable:
a live search playground and a read-only document browser, one of each per
collection. Both are gated by the edition (hidden, not badged, in Free) and by
the `typesense:manageCollections` permission, since both tune and inspect a
collection. Open them from Typesense, Playground, then pick a collection.

Every query runs server-side through the search layer; the admin API key never
reaches the browser, and every parameter is bounded server-side.

Both surfaces open full-viewport, in the same split-pane shape as core's
GraphiQL explorer: the tunable parameters sit in a scrollable left pane, the
results in a scrollable right pane, with a header carrying the collection name,
a link to the other surface, and the primary action.

## Search playground

The query console runs a tuned query against the real server and shows the
ranked hits with their `_text_match` scores and `search_time_ms`. Run with the
Run button or Cmd/Ctrl+Enter. Each hit shows its rank, id, a score badge, and a
collapsible block of the document JSON. A stats bar reports the result count and
query time. The parameters are grouped into Query, Ranking, and Filtering:

- Query: `q`, `query_by`, `prefix`, `num_typos` (0 to 4), `drop_tokens_threshold`
- Ranking: `query_by_weights`, `sort_by`, `preset`
- Filtering: `filter_by`, `facet_by` (set it to see facet counts alongside the
  hits), `per_page` (capped at 250)

Parameters the connected server does not support are hidden rather than shown
disabled. At the supported server floor (v28) every parameter above is
available.

### Diff mode (preview pending edits)

Before you save a weight, preset, or curation change, preview its effect. Enter
the proposed weights or preset in the "Diff a pending edit" group and Diff: the
console runs the query twice, once against the current live state and once
against the pending overlay, and renders the two ranked lists side by side (each
with its own stats bar) plus a summary of which hits entered, dropped, or moved
rank. Nothing is saved; it is a pure preview.

## Document browser

The document browser is a read-only, paged view of the collection's actual
indexed documents. Pass a `filter_by` and `sort_by` to narrow and order the
page, and expand any row to read its document JSON inline. A stats bar shows the
live document count and the current page. Use it to confirm what a collection
actually holds, distinct from what a search returns.

## From an element index

In Pro, the "View in Typesense search" element action deep-links to the
playground for the element's collection, prefiltered to the element (an
`elementId` filter). In Free the same action degrades to guidance (the utility
and the `typesense/inspect` console command).
