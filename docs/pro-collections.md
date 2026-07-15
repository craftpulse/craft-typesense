---
title: Collections cockpit (Pro)
description: Authoring Typesense collections in the control panel
---
# Collections cockpit (Pro)

The Pro edition adds a control-panel cockpit for authoring Typesense collections
without touching `config/typesense.php`. It sits alongside the config-file and
code-registered collections, and everything it authors persists to project
config, so it deploys and reviews like the rest of your configuration.

## Editions and permissions

The cockpit is a Pro feature. In the Free edition the plugin renders none of the
Pro control panel: no nav items, no routes, no permissions. Lower editions do
not badge Pro features, they hide them, and every Pro action is edition-checked
server-side, so a crafted request cannot reach a Pro action on Free.

Pro registers a granular permission set under the Typesense heading:

| Permission | Gates |
| --- | --- |
| `typesense:manageSettings` | The settings screen (Free and Pro). |
| `typesense:manageCollections` | The collections cockpit and mapping UI. |
| `typesense:manageCuration` | The curation manager. |
| `typesense:viewAnalytics` | The analytics dashboard. |

Nav items and screens key off both the edition and the matching permission.

## Two kinds of collections

A collection is owned by either the config file or the control panel:

| Owner | Authored in | Editable in the CP | Deploys via |
| --- | --- | --- | --- |
| Config | `config/typesense.php` (fluent builders) | No (read-only in the cockpit) | Your repo (the config file) |
| Control panel | The cockpit | Yes | Project config |

The cockpit browse screen lists CP-managed collections (create, edit, delete)
and, read-only below, the config-owned ones. When a config-file collection uses
the same name as a code-registered one, the config file wins and the collection
carries the "overridden by the config file" notice. The same notice appears if a
config collection displaces a CP-managed one: config always wins.

## Creating a collection

New collection, then:

- **Name**: the logical collection name (letters, numbers, underscores, hyphens).
- **Element source**: the element type and source to index, across every native
  type that has a field layout (entries, categories, assets, tags, globals,
  users) and Commerce products when Commerce is installed.
- **Multisite strategy**: shared with a site filter (one collection, a `siteId`
  field per document), or one collection per site. See [Multi-site](multisite.md).
- **Enabled**: whether the collection syncs.

Save, then map its fields (see [Field mapping](mapping.md)). A CP-managed
collection is an ordinary runtime collection: it flows through the same sync
engine, drift detection, control-panel utility, and search layer as a config
collection, with no special-casing.

## Where it lives

CP-managed collection definitions (name, source, multisite, per-field mappings
keyed by field UID, and metadata) live in project config under
`typesense.managedCollections`, keyed by UID. That makes them deployable and
reviewable: author in dev, commit the project-config change, deploy, and
`craft up` applies it, exactly like sections and fields. See the architecture
note on settings belonging in project config.

## Multi-site document identity

For a `sharedWithSiteFilter` collection that spans more than one site, each
site's document gets a composite id (`{elementId}-{siteId}`) so the per-site
documents never collide. A single-site scope and `collectionPerSite` keep the
bare element id. Deletion removes every document of an element regardless of the
id shape.
