---
title: Field mapping (Pro)
description: Mapping element fields to Typesense in the control panel
---
# Field mapping (Pro)

The mapping screen is where a control-panel-managed collection decides how each
element field lands in Typesense. It is a real Craft field layout: the collection
owns a `craft\models\FieldLayout`, edited with the genuine
`Craft.FieldLayoutDesigner`, and serialised into project config exactly like an
entry type. Placing a field in the layout indexes it; opening the field's
slideout tunes its Typesense mapping.

Open it from the Mapping tab of a saved collection's edit screen.

## The palette

The designer's field library is scoped to the collection's source (through
`FieldLayout::EVENT_DEFINE_CUSTOM_FIELDS` and `EVENT_DEFINE_NATIVE_FIELDS`, both
keyed on the layout's provider so ordinary entry-type layouts are untouched):

- each custom field in the element source's field layout (for entries, the union
  of the section's entry types), as the custom-field palette;
- for asset sources, the file-derived pseudo fields (filename, kind, size,
  width, height) as native fields;
- for Commerce product sources, the variant fields when Commerce is installed;
- every registered computed field (see [Extending](extending.md)) as a native
  field.

Drag a field into the layout to index it; drag it back out to stop indexing it.

## Server-authoritative derivation

The server, not the browser, decides two things: the Typesense type a field
derives to (through the Schema service, extensible with
`Schema::EVENT_DEFINE_TYPE_MAP`) and which controls apply to that type. A field's
slideout only offers the controls the server allows for the field, so you cannot
map a field to something the server would reject.

## The controls

A field's slideout reveals the applicable controls:

| Control | Applies to | Effect |
| --- | --- | --- |
| (placement) | all | Placing the field in the layout indexes it (there is no separate "Indexed" toggle). |
| Facet | string, string[], int, bool | Aggregate the field for faceted browsing. |
| Sortable | string, numeric, bool | Allow sorting on the field. |
| Search weight | string, string[] | The field's `query_by_weights` weight, compiled into the collection preset. |
| Infix search | string, string[] | Enable infix (mid-word) matching. |
| Stemming | string, string[] | Enable stemming. |
| Locale | string, string[] | The stemming/tokenisation locale. |
| Type override | all | Change the field's type within a bounded, server-approved set. |
| Index nested fields | object, object[] | Index the object's nested fields. |
| Embed image | asset sources | Auto-embed the asset image with CLIP for image search (see `docs/pro-vector-ai.md`). |
| Description | all | A natural-language description of the field. |

On Save, the whole field layout (each element carrying its mapping settings) is
assembled from the designer post and persisted into the collection definition in
project config, exactly as an entry type persists its field layout.

## Natural-language descriptions

The per-field Description feeds natural-language search: descriptions compile
into the collection's `metadata.field_descriptions`, which tells Typesense's
natural-language query models what each field means. Descriptions are optional
and have no effect on plain keyword search.

## How a mapping becomes documents

Saving a mapping does not talk to Typesense directly. The definition compiles
into a runtime collection: the schema (derived types plus your overrides, facet,
sort, infix, stem, locale, with every mapped field optional), and a generated
document path that reads each field by handle (or an asset pseudo-attribute, or
a computed-field value), derives geopoints, and adds the reserved
`elementId`/`siteId`. Empty fields are omitted from the document, so an empty
Craft field does not reject the whole document against its optional schema field.
The compiled collection then syncs like any other.

## The JOIN boundary (G2)

Cross-collection references (Typesense JOINs) are deliberately absent from this
UI. References are a fluent-config-only feature: they are authored in
`config/typesense.php` with the `Field::...->reference()` builder, where the
relationship and its cascade behaviour are explicit and reviewable in code. The
mapping UI maps an element's own fields; it does not author JOINs. A collection
that needs references belongs in the config file (see
[Fluent config](fluent-config.md)).

## Stemming and locale

A text field's slideout can turn on stemming, set a Locale (the language used to
tokenize and stem it, stored as the ISO subtag Typesense expects), and select a
server stemming dictionary. Choosing a dictionary writes the field's
`stem_dictionary` and turns on stemming automatically (Typesense implies
`stem: true`). Stemming dictionaries are server-global; import them under
Dictionaries. See
[stemming](https://typesense.org/docs/30.2/api/stemming.html).
