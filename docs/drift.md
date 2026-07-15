---
title: Drift detection
description: Comparing the live Typesense state against your config
---
# Drift detection

Drift is any difference between what your config declares and what the live
Typesense server actually holds. It happens when someone edits a collection on
the server by hand, or when you change the config but have not re-synced yet.
The Drift service reports it so you are never guessing.

## What it compares

- **Schema**: declared fields versus the live collection's fields, per resolved
  target. It reports missing fields (declared but not live), extra fields (a
  manual server-side edit), and type mismatches.
- **Synonyms and curation**: for collections that own those features in config,
  declared rule IDs versus the live set. A live-only rule and a missing declared
  rule are both drift.
- **Preset**: the declared preset value versus the live preset.

## Statuses

| Status | Meaning |
| --- | --- |
| `inSync` | Declared and live match. |
| `drifted` | They differ (details name the fields or rules). |
| `missing` | The collection is declared but does not exist on the server. |

## From the console

```bash
php craft typesense/config/diff
```

This prints a finding per collection target and aspect. A non-zero exit means at
least one target has drifted, which makes it useful in a deploy check.

## In the control panel

The Typesense utility shows a per-collection drift indicator (in sync, drifted,
or missing) alongside document counts, so editors can spot a problem without the
console.

## Fixing drift

- Config changed, server behind: run `php craft typesense/schema/apply` (queued
  and polled) to rebuild the schema, then `php craft typesense/sync/all`.
- Manual server edit you want to keep: fold it back into your config so the two
  agree.
