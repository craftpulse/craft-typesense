---
title: Backup and restore
description: Exporting and importing collection documents as JSONL
---
# Backup and restore

Export a collection's documents to JSONL and import them back. The format is the
same JSONL Typesense uses for its own import API, so backups are portable across
servers and pipe-compatible with `curl`.

## Export

Print a collection's documents to stdout:

```bash
php craft typesense/backup/export products
```

Write them to a file with `--path`:

```bash
php craft typesense/backup/export products --path=storage/backups/products.jsonl
```

## Import

Import upserts documents (existing IDs are overwritten, new IDs added). Read from
a file with `--path`, or pipe JSONL in on stdin:

```bash
php craft typesense/backup/import products --path=storage/backups/products.jsonl

cat products.jsonl | php craft typesense/backup/import products
```

## Notes

- Import does not create the collection. Ensure the schema exists first (a normal
  sync, or `php craft typesense/schema/apply`).
- Backups capture documents, not synonyms, curation, or presets. Those are config
  managed (see [Synonyms, curation and presets](synonyms.md)) and reseed on
  collection creation.
- For a full rebuild from Craft content, prefer `php craft typesense/sync/all`
  over an import; backups are for point-in-time snapshots and cross-server moves.
