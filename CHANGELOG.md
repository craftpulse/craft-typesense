# Typesense Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).

## 5.9.0 - Unreleased
> ### Upgrading from 5.8.x
> **This is a zero-touch update for existing installs.** Run `craft up` as usual.
>
> - **Namespace:** the internal PHP namespace moved from `percipiolondon\typesense` to `craftpulse\typesense`. The Composer package name (`craftpulse/craft-typesense`) and the plugin handle (`typesense`) are unchanged, so project config, permissions, and settings are preserved.
> - **Config files keep working:** existing `config/typesense.php` files that use `percipiolondon\typesense\TypesenseCollectionIndex` keep working through a backwards-compatibility shim and now run on the new sync engine. Each legacy config is deprecator-logged. Run `craft typesense/config/migrate` to generate the new fluent config, then review the `elementQuery` and `transform` closures it marks.
> - **Your data is preserved:** the synonyms table and rows survive. The vestigial `typesense_collections` table (never read or written) is dropped. Documents keep every field they had, plus two new reserved fields (`elementId`, `siteId`) used for reliable deletion and multisite filtering.
> - **Cockpit users:** if you run the Cockpit companion plugin, update it to its 5.9.0 companion release so it re-registers its collections. Until then the control panel shows a non-blocking warning and search keeps working.
> - **Full upgrade guide:** see `docs/upgrading.md`.

### Changed
- Moved the internal PHP namespace from `percipiolondon\typesense` to `craftpulse\typesense`, keeping the `craftpulse/craft-typesense` package name and `typesense` handle.
- Replaced the legacy client service with the new `Client` service. The plugin component is still reached with `getClient()`, and `getClient()->client()` still returns the typesense-php client, so existing calls keep working.
- The plugin's `typesense:manageSettings` permission now gates the settings screen. The old `typesense:plugin-settings` and `typesense:dashboard` permissions were removed.
- **Sync suspend moved from a project-config setting to runtime database state (behavior change).** The `syncSuspended` setting is gone; suspend now toggles freely in every environment, including when `allowAdminChanges` is disabled, and never churns project config. Suspend also became per-collection: toggle it globally or per collection from the Typesense utility, per collection from a collection's edit screen (Suspend sync in the meta sidebar), or with `craft typesense/sync/suspend [collection]` and `craft typesense/sync/resume [collection]`. The sync engine treats an element as suspended when the global switch is on or its collection is individually suspended. An existing globally-suspended install carries over to a global suspend row on update. See `docs/sync-engine.md`.
- **A collection's Relevance section can pick a default stopword set.** The chosen server stopword set is applied as the collection's default `stopwords` search parameter (through its mirrored preset), so those words are dropped from every query against the collection. Stopword sets are server-global; manage them under Dictionaries.
- **The mapping slideout can assign a server stemming dictionary to a field.** Choosing one writes the field's `stem_dictionary` and turns on stemming automatically (Typesense implies `stem: true`), so precise word-to-root mappings apply at index time. The slideout's Locale control is now a language select (normalised to the ISO subtag Typesense expects) rather than free text.
- **The search playground and document browser are now one GraphiQL-style screen.** A single playground (the analogue of Craft's GraphQL explorer, keeping Craft's chrome header) carries a collection picker and Run in the header toolbar, a search-params JSON editor (with a line-number gutter, Cmd/Ctrl+Enter to run) on the left, the ranked response on the right, and a docs-explorer side pane with the live schema and a pageable, filterable document browser. The diff-a-pending-edit capability is a drawer. The separate collection picker and document-browser screens are gone (the old `/playground/<collection>/browse` URL redirects into the screen), and Playground moved to the bottom of the Typesense subnav, directly above Settings.
- **Config-file collections have their own collection screen** (Typesense, then Collections, then a config collection). Its Overview is read-only (schema, mapping, relevance, and vector are managed in the config file), while its Synonyms and Curation sections stay control-panel-editable whenever the config file is silent about that domain, and render read-only with the config notice when it declares them (presence-based ownership). Config-collection screens are keyed by name (`typesense/collections/config/<name>/...`). A disabled control-panel collection's section now renders a "disabled" notice instead of a 404.
- **Synonyms and Curation moved into the collection edit screen** as its Synonyms (`typesense:manageSynonyms`) and Curation (`typesense:manageCuration`) sections, reached from the collection sidebar nav. Their top-level nav items, collection pickers, and picker routes are gone. Dictionaries stays top-level, since stopwords and stemming dictionaries are server-global resources (applied via search params and per-field references), not collection-bound. The Collections nav item and picker admit any of manageCollections, manageRelevance, manageSynonyms, or manageCuration.
- **Control-panel list screens now use Craft's native index-table idiom** (`Craft.VueAdminTable` with inline data, matching core's image-transforms screen): the collections cockpit, dictionaries, experiments, API-key profiles, conversation models, and the synonyms and curation sections list with a name link to the editor and a native row-delete affordance, replacing the previous "Manage" buttons and inline delete forms.
- **The collection edit screen is now the home for everything per-collection, as URL-based Craft tabs.** Settings and Mapping (gated by `typesense:manageCollections`), Relevance and Vector / AI (gated by `typesense:manageRelevance`) each keep their own URL, controller action, and permission gate. The old top-level Relevance nav item and its collection picker are gone: relevance is reached through Collections. The Mapping tab replaces the former "Map fields" button. Each tab renders only if the viewer holds its permission (a tab the viewer cannot access is not shown), and a new, unsaved collection shows only the Settings tab.

### Added
- Added a backwards-compatibility autoloader that transparently aliases legacy `percipiolondon\typesense\*` class names to their `craftpulse\typesense\*` counterparts.
- Added a new `Client` service: a typesense-php client factory for single-node, cluster, and Typesense Cloud connections, with health checks and a fail-soft search contract (an unreachable server returns a well-formed empty result, never a 500).
- Added client resilience settings: connection timeout, health-check interval, retry count, and retry interval, all environment-variable aware.
- Added server-version detection and `ServerCapabilities`: version-sensitive features are gated on the detected version (hide, never badge). Servers below v28.0 are unsupported, and v30.0 and v30.1 are refused because their synonym and curation APIs return 404.
- Added a collection-name prefix setting so several environments can share one cluster.
- Added a rebuilt settings screen (dedicated `SettingsController`, gated by the new `typesense:manageSettings` permission) with connection, resilience, sync and queue, and analytics tabs. The screen is read-only and saves fail closed when `allowAdminChanges` is off.
- Added an analytics opt-in default. Feature ownership (synonyms, curation, presets, stopwords) is presence-based, not a setting: a collection that declares a feature in its fluent config owns it (seeded and read-only in the control panel), and a collection that is silent leaves the feature control-panel-owned and editable. Permissions decide who can edit; config presence decides what is config-owned.
- Added fluent config builders (`Collection` and `Field`) covering the entire Typesense schema surface: every field type and property, geopoint, object/nested (with automatic `enable_nested_fields`), cross-collection references (JOINs, fluent-config only), vector fields with auto-embedding, and a `raw()` escape hatch. Builder features that need a specific server version validate against the detected capabilities and name the required version.
- Added the collections registry (`Collections`): merges config-file and event-registered collections into one map, resolves name collisions in favour of the config file (flagging the overridden name), and resolves Typesense target names through the environment-prefix resolver.
- Added the Schema service: Craft-field to Typesense-type derivation (extensible via `Schema::EVENT_DEFINE_TYPE_MAP`), geopoint normalization ([lat, lng] order, zero-coordinate skip), and a computed-field registry (`Schema::EVENT_REGISTER_COMPUTED_FIELDS`).
- Added the `Collections::EVENT_REGISTER_COLLECTIONS` event so other plugins and project code can register their own collections.
- Added the redone sync engine: element save/restore/delete/move listeners (gated on a configured client and the suspend switch), incremental upserts and deletes batched by collection and site and debounced to the end of the request, memory-bounded full syncs with Craft Cloud-safe continuation slicing and progress reporting, and tracked-ID reconciliation replacing the legacy export-diff-delete.
- Added the Documents transformer with both the closure path and a generated (field-mapping plus computed-field) path, reserved elementId/siteId fields for reliable deletion and site filtering, status-based delete signalling, multiple documents per element, relation dependency tracking (re-index on related-element change), and the `Documents::EVENT_BEFORE_INDEX_DOCUMENT` / `EVENT_AFTER_INDEX_DOCUMENT` events.
- Added multisite strategies (`collectionPerSite` and `sharedWithSiteFilter`) threaded through the registry name resolver and job batching.
- Added the `typesense_sync_state` and `typesense_sync_dependencies` tables (schema version 5.9.0), created on fresh install and via an idempotent update migration.
- Added a compatibility shim that adapts legacy `TypesenseCollectionIndex` configs into registry collections (deprecator-logged), so pre-5.9.0 config files run untouched on the new sync engine.
- Added the `typesense/config/migrate` command, which generates a fluent config file from a legacy config (schema deterministically, resolver bodies copied verbatim with review markers).
- Added the `typesense/config/export` command, which reverse-migrates a collection into a fluent config file.
- Added a non-blocking control-panel warning when an un-paired Cockpit version is detected during the update.
- Added the full console command surface: `typesense/sync/all|collection|flush|refresh|suspend|resume`, `typesense/config/diff`, `typesense/schema/apply` (always queued and polled), `typesense/keys/generate-scoped`, `typesense/inspect <elementId>`, and `typesense/backup/export|import` (JSONL, pipe-compatible with Typesense import).
- Added the Keys service and `craft.typesense.scopedSearchKey()` Twig helper: derives narrow, expiring, filter-embedded scoped search keys from the search-only key (HMAC, client-side, no admin key exposure), including the limit_hits, limit_multi_searches, and cache_ttl rate levers.
- Added the Drift service: compares the live Typesense schema against the declared config, detecting manual server-side edits and config changes. Powers `config/diff` and the utility drift panel.
- Added the Typesense control-panel utility (read-only in Free): server status and capabilities, a collections overview with document counts and drift indicators, and sync/flush/suspend actions built with `UrlHelper::actionUrl` (fixing the CP action URL issues in headless and split-domain setups).
- Added a "Reindex in Typesense" bulk element action on entries, categories, assets, and Commerce products (when installed).
- Added opt-in sync-failure email alerts (off by default), throttled per failure context.
- Added the Synonyms service with one public interface over two server shapes: per-collection synonyms on v28/v29 (through the typesense-php client) and global synonym sets on v30.2+ (through raw HTTP, since the bundled client predates them). The shape is chosen from the detected capabilities, so callers behave identically on either. Ownership is presence-based: a collection that declares `synonymDefinitions()` in fluent config owns its synonyms (seeded on collection creation, read-only in the control panel), and a collection that is silent leaves synonyms control-panel-owned.
- Added the Curation service with the same dual-shape design: per-collection overrides on v28/v29 and global curation sets on v30.2+, config-seeded for collections that declare `curationRules()`.
- Added the Presets service: a collection's declared search preset (typo tolerance, prefix, `drop_tokens`, and so on) is mirrored into a Typesense preset, and the search layer always passes it by name.
- Added the Search service and the `craft.typesense.search()` and `craft.typesense.multiSearch()` Twig helpers: fail-soft single and multi-search that resolve the site-aware target name and enrich each query with the collection's preset, synonym set (v30.2+), and stopwords. An unreachable server or unknown collection returns a well-formed empty result, never an error.
- Added config-seeded stopwords and stemming-dictionary import (the Dictionaries service), so a field's `stem_dictionary` reference resolves.
- Added a Free GraphQL `typesenseSearch` query: a developer tool that runs entirely server-side through the fail-soft search layer and returns the Typesense response as JSON, never exposing an API key to the client.
- Added a "View in Typesense search" element action (Free: points to the `typesense/inspect` command and the utility; upgrades to an in-panel search preview when the Pro companion is present).
- Added a progressively-enhanced front-end search built on Datastar (pinned to v1.0.2, loaded from a CDN, no build step): a server-side fragment endpoint (`typesense/search/results`) that runs the search with the admin key never leaving the server and returns the results, facet sidebar, and pagination as HTML fragments morphed into the page by id, plus the `craft.typesense.searchForm()`, `results()`, `facetList()`, `searchEndpoint()`, and `frontendSearch()` render builders. Search-as-you-type (debounced), multi-select facets, and pagination all work, and the whole widget degrades to a plain GET form that renders full results server-side with JavaScript disabled. The three fragment partials (`_typesense/results`, `_typesense/facets`, `_typesense/pagination`) are overridable from a project's `templates/_typesense/` directory.
- Added the `typesense/example-templates` console command, which installs a bundle of full example pages (search with facets, header autocomplete, geo/radius, and voice input) into `templates/<folder>` (default `typesense`), rewriting internal paths when renamed. The pages are wired to the `heroes` demo collection and run out of the box.
- Added Free and Pro editions. The Pro control panel is hidden (not badged) in Free, and every Pro action is edition-checked server-side, so a crafted request cannot reach a Pro action on Free. Pro registers a granular permission set under one Typesense heading, one handle per screen family with no umbrellas: `typesense:manageCollections` (cockpit and mapping), `typesense:manageRelevance`, `typesense:manageSynonyms`, `typesense:manageCuration`, `typesense:manageDictionaries`, `typesense:manageAliases`, `typesense:manageExperiments`, `typesense:manageKeys`, `typesense:viewAnalytics`, `typesense:viewDiagnostics` (playground, document browser, inspector sidebar), and `typesense:manageOps` (sync, flush, suspend, and index-lifecycle ops). `typesense:manageSettings` is registered in every edition; the rest are Pro-only. Nav items, routes, and server-side action checks all key off both edition and permission.
- Added the Pro collections cockpit: browse the control-panel-managed collections (create, edit, delete) alongside read-only visibility of the config-owned ones, with the "declared in config" notice. A collection picks an element source across the native element types (entries, categories, assets, tags, globals, users), a name, and a multisite strategy, and persists to project config keyed by UID.
- Added the Pro field mapping UI: a Garnish component in the FieldLayoutDesigner visual language (never instantiating `Craft.FieldLayoutDesigner`) with a read-only card per field of the source, the asset file pseudo fields, the Commerce variant fields, and the registered computed fields. A per-card disclosure HUD offers only the controls the server allows for the derived type: indexed, facet, sortable, search weight, infix, stemming, locale, bounded type override, nested-field indexing, the image-embed toggle on asset sources (wired to CLIP), and a natural-language description. Choices persist to the collection definition keyed by field UID.
- Added the Pro search playground and document browser (per collection, gated by the edition and `typesense:manageCollections`). The playground is a live query console: tunable params (`q`, `query_by`, `query_by_weights`, `filter_by`, `sort_by`, `preset`, `num_typos`, `drop_tokens_threshold`, `prefix`, `facet_by`, `per_page`) run server-side through the search layer (the admin key never reaches the browser, every param is bounded) and return ranked hits with their `_text_match` scores, `search_time_ms`, and facet counts. Its diff mode runs the query against the current state and a pending overlay of edits and renders the ranking change (entered, dropped, moved) before anything is saved. The document browser is a read-only, paged, filterable view of the actual indexed documents with a per-document JSON detail HUD. The "View in Typesense search" element action deep-links into the playground (prefiltered to the element) in Pro, and keeps its Free guidance.
- Added the generated-transformer compiler: a control-panel-managed collection definition compiles into a runtime collection (schema from the derived types plus overrides, facet, sort, infix, stem, locale; a generated document path for field, asset-pseudo, and computed-field values; search weights folded into the collection preset; descriptions into `metadata.field_descriptions`) and joins the same registry as config and event collections, flowing through the sync engine, drift comparator, utility, and search layer with no special-casing. References/JOINs remain a fluent-config-only feature, deliberately absent from the mapping UI.
- Added the Pro curation manager (gated by the edition and `typesense:manageCuration`): per-collection CRUD for curation rules over the dual-shape curation service, covering the query and match type (exact/contains), a rule filter condition, includes (pins) with 1-based positions, excludes (hides), `filter_by` and `sort_by` actions, and a valid-from/until effective window. A collection that declares `curationRules()` in config owns them and renders read-only with the "declared in config" notice; a collection that is silent is control-panel-owned and editable. Every write refreshes the element-to-rule lookup index (a new `typesense_curation_index` table) so pins can be looked up per element.
- Added the entry-edit curation quick-pin: a sidebar panel that lists the element's current pins and pins the element for a query at a position in one of the control-panel-owned curation collections it belongs to (creating the rule if absent). Hidden in Free, for users without `typesense:manageCuration`, and for elements that match no control-panel-owned curation collection.
- Added the Pro synonyms manager (gated by the edition and `typesense:manageSynonyms`): per-collection CRUD for one-way and multi-way synonyms (with an optional locale) over the dual-shape synonyms service. A collection that declares `synonymDefinitions()` in config owns them and renders read-only with the "declared in config" notice; a collection that is silent is control-panel-owned and editable.
- Added the Pro dictionaries manager (gated by the edition and `typesense:manageCollections`): per-collection stopwords sets (a collection that declares stopwords in config owns them and renders read-only; otherwise the set is control-panel-owned) and a stemming-dictionary screen that lists imported dictionaries and imports new ones from `word,root` lines or JSONL.
- Added the Pro indexing inspector: an `Inspector` service (the shared source of truth) and a read-only entry-edit sidebar panel that reports, per collection the element could belong to, its membership state (indexed, not a member, inactive, or builds no document), the last-indexed timestamp, the skip reason, and the live document currently in Typesense. Gated by the edition and `typesense:manageCollections`; hidden otherwise.
- Added Pro vector and AI search (gated by the edition and `typesense:manageCollections`): per-collection auto-embedding with a model picker (built-in `ts/*` ONNX models that run on the server CPU with no key, and the remote providers OpenAI, Azure, Google, GCP, Cloudflare, and OpenAI-compatible custom endpoints), whose credentials are read from environment variables and never stored raw, and whose config is validated for shape and environment resolution without ever making a live call. Auto-embedding compiles into a vector field generated server-side at index time; changing it re-embeds every document (surfaced as an honest re-sync warning). Added hybrid search (keyword + semantic fusion with an alpha weight and `rerank_hybrid_matches` gated on the v28+ capability), a similar-items helper (`craft.typesense.similar()`, vector-query by document id), and CLIP image search (`ts/clip-vit-b-p32`): an asset's file is streamed in bounded-memory chunks and base64-encoded at index time so images embed for text-to-image and image-to-image search.
- Added experimental AI features, each shown with its honest trade-off and hidden on servers that do not support it: conversational RAG (an `AiModels` service for env-keyed conversation-model config plus `craft.typesense.ask()`, with a per-search LLM cost), natural-language search (`nl_query`, gated on v29+, with an invalid-syntax retry risk), native personalization (v30.2+, undocumented upstream), and user-level BYO re-ranking (external model required). Conversation-model config is validated for shape and environment resolution without a live call.
- Added Pro search analytics (gated by the edition and `typesense:viewAnalytics`): an `Analytics` service (rule CRUD for popular-query, no-hit, counter, and log rules, destination readers, and event sending) and a dashboard showing popular-query and zero-result readouts, a one-click "set up recommended rules" action, and an A/B comparison by analytics tag on tag-capable servers (v29+, hidden below). Analytics is opt-in on both sides: the server must be started with search analytics enabled (the dashboard warns when it is not) and the plugin setting defaults off. User-id association defaults off (no per-user data unless opted in) and the retention posture is surfaced in the dashboard. Added a front-end analytics event helper: an anonymous `typesense/search/track-event` action records click and conversion events server-side (the admin key never reaches the browser) with the `craft.typesense.eventEndpoint()` Twig helper.
- Added a Free ops read and Pro ops actions: the utility surfaces the server's memory, disk, and CPU from `/metrics.json` (Free), and a user with `typesense:manageCollections` gets snapshot, database-compaction, and cache-clear actions (Pro, edition- and permission-checked). Added a Craft dashboard widget showing per-collection document count, last sync, drift, and (when analytics is on) zero-result volume.
- Added Pro zero-downtime reindexing through aliases (gated by the edition and `typesense:manageCollections`): an aliases manager and an `Aliases` service that rebuild a collection by building a fresh timestamped physical collection from the current declared schema, copying the documents into it, atomically swapping the alias, and dropping the old physical collection, so the logical name resolves to a queryable collection at every step. Rebuilds always run on the queue (a `RebuildCollection` job and a `typesense/schema/rebuild` console command), never a blocking save, because a schema change applied in place blocks writes cluster-wide. Added a capability-gated collection clone (Typesense `src_name`), hidden on servers too old to support it (hide, never badge).
- Added Pro A/B testing (gated by the edition and `typesense:manageCollections`): a composition screen and an `Experiments` service to define a search experiment over a collection with weighted variants, each applying a scoped-key profile, a search preset, and an analytics tag. Experiments persist to project config; `craft.typesense.experiment('handle')` picks a variant by weight and derives a per-variant scoped search key that embeds the variant's `analytics_tag` (when the server supports analytics tags) and applies its profile filter, splitting traffic and attributing results server-side. The click-through and no-result comparison across variants is the analytics dashboard's job (a later phase); this ships the definition and orchestration.
- Added Pro API key management (gated by the edition and a new `typesense:manageKeys` permission): a control-panel screen driving Typesense's keys API. Create keys with granular action scopes (`documents:search`, `collections:*`, `synonyms:*`, and the rest), per-collection restrictions, and expiry; list the server's keys (value prefix only, as Typesense exposes after creation); delete (guarded) and rotate (the replacement is created before the old key can be deleted). A created key's full value is shown exactly once in a copy-this-now Garnish modal with a "never shown again" warning and is never stored by the plugin (no database, no project config, no logs). Added named scoped-key profiles (per-site or per-tenant filter templates persisted to project config) that the Free derivation helper references: `craft.typesense.scopedSearchKey({ profile: 'handle' })` resolves the template server-side, the profile's locked filter taking precedence. Free derives scoped keys (the security primitive); Pro manages the key estate.
- Added Pro relevance tuning (gated by the edition and `typesense:manageCollections`): a per-collection editor for additive boost rules, a raw search preset, result grouping, text-match buckets, and MMR diversification. Additive boost rules compile to an indexed `boost_score` field baked into documents at index time (the deterministic alternative to Typesense's first-match-wins `_eval` bug, v27-30.1): a document's score is the sum of the weights of every rule it matches, wired into the collection preset's sort chain after the text-match sort. Text-match buckets (`_text_match(buckets: N)`) and MMR (`mmr`, `mmr_lambda`) are version-gated through `ServerCapabilities` and hidden on servers that do not support them. Boost changes require a re-sync, surfaced honestly in the editor; the search playground's diff mode previews pending weight and preset changes before they are committed.

### Changed
- Deepened the Drift service beyond schema: for collections that own those features in config it now compares declared synonyms and curation rules against the live sets (a live-only rule is drift), and it compares the declared search preset against the live one.
- Collision-free document ids for multi-site shared collections: a `sharedWithSiteFilter` collection whose scope spans more than one site now gives each site's document a composite `{elementId}-{siteId}` id, so per-site documents no longer overwrite each other. A single-site scope and `collectionPerSite` keep the bare element id, and a transform that sets its own id is untouched, so existing document identity does not churn. Deletion (by the reserved `elementId` field) removes every document of an element regardless of id shape.

### Removed
- Removed the Vue build chain and the VuePress documentation site generator. Documentation now lives as plain Markdown in `docs/`.
- Removed the redundant Dashboard control-panel nav item, which only redirected to Collections. The plugin section now lands on Collections.
- Removed the dead `ProjectConfigDataHelper` (only referenced by commented-out code).
- Retired the legacy sync internals now superseded by the new engine: the element-save event host, the legacy sync job, and the legacy collection service. Their responsibilities moved to the Sync and Documents services.
- Dropped the vestigial `typesense_collections` table.
- Retired the legacy Collections and Documents control-panel screens, the collection model, and the legacy event classes. The collections overview now lives in the Typesense utility.
- Retired the legacy synonyms control-panel cluster (its controller, service, sync job, model, and templates) and the now-orphaned `CollectionHelper`, `CollectionRecord`, and vestigial `TypesenseModel` scaffold. The `typesense_synonyms` table and its rows are preserved and now read through the Synonyms service. Synonym management moves to config (Free) and the Pro control-panel manager. With both the Collections and Synonyms screens retired, the plugin section now lands on Settings; collections and synonyms status lives in the Typesense utility.
- Removed the dead Vue-era control-panel apparatus: the `TypesenseAsset` and `TypesenseUtilityAsset` bundles, the `src/web/assets` Vue/TypeScript sources and build output, and the Vite plugin service wiring that only the retired Collections and Synonyms screens used. The read-only utility renders with native Craft form posts and needs no bundle; the Pro control panel introduces its own assets when it lands.

### Quality
- Raised the static-analysis floor to its final Free state: PHPStan runs at level 8 with an empty baseline, and the ECS skip list is empty. Every source file now passes both.
- Added three regression tests pinning the pre-rebuild backlog's damning themes: control-panel action URLs are built from the control-panel base and never the site URL (issue #68, asserted on `UrlHelper::actionUrl`), saving an element before the plugin is configured is a safe no-op (issue #63), and applying a draft skips cleanly instead of fataling (issue #67). A further test pins that one element indexes into every collection whose query matches it (issues #65 and #66).
- Completed the `docs/` set for the whole Free surface (search, synonyms/curation/presets, multi-site, drift, backup, and extension events for plugin authors) and rewrote the README for 5.9.0.
- Documented the Pro control panel: the collections cockpit and mapping UI, the search playground and document browser, the curation manager and entry quick-pin (`docs/pro-curation.md`), the synonyms and dictionaries managers (`docs/pro-synonyms.md`), the indexing inspector (`docs/inspector.md`), relevance tuning (`docs/pro-relevance.md`), API key management (`docs/pro-keys.md`), aliases and zero-downtime rebuilds (`docs/pro-aliases.md`), A/B testing (`docs/pro-ab-testing.md`), analytics and ops (`docs/pro-analytics.md`), and vector and AI (`docs/pro-vector-ai.md`).
- Estate audit: a systematic gating matrix pins that every Pro controller fails closed on the Free edition and for a user without its permission, that the control panel renders no Pro nav in Free (hide, never badge), and that the v28 server-capability gates hide the features they must. Swept all user-facing copy for em-dashes and release-stage language (only the defined in-product experimental AI flags remain). The Cockpit companion re-registers its jobs and offices collections from Cockpit's side through the collection-registration event (a paired release, finalised separately).

## 5.8.3 - 2026-03-27
### Fixed
- Fixed site-aware collection resolution in `DocumentsController` for multi-instance Cockpit elements. Previously, `handleSave` always resolved to the first matching collection (Go4Jobs), causing FiftyFivePlus jobs and departments to never sync to their correct Typesense index on element save.

## 5.8.2 - 2025-12-08
### Fixed
- Fixed an where the custom elements wouldn't sync - this is cockpit specific, temporary solution until full rework is labelled "done".

## 5.8.1 - 2025-10-21
### Fixed
- Fixed an issue where the custom elements couldn't resolve, only Entry's could be resolved

## 5.8.0 - 2025-06-20
### Added
- Added element support for craftpulse/cockpit/elements/Contact
- Added element support for craftpulse/cockpit/elements/Department
- Added element support for craftpulse/cockpit/elements/Job
- Added element support for craftpulse/cockpit/elements/MatchFieldEntry
- Added element support for craftpulse/reviews/elements/Review;

## 5.7.2.1 - 2025-04-16
### Fixed
- Fixed a regression issue from safeguarding when no client is known

## 5.7.2 - 2025-04-16
### Added
- Added the structure after move event inside the after save method

### Fixed
- Fixed the unwanted entries in an index in after save, because criteria were skipped. Thanks to [scholejo](https://github.com/scholejo)

## 5.7.1 - 2025-04-16
### Fixed
- Fixed an issue that could occur when upgrading the project but not having client settings.

## 5.7.0 - 2025-02-26
### Added
- Added support for categories thanks to [dezeweetjeniet](https://github.com/dezeweetjeniet) [PR #54](https://github.com/craftpulse/craft-typesense/pull/54)

### Fixed
- Don't sync/flush if the domain is not the same in site [#55](https://github.com/craftpulse/craft-typesense/issues/55)
- Session does not exist in a console request thanks to [samuelbirch](https://github.com/samuelbirch) [#51](https://github.com/craftpulse/craft-typesense/issues/51)

## 5.6.2 - 2025-02-03
### Added
- Fixed an issue where choosing "single" would end up in validation errors [#47](https://github.com/craftpulse/craft-typesense/issues/47)

## 5.6.1 - 2025-01-24
### Fixed
- Fixed an issue where the settings wouldn't display if "Single" was selected as an option [#46](https://github.com/craftpulse/craft-typesense/issues/46)

## 5.6.0 - 2025-01-06
### Added
- Added support for synonyms
- Added support for Craft Commerce (Products and Variants) thanks to [samuelbirch](https://github.com/samuelbirch) and [dezeweetjeniet](https://github.com/dezeweetjeniet) [PR #36](https://github.com/craftpulse/craft-typesense/pull/36)
- Added support for Shopify products thanks to [sidoneill](https://github.com/sidoneill) [issue #43](https://github.com/craftpulse/craft-typesense/issues/43)

## 5.5.4 - 2024-10-16
### Added
- Added event hooks to capture changes on the Typesense document (EVENT_AFTER_DELETE/EVENT_AFTER_UPSERT/EVENT_BEFORE_DELETE/EVENT_BEFORE_UPSERT)

## 5.5.3.1 - 2024-08-26
### Fixed
- Composer version of craft vite

## 5.5.3 - 2024-08-26
### Added
- Added the possibility to allow indexing multiple sections in a single collection [#35](https://github.com/craftpulse/craft-typesense/issues/35)
- Added code analysis and auto release scripts

### Fixed
- Removed composer.lock so latest versions of dependencies can be installed as defined in composer.json

## 5.5.2 - 2024-08-02
### Fixed
- Added section.all fallback for the deletion of documents

## 5.5.1 - 2024-07-15

### Fixed
- Check for the deletion on the documents

## 5.5.0 - 2024-04-09

### Changed
- Craft 5 release

## 5.4.0 - 2024-04-09

### Changed
- Prepped for Craft 5

## 5.3.0 - 2024-01-16

### Added
-   Check for null resolvers

## 5.2.0 - 2024-01-03 (Happy 2024)

### Added
-   Before Sync / Flush event hooks

## 5.1.0 - 2023-12-01

### Added
-   Support for Assets

## 5.0.1 - 2023-11-22

### Changed
-   Changed the handling of the document deletion for mutli sites

## 5.0.0 - 2023-03-13 / Official release

### Added
-   Docs for the official release
-   Seperate typesense logging

### Changed
-   Move the deletion of the collection into the sync if it's a flush #7

## 4.0.2 - 2022-11-04

### Added
-   Added the possibility to handle save for all the types inside of the section

## 4.0.1 - 2022-10-25

### Fix
-   Fixed: the project rebuild command was failing because of project config settings, these were disabled since we don't use them yet

## 4.0.0 - 2022-10-06

### Added
-   Added: A sync console command

## 4.0.0-beta.3 - 2022-09-19

### Changed
-   Changed: disabled the before routing fetch to check for scheduled posts

## 4.0.0-beta.2 - 2022-09-19

### Added
-   Added: Delete the document when setting an enabled entry to disable [#6](https://github.com/percipioglobal/craft-typesense/issues/6)
-   Added: Create the document when a scheduled post becomes active [#9](https://github.com/percipioglobal/craft-typesense/issues/9)

## 4.0.0-beta.1 - 2022-08-21

### Added
-   Added support for Craft 4
