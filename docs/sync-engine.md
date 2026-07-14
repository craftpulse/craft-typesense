---
title: Sync engine
description: How the plugin keeps Typesense in step with Craft elements
---
# Sync engine

The sync engine keeps Typesense documents in step with Craft elements through
incremental event-driven updates and full re-syncs, all on the queue.

## Listeners

On boot the plugin listens for element save, restore, delete, and structure move.
Each handler is gated: it does nothing unless the client is configured and sync
is not suspended, and it ignores drafts and revisions. For a saved element it
queues an index for every collection whose element query the element matches, and
a delete for collections it no longer matches (for example after a section
change). Deletes queue a document removal from every matching collection.

## Batching and debounce

Index and delete work is accumulated per collection and site and flushed once at
the end of the request, so a save that propagates across sites, or a bulk
operation, produces one job per collection and site rather than one per element.

## Full sync and Cloud-safe slicing

A full sync queues one `SyncCollection` job per site. The job walks the element
query in page-sized slices (`pageSize` on the collection), building documents and
importing them in batches, keeping memory flat. If a job approaches the Craft
Cloud 15-minute cap it re-queues a continuation slice from its cursor, so a large
sync completes across multiple job runs. Progress is reported to the CP queue
manager.

## Reconciliation

Reconciliation replaces the legacy export-diff-delete. It compares the documents
currently in a collection against the element IDs the collection's query returns
for active elements, and deletes any document whose source element is no longer an
active member. A full sync queues reconciliation automatically when it finishes.

## Status to delete

An element that leaves its active statuses (by default `live` or `enabled`, or a
per-collection `activeStatuses` list) produces no documents, which the engine
treats as a delete: its documents are removed rather than left stale. Deletion
targets the reserved `elementId` field, so it removes single and multiple
documents per element alike.

## Dependencies

A transform receives a `registerDependency` callback. Registering a related
element records a dependency, and when that related element later changes, every
source element that depends on it is re-indexed.

## Suspend

The global suspend switch (a setting) stops all event-driven and full-sync work
while it is on. Use it during bulk imports (for example Feed Me), then run a full
sync afterward. Console and utility toggles for suspend arrive in a later phase.

## Multisite strategies

- `collectionPerSite`: one Typesense collection per site; the collection name is
  suffixed with the site handle.
- `sharedWithSiteFilter`: one shared collection; every document carries a
  `siteId` field that searches filter on.

Both are wired through the registry's name resolver, so the environment prefix
and the per-site suffix are applied in one place.

## Queue priority

Every job is pushed at the configured queue priority.
