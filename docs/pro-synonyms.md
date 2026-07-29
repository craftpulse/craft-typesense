---
title: Synonyms and dictionaries managers (Pro)
description: Authoring synonyms, stopwords, and stemming dictionaries in the control panel
---
# Synonyms and dictionaries managers (Pro)

The Pro edition adds control-panel managers for a collection's synonyms,
stopwords, and stemming dictionaries. They sit over the same dual-shape services
the config file uses, so they work identically on Typesense v28/v29
(per-collection APIs) and v30.2+ (global sets). For the config-file side of these
levers, and the shared ownership model, see `docs/synonyms.md`.

## Editions and permissions

These managers are Pro features, each gated by its own permission: the synonyms
manager by `typesense:manage-synonyms`, and the stopwords and stemming-dictionary
managers by `typesense:manage-dictionaries`. In the Free edition the plugin renders
none of the Pro control panel. Every Pro action is edition-checked server-side.

## Synonyms

Open a control-panel-managed collection (Typesense, then Collections, then a
collection) and choose its Synonyms section, then add or edit a synonym. A
synonym carries:

| Field | Meaning |
| --- | --- |
| Synonym id | A stable identifier. |
| Terms | The interchangeable terms, one per line (or comma-separated). |
| Root (one-way) | Optional. When set, the terms map one-way to this root query; leave blank for a multi-way synonym. |
| Locale | Optional language code that scopes the synonym (for example, `en`). |

A collection that declares `synonymDefinitions()` in config owns its synonyms and
renders read-only with the "declared in config" notice; a collection that is
silent is control-panel-owned and editable.

## Stopwords

Pick a collection from `Typesense -> Dictionaries`, then manage its stopwords set
(words dropped from queries against the collection). A collection that declares
its stopwords in fluent config owns them and renders read-only with the config
notice; otherwise the set is control-panel-owned and editable. You can set an
optional locale.

## Stemming dictionaries

`Typesense -> Dictionaries -> Stemming dictionaries` lists the imported stemming
dictionaries and imports new ones. An import needs a dictionary id (the id a
field's `stem_dictionary` reference resolves to) and its entries, one word/root
pair per line as `word,root`, or a JSONL object (`{"word":"...","root":"..."}`)
per line.
