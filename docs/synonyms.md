---
title: Synonyms, curation and presets
description: Config-declared relevance tuning, dual-shape across Typesense versions
---
# Synonyms, curation and presets

Three relevance levers ship as services with one public interface each, working
identically whether you run Typesense v28/v29 (per-collection APIs through the
bundled client) or v30.2+ (global sets through raw HTTP). The plugin chooses the
shape from the detected server version; you never pick.

## Ownership

Ownership is presence-based, decided per collection per feature. There is no
ownership setting and no per-collection ownership override. Two independent
questions apply:

- **Permissions decide who can edit.** The synonyms, curation, and dictionaries
  managers each have their own permission (`typesense:manage-synonyms`,
  `typesense:manage-curation`, `typesense:manage-dictionaries`). See the
  [permissions table](pro-collections.md#editions-and-permissions).
- **Config presence decides what is config-owned.** If a collection declares the
  feature in its fluent config, that feature is config-owned: the plugin seeds it
  when the collection is created and the control panel renders it read-only with a
  "declared in config" notice. If the config is silent about a feature, that
  feature is control-panel-owned and editable by anyone holding the matching
  permission.

| State | Meaning |
| --- | --- |
| Declared in config | Config-owned: seeded on collection creation, read-only in the control panel. |
| Silent in config | Control-panel-owned: editable in the manager (Pro), never seeded. |

### Seeding config-owned features

Config-owned synonyms, curation rules, presets, and stopwords are seeded the
first time a collection is created, which happens on `typesense/schema/apply` (or
the first `typesense/sync`). To re-seed after editing the config, re-apply the
schema. To hand a feature to editors, remove it from the config and use
`typesense/config/export <handle>` to capture the current server-side state into
a fluent config first if you want to keep it.

## Synonyms

```php
use craftpulse\typesense\builders\Collection;

Collection::make('products')
    ->synonymDefinitions([
        ['id' => 'outerwear', 'synonyms' => ['blazer', 'coat', 'jacket']],
        ['id' => 'trousers', 'root' => 'trousers', 'synonyms' => ['pants', 'slacks']],
    ]);
```

Declaring `synonymDefinitions()` makes synonyms config-owned for this collection;
they are seeded when the collection is created. On v30.2+ they are written as a
global synonym set named `<collection>_synonyms`, and the search layer passes
`synonym_sets=<name>` automatically. Omit `synonymDefinitions()` to leave synonyms
control-panel-owned.

## Curation

```php
Collection::make('products')
    ->curationRules([
        [
            'id' => 'pin-coat',
            'rule' => ['query' => 'coat', 'match' => 'exact'],
            'includes' => [['id' => '42', 'position' => 1]],
        ],
    ]);
```

Declaring `curationRules()` makes curation config-owned for this collection; omit
it to leave curation control-panel-owned.

## Presets

A search preset captures query defaults (typo tolerance, `prefix`, `drop_tokens`,
and so on) once, so every search stays consistent. The declared preset is mirrored
into a Typesense preset and passed by name on every search.

```php
Collection::make('products')
    ->preset([
        'query_by' => 'title,body',
        'num_typos' => 2,
        'drop_tokens_threshold' => 1,
    ]);
```

## Drift

`typesense/config/diff` and the utility drift panel compare declared synonyms,
curation rules, and the preset against the live server for collections that own
those features in config. A live-only rule (a manual server-side edit) and a
missing declared rule are both reported as drift.
