---
title: Analytics and ops (Pro)
description: Search analytics, the dashboard, GDPR posture, event tracking, A/B comparison, and the ops cockpit
---
# Analytics and ops (Pro)

The Pro edition adds a search analytics dashboard, a front-end event helper, an
A/B comparison, and an operations cockpit.

## Editions and permissions

The analytics dashboard is gated by `typesense:viewAnalytics`; the ops actions by
`typesense:manageCollections`. Reading server metrics is Free (it appears in the
utility). Every Pro action is edition-checked server-side.

## Enabling analytics

Analytics is opt-in on both sides:

1. **The server** must be started with `--enable-search-analytics=true` (and an
   analytics flush interval). The dashboard warns when it is not.
2. **The plugin** must opt in: set the analytics setting on (it defaults off).

Nothing is collected until both are true.

## Rules and destinations

Analytics rules aggregate queries into destination collections:

| Rule type | Records |
| --- | --- |
| `popular_queries` | The most frequent queries. |
| `nohits_queries` | Queries that returned nothing. |
| `counter` | Increments a document field on events. |
| `log` | Raw event logging. |

The dashboard's "set up recommended rules" button creates a popular-queries and a
no-hits rule (and their `q`/`count` destination collections) for every declared
collection. The readouts show the top queries and counts per rule.

## GDPR posture

The dashboard surfaces the privacy posture directly:

- **Opt-in:** analytics collection is off until you enable it.
- **User-id association is off by default:** no per-user data is collected unless
  you opt into it. When off, the event helper strips any `user_id`.
- **Retention:** set retention guidance in the settings; Typesense keeps aggregate
  counts until overwritten, so document your own retention policy.

## Front-end event helper

Post click and conversion events from the front end without exposing any key:

```twig
<button
    data-on-click="@post('{{ craft.typesense.eventEndpoint() }}', { body: { type: 'click', name: 'heroes_click', docId: docId, q: query } })"
>{{ hit.title }}</button>
```

The endpoint (`typesense/search/track-event`) records the event server-side. Only
`click` and `conversion` types are accepted from the front end. The Datastar
wiring above is documented here; wiring it into the shipped example templates is a
later polish step.

## A/B comparison

When the server supports analytics tags (v29+), the dashboard shows an A/B section
listing each experiment's variants and their analytics tags. Each variant's
searches carry its tag (see `docs/pro-ab-testing.md`), so the tagged volumes
attribute per variant. On servers without analytics tags the section is hidden.

## Ops cockpit

The Typesense utility shows the server's memory, disk, and CPU from
`/metrics.json` (Free, read-only). In Pro, a user with `typesense:manageCollections`
also gets operational actions:

- **Compact database:** compacts the on-disk database (non-blocking).
- **Clear cache:** clears the server's query cache.
- **Take snapshot:** writes a point-in-time snapshot to a path on the server.

These actions are edition- and permission-checked server-side.
