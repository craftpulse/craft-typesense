---
title: Custom templates and theming
description: Restyle and override the atomic front-end search components
---
# Custom templates and theming

The front-end search renders from small, atomic components. There are two ways to
change how it looks, from lightest to fullest control:

1. **Theme config** - override the class and attribute set of any component from
   a single setting, without copying a template.
2. **Template overrides** - copy any component template into your own directory
   and it wins, file by file, falling back to the plugin default for everything
   you do not copy.

Both are adapted from Formie's model. One deliberate divergence: Formie attaches
custom templates to a per-form template element, but this plugin uses a single
plugin-setting directory. That is the estate's fewer-moving-parts rule; a
per-collection template record can be layered on later if real demand appears.

## The atomic components

| Component | Template | Contract (main vars) | Region id |
| --- | --- | --- | --- |
| Results region | `results` | `result, urls, options, trackEndpoint, csrfToken, theme` | `#ts-results` |
| Result card | `_result-card` | `doc, href, clickUrl, csrfToken, theme` | - |
| Facets region | `facets` | `result, options, endpoint, theme` | `#ts-facets` |
| Facet item | `_facet-item` | `value, count, id, selected, endpoint, theme` | - |
| Pagination | `pagination` | `result, options, endpoint` | `#ts-pagination` |

The region templates loop and render the per-row atoms (`_result-card`,
`_facet-item`) through the resolver, so you can override just the card or the
item without touching the region.

## Theme config

Set `frontendThemeConfig` in `config/typesense.php` (or the plugin settings). It
is a map keyed by component; each entry may carry a `class`, an `attributes` map,
and a `resetClass` flag. A top-level `resetClasses` strips the structural default
classes everywhere.

```php
return [
    'frontendThemeConfig' => [
        // Add cosmetic classes on top of the structural defaults.
        'facetItem' => ['class' => 'chip chip--rounded'],
        // Replace the defaults entirely for one component.
        'resultCard' => ['class' => 'card', 'resetClass' => true],
        // Arbitrary attributes.
        'facetLabel' => ['attributes' => ['data-analytics' => 'facet']],
    ],
];
```

The split mirrors Formie: **structural** classes (layout, the `ts-*` hooks) live
in the templates and stay unless you reset; **cosmetic** classes come from the
config. Because the config is a plugin setting, it applies identically on the
first server render and on every SSE morph, so the styling never flips as the
shopper types.

### Theme keys

`results`, `resultCard`, `resultCardTitle`, `facets`, `facetItem`, `facetLabel`,
`facetCount`, `pagination`.

## Template overrides

Point `frontendTemplatesDir` at a site template directory. Any component file you
place there overrides the plugin's, file by file; everything else falls back to
the plugin default.

```php
return [
    'frontendTemplatesDir' => '_typesense-overrides',
];
```

```
templates/
  _typesense-overrides/
    _facet-item.twig   # only this component is overridden
```

Your `_facet-item.twig` receives the same variables the contract lists. Use
`craft.typesense.attr(theme, 'facetItem', 'your structural classes')` to keep the
theme-config layer working inside your override.

## The SSE contract (required in overridden regions)

The live morph patches three regions by id. If you override a **region** template
(`results`, `facets`, `pagination`), the top-level element MUST keep its id:

- `results` -> `id="ts-results"`
- `facets` -> `id="ts-facets"`
- `pagination` -> `id="ts-pagination"`

The client signals (`q`, `page`, `facets`) and the status signals (`tsFound`,
`tsMs`, `tsSearching`) are the rest of the contract; keep the `data-bind` and
`data-on` hooks if you want live updates.

If an overridden region renders without its required id, the endpoint discards
the override for that region, renders the plugin default instead so the morph
keeps working, and logs a warning naming the template and the missing id. Prefer
overriding the per-row atoms (`_result-card`, `_facet-item`), which carry no
contract, when you only need to restyle.
