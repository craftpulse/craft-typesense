---
title: Collections cockpit (Pro)
description: Authoring Typesense collections in the control panel
---
# Collections cockpit (Pro)

The Pro edition adds a control-panel cockpit for authoring Typesense collections
without touching `config/typesense.php`. It sits alongside the config-file and
code-registered collections, and everything it authors persists to project
config, so it deploys and reviews like the rest of your configuration.

The cockpit lists collections across two tabs, CP managed and Config managed.
Each row carries a copyable "Typesense collection" chip: the exact name a front
end should query. It is alias-aware, showing the logical (alias) name when the
collection sits behind an alias and the physical name otherwise, which in both
cases is the name Typesense answers to. Copy it to query the search server
directly (bypassing this plugin's search layer) when you roll your own front end.

## Editions and permissions

The cockpit is a Pro feature. In the Free edition the plugin renders none of the
Pro control panel: no nav items, no routes, no permissions. Lower editions do
not badge Pro features, they hide them, and every Pro action is edition-checked
server-side, so a crafted request cannot reach a Pro action on Free.

Pro registers a granular permission set under the single Typesense heading, one
handle per screen family (no umbrellas): a user granted one handle reaches only
that family, nothing else.

| Permission | Gates |
| --- | --- |
| `typesense:manageSettings` | The settings screen (Free and Pro). |
| `typesense:manageCollections` | The collections cockpit and the mapping UI (including the per-collection suspend toggle on the collection edit screen). |
| `typesense:manageRelevance` | The relevance and vector/AI editor, and conversation (RAG) models. |
| `typesense:manageSynonyms` | The synonyms manager. |
| `typesense:manageCuration` | The curation manager. |
| `typesense:manageDictionaries` | The stopwords and stemming-dictionary manager. |
| `typesense:manageAliases` | The aliases manager, zero-downtime rebuilds, and cloning. |
| `typesense:manageExperiments` | The A/B experiments composition screen. |
| `typesense:manageKeys` | The API keys manager and scoped-key profiles. |
| `typesense:viewAnalytics` | The analytics dashboard. |
| `typesense:viewDiagnostics` | The search playground (including its docs-explorer document browser) and the element-edit inspector sidebar. |
| `typesense:manageOps` | The Typesense utility's operational actions (sync, flush, suspend globally or per collection) and the Pro index-lifecycle ops (clear cache, compact, snapshot). |

`typesense:manageSettings` is registered in every edition; the rest are Pro-only
(hidden in Free, never badged). Nav items, routes, and server-side action checks
all key off both the edition and the matching permission.

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

A new collection shows only the Settings tab. Save it, and the collection edit
screen gains its remaining tabs (each with its own URL and permission gate):

- **Settings**: the fields above (`typesense:manageCollections`).
- **Mapping**: the field layout designer (`typesense:manageCollections`). See
  [Field mapping](mapping.md).
- **Relevance**: search preset, boosts, grouping, buckets, diversification
  (`typesense:manageRelevance`). See [Relevance tuning](pro-relevance.md).
- **Vector / AI**: auto-embedding and the experimental AI features
  (`typesense:manageRelevance`). See [Vector and AI](pro-vector-ai.md).
- **Synonyms**: one-way and multi-way synonyms (`typesense:manageSynonyms`). See
  [Synonyms manager](pro-synonyms.md).
- **Curation**: pin, hide, and boost rules (`typesense:manageCuration`). See
  [Curation manager](pro-curation.md).

Stopwords and stemming dictionaries are server-global resources, so they stay on
the top-level Dictionaries screen, not on a collection. A tab the viewer's
permissions do not cover is not shown. A CP-managed collection
is an ordinary runtime collection: it flows through the same sync engine, drift
detection, control-panel utility, and search layer as a config collection, with
no special-casing.

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
