---
title: Synonyms, curation and presets
description: Config-managed relevance tuning, dual-shape across Typesense versions
---
# Synonyms, curation and presets

Three relevance levers ship as services with one public interface each, working
identically whether you run Typesense v28/v29 (per-collection APIs through the
bundled client) or v30.2+ (global sets through raw HTTP). The plugin chooses the
shape from the detected server version; you never pick.

## Ownership

Each lever has an owner: the config file or the control panel. The default comes
from the plugin settings (`synonymsManagedBy`, `curationManagedBy`), and a
collection can override it in fluent config. Config always wins over the settings
default, and an overridden collection surfaces the "managed by the config file"
notice in the control panel.

| Owner | Meaning |
| --- | --- |
| `config` | Declared in the config file and seeded on collection creation. The control panel is read-only for this collection. |
| `cp` | Owned by the control-panel manager (Pro). The config declaration is ignored. |

## Synonyms

```php
use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\models\Settings;

Collection::make('products')
    ->synonyms(Settings::MANAGED_BY_CONFIG)
    ->synonymDefinitions([
        ['id' => 'outerwear', 'synonyms' => ['blazer', 'coat', 'jacket']],
        ['id' => 'trousers', 'root' => 'trousers', 'synonyms' => ['pants', 'slacks']],
    ]);
```

Config-managed synonyms are seeded when the collection is created. On v30.2+ they
are written as a global synonym set named `<collection>_synonyms`, and the search
layer passes `synonym_sets=<name>` automatically.

## Curation

```php
Collection::make('products')
    ->curation(Settings::MANAGED_BY_CONFIG)
    ->curationRules([
        [
            'id' => 'pin-coat',
            'rule' => ['query' => 'coat', 'match' => 'exact'],
            'includes' => [['id' => '42', 'position' => 1]],
        ],
    ]);
```

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
curation rules, and the preset against the live server for config-managed
collections. A live-only rule (a manual server-side edit) and a missing declared
rule are both reported as drift.
