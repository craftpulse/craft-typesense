---
title: Search playground (Pro)
description: One GraphiQL-style query, diff, and document console in the control panel
---
# Search playground (Pro)

The Pro edition adds one search playground: a GraphiQL-style console, the
analogue of Craft's own GraphQL explorer. It is gated by the edition (hidden, not
badged, in Free) and by `typesense:viewDiagnostics`. It sits at the bottom of the
Typesense subnav, directly above Settings. Every query runs server-side through
the search layer, so the admin API key never reaches the browser and the result
payload is bounded.

## The one screen

Craft's chrome header carries a collection picker (the analogue of GraphiQL's
schema dropdown) and the Run button. Switching the picker opens that collection.
The body is a split of three panes:

- **Request editor (left).** A JSON editor (monospace, with a line-number gutter)
  holding the full Typesense search-params body, prefilled with a sensible
  default (`q`, `query_by` from the collection's first string field, `per_page`).
  Edit any parameter from the [search API reference](https://typesense.org/docs/30.2/api/search.html).
  Run with the header Run button or Cmd/Ctrl+Enter.
- **Response (right).** The ranked hits with their `_text_match` scores and
  `search_time_ms`: a stats bar (result count and query time), each hit with its
  rank, id, a score badge, and a collapsible document JSON block. Facet counts
  appear when the request sets `facet_by`.
- **Docs explorer (side).** The collection's live schema (field names and types)
  and a pageable, filterable document browser (pass a `filter_by`), each row's
  JSON expanding inline. This is where you confirm what a collection actually
  holds, distinct from what a search returns.

### Diff a pending edit

Before you save a weight or preset change, preview its effect. Open the Diff
drawer under the editor, enter an overlay of proposed parameters, and Run diff:
the console runs the query twice (current versus the current request merged with
the overlay) and renders the two ranked lists side by side, plus a summary of
which hits entered, dropped, or moved rank. Nothing is saved. The relevance
section links here with the collection preselected.

## From an element index

In Pro, the "View in Typesense search" element action deep-links to the
playground for the element's collection, prefiltered to the element (an
`elementId` filter). In Free the same action degrades to guidance (the utility
and the `typesense/inspect` console command).
