---
title: Control panel utility
description: The Typesense utility, drift, and alerting
---
# Control panel utility

The Typesense utility (Utilities, then Typesense) is the operations home. It is
shaped after Craft's own utilities: each section leads with a heading, surfaces
state as a data table, then offers one action per block with a description above
a solid button. It shows:

- **Server status:** the connected version, health, support state, and the
  per-capability availability table for that version.
- **Collections:** one row per declared collection with its resolved Typesense
  target, document count, schema-drift indicator, last sync time, and sync state.
  A per-row action menu (the same `disclosureMenu` row-action idiom as the
  aliases and API-keys screens) queues **Sync**, **Apply schema**, **Suspend /
  Resume sync**, or **Flush** for that single collection.
- **Global operations** (for users with the `typesense:manage-ops` permission):
  queue a sync of every collection, toggle the global sync-suspend master switch,
  or flush every collection. Every action posts to a control-panel action URL
  (built with `UrlHelper::actionUrl`), so it works regardless of how the site's
  base URL differs from the CP URL. Destructive actions (flush) confirm first.
  Suspend is runtime database state (see
  [the sync engine](sync-engine.md#suspend)), so the toggles work even when
  `allowAdminChanges` is disabled.
- **Server operations** (Pro, `typesense:manage-ops`): the server memory, disk,
  and CPU readout, plus database compaction, query-cache clearing, and snapshots.

### Apply schema vs. rebuild

The per-collection **Apply schema** action queues an additive schema apply: it
creates the collection if it is missing and adds any newly declared fields. It
never rewrites documents. Because Typesense re-creates a collection to change a
field's type (there is no in-place type change), a structural change is not
applied here — use the zero-downtime **Rebuild** on the
[Aliases screen](pro-aliases.md) for that.

## Drift

The utility surfaces a per-collection drift indicator (in sync, drifted, or
missing). For the full report, run `craft typesense/config/diff`, which detects
both a manual server-side schema edit and a config change. Apply additive schema
changes with `craft typesense/schema/apply`.

## Sync-failure alerts

Sync-failure email alerts are off by default. Enable them in the plugin settings
(Analytics and alerts) and set an alert address. When a Typesense sync job fails
repeatedly, or the server is unreachable, one email is sent per failure context
(throttled), so a burst of failures does not flood the inbox.

## Element actions

Entries, categories, assets, and Commerce products (when installed) gain a
"Reindex in Typesense" bulk action in their element indexes.
