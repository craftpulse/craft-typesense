---
title: Field mapping (Pro)
description: Mapping element fields to Typesense in the control panel
---
# Field mapping (Pro)

The mapping screen is where a control-panel-managed collection decides how each
element field lands in Typesense. It presents one card per field in the
FieldLayoutDesigner visual language, with a disclosure panel per card for the
field's options. (It borrows the designer's look and interaction only; it never
touches Craft's field-layout authoring.)

Open it from a saved collection's edit screen (Map fields).

## The cards

The screen shows a card for every mappable field of the collection's source:

- each field in the element source's field layout (for entries, the union of the
  section's entry types);
- for asset sources, the file-derived pseudo fields: filename, kind, size,
  width, height;
- for Commerce product sources, the variant fields (surfaced on the product
  card) when Commerce is installed;
- every registered computed field (see [Extending](extending.md)).

Each card shows the field name, the Typesense type the server derived for it,
and a one-line summary of the current mapping.

## Server-authoritative derivation

The server, not the browser, decides two things: the Typesense type a field
derives to (through the Schema service, extensible with
`Schema::EVENT_DEFINE_TYPE_MAP`) and which controls apply to that type. The
disclosure panel only offers the controls the server allows for the field, so
you cannot map a field to something the server would reject.

## The controls

Opening a card's panel reveals the applicable controls:

| Control | Applies to | Effect |
| --- | --- | --- |
| Indexed | all | Whether the field is included in the document and schema. |
| Facet | string, string[], int, bool | Aggregate the field for faceted browsing. |
| Sortable | string, numeric, bool | Allow sorting on the field. |
| Search weight | string, string[] | The field's `query_by_weights` weight, compiled into the collection preset. |
| Infix search | string, string[] | Enable infix (mid-word) matching. |
| Stemming | string, string[] | Enable stemming. |
| Locale | string, string[] | The stemming/tokenisation locale. |
| Type override | all | Change the field's type within a bounded, server-approved set. |
| Index nested fields | object, object[] | Index the object's nested fields. |
| Embed image (experimental) | asset sources | Auto-embed the asset image (experimental; the pipeline lands later). |
| Description | all | A natural-language description of the field. |

Applying the panel writes the choices back to the card and, on Save, persists
them to the collection definition in project config, keyed by field UID.

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
