# Typesense Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](http://keepachangelog.com/) and this project adheres to [Semantic Versioning](http://semver.org/).

## 5.9.0 - Unreleased
> **Upgrade note:** the plugin's internal PHP namespace moved from `percipiolondon\typesense` to `craftpulse\typesense`. The Composer package name (`craftpulse/craft-typesense`) and the plugin handle (`typesense`) are unchanged, so project config, permissions, and settings are preserved on update. Existing `config/typesense.php` files that reference legacy class names such as `percipiolondon\typesense\TypesenseCollectionIndex` keep working through a backwards-compatibility shim; update them to the `craftpulse\typesense` namespace when convenient.

### Changed
- Moved the internal PHP namespace from `percipiolondon\typesense` to `craftpulse\typesense`, keeping the `craftpulse/craft-typesense` package name and `typesense` handle.
- Replaced the legacy client service with the new `Client` service. The plugin component is still reached with `getClient()`, and `getClient()->client()` still returns the typesense-php client, so existing calls keep working.
- The plugin's `typesense:manageSettings` permission now gates the settings screen. The old `typesense:plugin-settings` and `typesense:dashboard` permissions were removed.

### Added
- Added a backwards-compatibility autoloader that transparently aliases legacy `percipiolondon\typesense\*` class names to their `craftpulse\typesense\*` counterparts.
- Added a new `Client` service: a typesense-php client factory for single-node, cluster, and Typesense Cloud connections, with health checks and a fail-soft search contract (an unreachable server returns a well-formed empty result, never a 500).
- Added client resilience settings: connection timeout, health-check interval, retry count, and retry interval, all environment-variable aware.
- Added server-version detection and `ServerCapabilities`: version-sensitive features are gated on the detected version (hide, never badge). Servers below v28.0 are unsupported, and v30.0 and v30.1 are refused because their synonym and curation APIs return 404.
- Added a collection-name prefix setting so several environments can share one cluster.
- Added a rebuilt settings screen (dedicated `SettingsController`, gated by the new `typesense:manageSettings` permission) with connection, resilience, sync and queue, ownership, and analytics tabs. The screen is read-only and saves fail closed when `allowAdminChanges` is off.
- Added per-feature ownership defaults (synonyms, curation, presets), an analytics opt-in default, and a global sync-suspend switch.
- Added fluent config builders (`Collection` and `Field`) covering the entire Typesense schema surface: every field type and property, geopoint, object/nested (with automatic `enable_nested_fields`), cross-collection references (JOINs, fluent-config only), vector fields with auto-embedding, and a `raw()` escape hatch. Builder features that need a specific server version validate against the detected capabilities and name the required version.
- Added the collections registry (`Collections`): merges config-file and event-registered collections into one map, resolves name collisions in favour of the config file (flagging the overridden name), and resolves Typesense target names through the environment-prefix resolver.
- Added the Schema service: Craft-field to Typesense-type derivation (extensible via `Schema::EVENT_DEFINE_TYPE_MAP`), geopoint normalization ([lat, lng] order, zero-coordinate skip), and a computed-field registry (`Schema::EVENT_REGISTER_COMPUTED_FIELDS`).
- Added the `Collections::EVENT_REGISTER_COLLECTIONS` event so other plugins and project code can register their own collections.

### Removed
- Removed the Vue build chain and the VuePress documentation site generator. Documentation now lives as plain Markdown in `docs/`.
- Removed the redundant Dashboard control-panel nav item, which only redirected to Collections. The plugin section now lands on Collections.
- Removed the dead `ProjectConfigDataHelper` (only referenced by commented-out code).

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
