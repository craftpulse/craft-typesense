---
title: API key management (Pro)
description: Creating and managing Typesense API keys, scoped-key profiles, and the Free/Pro key boundary
---
# API key management (Pro)

The Pro edition adds a control-panel screen for Typesense's API keys: create keys
with granular scopes and expiry, list the keys on the server, delete and rotate
them, and manage named scoped-key profiles for the front end.

## The Free/Pro boundary

Key handling splits cleanly across the editions:

- **Free derives** the security primitive: `craft.typesense.scopedSearchKey()`
  derives a narrow, expiring, filter-embedded search key from the search-only key
  entirely server-side. This ships in Free (see `docs/scoped-keys.md`).
- **Pro manages** the key estate: the CP screen creating, listing, deleting, and
  rotating server keys, and authoring the scoped-key profiles the Free helper
  references. This is a convenience layer on top of the Free primitive.

## Editions and permissions

The keys screen is a Pro feature, gated by `typesense:manageKeys`. In the Free
edition the plugin renders none of the Pro control panel. Every action is
edition-checked and permission-checked server-side, so a crafted request fails
closed.

## Creating a key

Choose the action scopes the key may use (a front-end search key needs only
`documents:search`), optionally restrict it to specific collections, and set an
optional expiry. The offered scopes include `documents:search`, `documents:get`,
`documents:*`, `collections:get`/`list`/`*`, `aliases:*`, `synonyms:*`,
`overrides:*`, `presets:*`, `analytics:*`, `analytics/events:create`, `keys:*`,
`operations/*`, `metrics.json:list`, and `*`.

## Show-once semantics

Typesense returns a key's full value **only once**, at creation. The plugin
surfaces it in a copy-this-now modal with an explicit "this will never be shown
again" warning, and **never stores it** anywhere: not in the database, not in
project config, not in the logs. After that screen the value is unrecoverable. If
you lose it, delete the key and create a new one. The key list thereafter shows
only the value prefix, exactly as Typesense itself exposes.

## Deleting and rotating

Deleting a key is guarded by a confirmation; the key stops working immediately
for any client using it.

Rotation is deliberately ordered "generate the replacement first": the Rotate
action prefills a new key with the old key's scopes, and only the created screen,
once you hold the new value, offers deleting the old key. You are never left
without a working key mid-rotation.

## Scoped-key profiles

A scoped-key profile is a reusable, named filter template (per site, per tenant,
per group) persisted to project config:

| Field | Purpose |
| --- | --- |
| Handle | The reference used in Twig. |
| Locked filter (`filter_by`) | The filter the derived key is locked to; the browser cannot widen it. |
| Include / exclude fields | Restrict which fields the key can return. |
| Expires in | Seconds from derivation until the key expires. |
| Limit hits | A per-key cap on the number of hits. |

Reference a profile from the Free derivation helper:

```twig
{% set key = craft.typesense.scopedSearchKey({ profile: 'tenant-a' }) %}
```

The plugin resolves the profile server-side, computing `expires_at` from the
window at call time. The profile's locked filter takes precedence over any ad-hoc
parameters passed alongside it, so a template call cannot widen the embedded
filter.
