---
title: Console commands
description: The typesense/* console command reference
---
# Console commands

## Sync

| Command | Does |
| --- | --- |
| `typesense/sync/all` | Queues a full sync of every collection. |
| `typesense/sync/collection <handle>` | Queues a full sync of one collection. |
| `typesense/sync/flush` | Queues a flush (delete and re-sync) of every collection. |
| `typesense/sync/refresh` | Queues reconciliation of every collection (removes orphaned documents). |
| `typesense/sync/suspend [collection]` | Suspends element sync globally, or for one collection when a handle is given (for bulk imports). |
| `typesense/sync/resume [collection]` | Resumes element sync globally, or for one collection. |

`typesense/default/sync` and `typesense/default/flush` remain as backwards
compatible aliases. Run `craft queue/run` (or a queue daemon) to process the
queued jobs.

## Config

| Command | Does |
| --- | --- |
| `typesense/config/diff` | Reports schema drift between the declared config and the live server. Exit code is non-zero when drift is found. |
| `typesense/config/migrate` | Generates a fluent config from legacy config. `--path` writes to a file. |
| `typesense/config/export <handle>` | Reverse-migrates a collection into a fluent config. |

## Schema

| Command | Does |
| --- | --- |
| `typesense/schema/apply` | Queues additive schema changes (add missing declared fields). Always queued and polled, never a blocking save, because a schema change blocks writes cluster-wide. |

## Keys

| Command | Does |
| --- | --- |
| `typesense/keys/generate-scoped` | Derives a scoped search key from the search-only key. Options: `--filter`, `--expiresIn` (seconds), `--limitHits`, `--limitMultiSearches`. |

## Inspect

| Command | Does |
| --- | --- |
| `typesense/inspect <elementId>` | Explains, for one element, which collections it belongs to, whether it is indexable, and the document it produces (or why it is skipped). |

## Backup

| Command | Does |
| --- | --- |
| `typesense/backup/export <handle>` | Exports a collection's documents as JSONL. `--path` writes to a file, otherwise stdout. |
| `typesense/backup/import <handle>` | Imports JSONL (upsert) from `--path` or stdin. |

The backup format is pipe-compatible with Typesense's own import, so
`typesense/backup/export jobs | ...` works.
