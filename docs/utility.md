---
title: Control panel utility
description: The Typesense utility, drift, and alerting
---
# Control panel utility

The Typesense utility (Utilities, then Typesense) is the read-only operations
home in Free. It shows:

- **Server status:** the connected version, health, support state, and the
  capability list for that version.
- **Collections:** each collection's resolved Typesense target, multisite
  strategy, document count, and drift indicator.
- **Actions** (for users with the `typesense:manageSettings` permission): queue a
  full sync, flush and re-sync, or toggle the sync-suspend switch. The action
  buttons post to control-panel action URLs (built with `UrlHelper::actionUrl`),
  so they work regardless of how the site's base URL differs from the CP URL.

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
