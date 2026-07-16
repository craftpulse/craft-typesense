---
title: Aliases and zero-downtime rebuilds (Pro)
description: Zero-downtime reindexing through aliases, and collection cloning
---
# Aliases and zero-downtime rebuilds (Pro)

The Pro edition adds an aliases manager for zero-downtime reindexing. An alias is
a stable logical name pointing at a physical (timestamped) collection; front-end
and search code always reference the logical name, so the physical collection
behind it can be rebuilt and swapped without a search ever seeing downtime.

## Editions and permissions

The aliases manager is a Pro feature, gated by `typesense:manageCollections`. In
the Free edition the plugin renders none of the Pro control panel. Every action
is edition-checked server-side.

## Zero-downtime rebuild

A rebuild:

1. builds a fresh physical collection (`<logical>_<timestamp>`) from the current
   declared schema;
2. copies the documents into it (server-side export then import);
3. atomically swaps the alias to point at the new physical collection;
4. drops the old physical collection.

Because the alias swap is atomic and the new collection is fully populated before
the swap, the logical name resolves to a queryable collection at every step. The
first rebuild of a plain (non-alias) collection converts it to the alias pattern.

The aliases screen lists one row per collection: the collection name, a copyable
Query name (the name a front end should hit, alias-aware), the physical
collection currently serving it (also copyable), and when it was last built. Both
copyable chips use Craft's copy-text button.

Rebuilds always run on the queue, never as a blocking request: a schema change
applied in place blocks writes cluster-wide, so the plugin never does that.
Trigger a rebuild from the aliases screen or the console:

```bash
php craft typesense/schema/rebuild            # every collection
php craft typesense/schema/rebuild jobs        # one collection
```

For additive field changes that do not need a full rebuild, `typesense/schema/apply`
queues an in-place, polled update instead.

## Collection cloning

Cloning copies a collection's schema (not its documents) into a new collection
using Typesense's `src_name`. It is capability-gated: it appears and works only on
servers new enough to support it (the plugin refuses the intermediate versions
where the feature is unstable), and is hidden entirely below that (hide, never
badge).
