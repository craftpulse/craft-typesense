---
title: Relevance tuning (Pro)
description: Boost rules, search presets, buckets, grouping, and diversification in the control panel
---
# Relevance tuning (Pro)

The Pro edition adds a Relevance tab to each control-panel-managed collection's
edit screen (Typesense, then Collections, then a collection, then the Relevance
tab). Config-file collections tune relevance in fluent config (the `preset()`
builder); the tab is for CP-managed collections.

## Editions and permissions

Relevance tuning is a Pro feature, gated by `typesense:manage-relevance`. Holding
that handle also reveals the collection's Vector / AI tab; the Settings and
Mapping tabs are gated separately by `typesense:manage-collections`. In the Free
edition the plugin renders none of the Pro control panel. Every Pro action is
edition-checked server-side.

## Additive boost rules (baked to `boost_score`)

Typesense's `_eval` boosting is first-match-wins (an upstream bug across v27 to
v30.1): only the first matching condition applies, so additive boosting is
unreliable. The plugin sidesteps this by compiling boost rules to an indexed
`boost_score` field baked into each document at index time. A document's score is
the sum of the weights of every rule it matches, so ranking is deterministic and
testable.

Each rule in the editor is a single condition (field or attribute, operator,
value) and a weight. The compiled collection gains a sortable `boost_score`
field, and its preset sorts by text match first, then by `boost_score` (so boosts
break ties within a relevance tier, never override a better textual match).
Multi-condition rules remain a fluent-config capability.

Because the score is baked at index time, **changing boost rules requires
re-syncing the collection** to take effect. The editor surfaces this as a
note-warning.

Raw single-condition `_eval` boosting is still available in fluent config for
callers who want it; the condition builder here translates to the deterministic
baked path.

## Search preset

The editor writes raw Typesense preset parameters (`num_typos`, `prefix`,
`drop_tokens_threshold`, `typo_tokens_threshold`, `min_len_1typo`,
`min_len_2typo`) that the search layer passes with every query against the
collection. Blank fields fall back to the server default.

## Result grouping

Set a `group_by` field (and an optional `group_limit`) to collapse results that
share a value, for example variant collapse on a product collection.

## Version-gated controls

Two controls appear only when the detected server supports them (hide, never
badge):

| Control | Server | Effect |
| --- | --- | --- |
| Text-match buckets | v28+ | Buckets text-match scores into N tiers (`_text_match(buckets: N)`) so a secondary sort (a boost) can reorder within a tier. |
| MMR diversification | v30.2+ | Maximal Marginal Relevance (`mmr`, `mmr_lambda`) diversifies results (requires vector search). |

The compiler only emits these preset parameters when the server understands
them, so a downgraded server never receives a parameter it would reject.

## Previewing before you commit

The editor links into the search playground's diff mode (the link carries a
`#diff` fragment, so the Diff drawer opens automatically on arrival with the
collection already selected). It previews a pending overlay of weight and preset
changes against the current ranking (entered, dropped, moved) before anything is
saved. See `docs/playground.md`.

## Stopword set

The Relevance section can point the collection at a server stopword set. The
chosen set is applied as the collection's default `stopwords` search parameter
(through its mirrored preset), so those words are dropped from every query
against the collection. Stopword sets are server-global (managed under
Dictionaries), so several collections can share one. See
[stopwords](https://typesense.org/docs/30.2/api/stopwords.html).
