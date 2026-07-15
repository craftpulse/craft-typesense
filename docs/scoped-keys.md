---
title: Scoped search keys
description: Deriving narrow, expiring front-end search keys
---
# Scoped search keys

Never put the admin API key (or an unrestricted search key) in front-end code.
Instead, derive a scoped search key: a narrow, expiring key with an embedded
filter that the browser cannot widen. Derivation is an HMAC over the search-only
key and happens entirely server-side (no round trip to Typesense).

## Setup

Set a **search-only API key** in the plugin settings (create one in Typesense
with only the `documents:search` action). The admin key stays server-side and is
never exposed to templates.

## In Twig

```twig
{% set searchKey = craft.typesense.scopedSearchKey({
    filter_by: 'siteId:=' ~ currentSite.id,
    expires_at: now.timestamp + 3600,
}) %}
```

Pass `searchKey` to your front-end search client. The embedded `filter_by` is
cryptographically enforced: the browser can add filters but cannot remove or
widen the embedded one.

## Embeddable parameters

| Parameter | Purpose |
| --- | --- |
| `filter_by` | A filter the key is locked to (for example a tenant or site). |
| `expires_at` | Unix timestamp after which the key stops working. |
| `include_fields` / `exclude_fields` | Restrict which fields the key can return. |
| `limit_hits` | Cap the number of hits (the only per-key rate lever Typesense offers). |
| `limit_multi_searches` | Cap the number of searches per multi-search. |
| `cache_ttl` | Server-side cache duration for the key's results. |

## Profiles (Pro)

In the Pro edition you can save named scoped-key profiles (per-site or per-tenant
filter templates) in the control panel and reference them by handle, so the
locked filter lives in one reviewable place instead of being repeated across
templates:

```twig
{% set searchKey = craft.typesense.scopedSearchKey({ profile: 'tenant-a' }) %}
```

The profile's locked filter takes precedence over any ad-hoc parameters. See
`docs/pro-keys.md`.

## From the console

```bash
php craft typesense/keys/generate-scoped --filter="siteId:=1" --expiresIn=3600 --limitHits=100
```

## Security posture

- The admin key is never exposed to templates or the front end.
- Scoped keys are derived from the search-only key; if the search-only key is
  deleted or rotated in Typesense, all derived scoped keys stop working.
- Front-end search is fail-soft: an unreachable server returns an empty,
  well-formed result rather than a 500.
