---
title: Curation manager (Pro)
description: Authoring pins, hides, and boosts in the control panel, and quick-pinning from an entry
---
# Curation manager (Pro)

The Pro edition adds a control-panel manager for curation rules (Typesense
overrides): pin documents to the top of a query, hide documents, and boost or
filter results for specific searches. It sits over the same dual-shape curation
service the config file uses, so it works identically on Typesense v28/v29
(per-collection overrides) and v30.2+ (global curation sets).

## Editions and permissions

Curation is a Pro feature, gated by `typesense:manageCuration`. In the Free
edition the plugin renders none of the Pro control panel: no nav items, no
routes, no permissions. Every Pro action is edition-checked server-side, so a
crafted request cannot reach a Pro action on Free.

## Ownership

Ownership is presence-based. A collection that declares `curationRules()` in its
fluent config owns its curation: the plugin seeds those rules on collection
creation and the manager renders them read-only with a "declared in config"
notice. A collection that is silent about curation is control-panel-owned and
editable by anyone holding `typesense:manageCuration`. There is no ownership
setting. See `docs/synonyms.md` for the shared ownership model and the seeding
workflow.

## The rule editor

Pick a collection from `Typesense -> Curation`, then add or edit a rule. A rule
carries:

| Field | Meaning |
| --- | --- |
| Rule id | A stable identifier for the rule. |
| Query | The search query the rule matches. |
| Match | `exact` or `contains`. |
| Rule filter | An optional `filter_by` that scopes when the rule matches. |
| Includes (pins) | Document ids to pin, each at a 1-based position. |
| Excludes (hides) | Document ids to hide from the results. |
| Action: filter_by | A filter applied to matching queries. |
| Action: sort_by | A sort applied to matching queries. |
| Valid from / until | An optional effective window (any parseable date/time), stored as `effective_from_ts` / `effective_to_ts`. |

Every write refreshes an element-to-rule lookup index (the
`typesense_curation_index` table), so the plugin can answer "which rules pin this
element?" without scanning every rule. That index powers the entry sidebar
below.

## Quick-pin from an entry

The entry edit screen gains a Typesense curation panel in its sidebar. It lists
the pins that currently reference this element and offers a one-step pin: choose
a query and a position, and the plugin resolves the element's document id for the
collection, adds an include on the matching rule (creating the rule if none
matches the query), and updates the lookup index.

The panel is shown only when all of these hold:

- the plugin is running the Pro edition;
- the current user holds `typesense:manageCuration`;
- the element is a member of at least one control-panel-owned curation collection
  (one that does not declare its curation in config).

Otherwise it renders nothing (including in Free).
