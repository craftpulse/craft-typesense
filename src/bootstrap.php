<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Forward-compatibility shim for the 5.9.0 PHP namespace move.
 *
 * The plugin's internal namespace becomes `craftpulse\typesense` in 5.9.0 (the
 * Composer package name `craftpulse/craft-typesense` and the plugin handle
 * `typesense` are unchanged). On this 5.8.x line the classes still live under
 * `percipiolondon\typesense`, so this file registers a fallback autoloader that
 * transparently aliases any `craftpulse\typesense\*` class, interface, or trait to
 * its existing `percipiolondon\typesense\*` counterpart. That lets integrators
 * migrate their imports and `config/typesense.php` references to the future
 * `craftpulse\typesense` names today, before upgrading to 5.9.0, and keep working
 * on 5.8.x in the meantime.
 *
 * The autoloader is appended (not prepended), so it only runs after Composer's
 * PSR-4 autoloader fails to resolve the (not-yet-existing) `craftpulse\typesense`
 * class. It does not log a deprecation: on this line the `craftpulse\typesense`
 * names are the forward target, not deprecated, and the `percipiolondon\typesense`
 * names remain fully supported until 5.9.0.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2025 CraftPulse
 */

spl_autoload_register(static function(string $class): void {
    $futurePrefix = 'craftpulse\\typesense\\';

    if (!str_starts_with($class, $futurePrefix)) {
        return;
    }

    $target = 'percipiolondon\\typesense\\' . substr($class, strlen($futurePrefix));

    if (!class_exists($target) && !interface_exists($target) && !trait_exists($target)) {
        return;
    }

    class_alias($target, $class);
});
