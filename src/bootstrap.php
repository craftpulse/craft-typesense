<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Backwards-compatibility shim for the pre-5.9.0 PHP namespace.
 *
 * The plugin's internal namespace moved from `percipiolondon\typesense` to
 * `craftpulse\typesense` in 5.9.0 (the Composer package name `craftpulse/craft-typesense`
 * and the plugin handle `typesense` are unchanged). Existing `config/typesense.php`
 * files reference legacy fully-qualified class names such as
 * `percipiolondon\typesense\TypesenseCollectionIndex`, so this file registers a
 * fallback autoloader that transparently aliases any legacy `percipiolondon\typesense\*`
 * class, interface, or trait to its `craftpulse\typesense\*` counterpart. Userland
 * config authored against the old namespace keeps working verbatim.
 *
 * The autoloader is appended (not prepended), so it only runs after Composer's
 * PSR-4 autoloader fails to resolve the (now non-existent) legacy class. Deprecation
 * notices for legacy config usage are emitted by the config compatibility shim
 * (which runs inside a booted request context), not from this autoloader, which can
 * fire before the application is fully initialised.
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

    class_alias($target, $class);
});
