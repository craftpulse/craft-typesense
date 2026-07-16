---
title: Upgrading to 5.9.0
description: What changes when you update craft-typesense from 5.8.x to 5.9.0
---
# Upgrading to 5.9.0

5.9.0 is a rebuild of the plugin's internals. For existing installs it is a
zero-touch update: run `craft up` as usual. This guide explains what changes and
the optional follow-ups.

## Zero-touch update

```bash
composer update craftpulse/craft-typesense
php craft up
```

`craft up` runs the update migrations. Your settings, synonyms, project-config
keys, and search behavior are preserved.

## What changes

### Namespace

The internal PHP namespace moved from `percipiolondon\typesense` to
`craftpulse\typesense`. The Composer package name (`craftpulse/craft-typesense`)
and the plugin handle (`typesense`) are unchanged, so project config,
permissions, and settings survive.

If your own code references plugin classes directly, update the namespace. A
backwards-compatibility autoloader (a Composer `files`-loaded fallback in
`src/bootstrap.php`) keeps legacy `percipiolondon\typesense\*` class names working
in the meantime: it logs a Craft deprecation naming the old and new class, then
aliases the old name to the new one. This covers every place a legacy name
survives the upgrade:

- `config/typesense.php` references and custom-module `Event::on(percipiolondon\...)`
  handlers and type hints.
- Class strings Craft persisted (a dashboard `HealthWidget`, the mapping
  field-layout element classes in project config). The field-layout classes are
  also permanently rewritten to the new namespace by a migration on `craft up`, so
  that stored data no longer depends on the shim.
- Serialized queue payloads already in the database, which unserialize and run
  through the alias.

**Deprecation timeline.** The shim is a bridge, not a permanent contract: legacy
`percipiolondon\typesense\*` names are deprecated as of 5.9.0 and the shim will be
removed in a future major version. Update your references (and re-save any
CP-managed collections) to clear the deprecation warnings.

### Config files keep working

Existing `config/typesense.php` files that use
`percipiolondon\typesense\TypesenseCollectionIndex` keep working through a
compatibility shim, and now run on the new sync engine (chunked, multisite-aware,
with correct status-to-delete handling). Each legacy config is deprecator-logged.

To move to the new fluent builders, generate a starting point:

```bash
php craft typesense/config/migrate --path=config/typesense.generated.php
```

The generator emits the schema deterministically and copies your resolver body
verbatim with `REVIEW` markers. Review the `elementQuery` and `transform`
closures, then replace your config file.

### Documents gain two reserved fields

Every document now carries `elementId` and `siteId`, used for reliable deletion
and multisite filtering. Your existing fields are unchanged. Collections keep
their names and document counts.

### The vestigial collections table is dropped

5.8.x created a `typesense_collections` table that was never used. The update
drops it. The synonyms table and its rows are untouched.

## Cockpit users

If you run the Cockpit companion plugin, update it to its 5.9.0 companion release
so it re-registers its collections through the new registration events. Until you
do, the control panel shows a non-blocking warning and search keeps working.

## Reverse migration

To turn a collection back into a fluent config file (for example to move a
control-panel-managed collection into version control):

```bash
php craft typesense/config/export <handle> --path=config/typesense.generated.php
```
