<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Backwards-compatibility shim for the pre-5.9.0 PHP namespace.
 *
 * The plugin's internal namespace moved from `percipiolondon\typesense` to
 * `craftpulse\typesense` in 5.9.0 (the Composer package name `craftpulse/craft-typesense`
 * and the plugin handle `typesense` are unchanged). Legacy fully-qualified class
 * names survive in three places across the upgrade: userland `config/typesense.php`
 * files and custom-module `Event::on(percipiolondon\...)` handlers and type hints;
 * class strings Craft persisted (a `HealthWidget` in a dashboard, mapping-field
 * element classes in a field layout's project-config); and serialized queue
 * payloads already in the database. This file registers a fallback autoloader that
 * transparently aliases any legacy `percipiolondon\typesense\*` class, interface,
 * or trait to its `craftpulse\typesense\*` counterpart, so all three keep working.
 *
 * It loads through Composer's `files` autoload (see composer.json), which runs at
 * bootstrap before any class resolution, so it is registered in time for the very
 * first legacy-class load, including the unserialize() of a pending queue payload.
 *
 * The autoloader is appended (not prepended), so it only runs after Composer's
 * PSR-4 autoloader fails to resolve the (now non-existent) legacy class. When it
 * fires it logs a Craft deprecation naming the old and new class (best-effort:
 * guarded for early-boot and console contexts where the deprecator is unavailable),
 * then aliases the class, so legacy usage is visible without breaking.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 * @since     5.9.0
 */

spl_autoload_register(static function(string $class): void {
    $legacyPrefix = 'percipiolondon\\typesense\\';

    if (!str_starts_with($class, $legacyPrefix)) {
        return;
    }

    $target = 'craftpulse\\typesense\\' . substr($class, strlen($legacyPrefix));

    if (!class_exists($target) && !interface_exists($target) && !trait_exists($target)) {
        return;
    }

    // Log a deprecation naming the old and new class, when the deprecator is
    // available. The autoloader can fire before the application is booted (or in
    // a console/queue context, or during the unserialize() of a queue payload),
    // so this is strictly best-effort and never allowed to break the alias.
    if (class_exists(\Craft::class, false) && \Craft::$app !== null) {
        try {
            \Craft::$app->getDeprecator()->log(
                "typesense-legacy-namespace:{$class}",
                "The `{$class}` class name is deprecated. Use `{$target}` instead. The legacy `percipiolondon\\typesense` namespace was replaced by `craftpulse\\typesense` in 5.9.0 and its shim will be removed in a future major version.",
            );
        } catch (\Throwable) {
            // Deprecator unavailable (early boot, no database, console): skip
            // logging and still alias below.
        }
    }

    class_alias($target, $class);
});
