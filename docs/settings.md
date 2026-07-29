---
title: Settings
description: Connecting Craft to Typesense and configuring plugin behaviour
---
# Settings

The settings screen lives in the plugin's own control-panel section at
**Typesense, then Settings**. Access is gated by the `typesense:manage-settings`
permission, so the screen can be delegated to a non-admin group. When
`allowAdminChanges` is off (typically production) the screen renders read-only
and any save is rejected.

Every connection and numeric value supports environment variables (type `$` in
the field to pick one), so you can point each environment at a different server.

## Connection

| Setting | Description |
| --- | --- |
| Server type | Single node, cluster, or Typesense Cloud. |
| Host / Port / Protocol | The single-node connection (server type Single node). |
| Cluster nodes / Cluster port | Semicolon-separated node hosts and their shared port (Cluster or Typesense Cloud). |
| Nearest node | The Typesense Cloud nearest-node host (Search Delivery Network). Cloud only. |
| Admin API key | The key used for indexing and management. Keep it out of the browser. |
| Search-only API key | The key that front-end scoped keys are derived from. |
| Collection prefix | Prepended to every collection name so several environments can share one cluster (for example `staging_`). |

## Resilience

| Setting | Default | Description |
| --- | --- | --- |
| Connection timeout (seconds) | 2 | How long to wait when opening a connection. |
| Health-check interval (seconds) | 60 | How long a node stays marked unhealthy before it is retried. |
| Retries | 3 | How many times a failed request is retried across nodes. |
| Retry interval (seconds) | 1 | The pause between retries. |

Front-end search is fail-soft: if the server is unreachable, a search returns a
well-formed empty result rather than raising a 500.

## Sync and queue

| Setting | Description |
| --- | --- |
| Queue priority | The priority assigned to Typesense sync jobs. Lower runs sooner. |

Suspend is no longer a setting. It is runtime state, so it lives in the Typesense
utility (global and per-collection) and on each collection's edit screen, and
works even when `allowAdminChanges` is disabled. See
[the sync engine](sync-engine.md#suspend).

## Feature ownership

There is no ownership setting. Two independent questions decide each relevance
feature (synonyms, curation, presets, stopwords):

- **Who can edit it?** A permission. See the permissions table in
  [collections](pro-collections.md#editions-and-permissions).
- **What is config-owned?** Config presence. If a collection declares the feature
  in its fluent config, that feature is config-owned and read-only in the control
  panel; if the config is silent, the feature is control-panel-owned and editable
  by anyone holding the matching permission. See
  [synonyms, curation and presets](synonyms.md#ownership).

## Analytics

Search analytics collection is opt-in and off by default. User-id association is
off by default; review your retention and privacy obligations before enabling.
