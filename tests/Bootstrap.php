<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Pest / PHPUnit bootstrap. Tests run from the playground's Craft root via
 * `ddev exec --dir=/var/www/html/cms vendor/bin/pest -c vendor/craftpulse/craft-typesense/phpunit.xml.dist`,
 * so the working directory is the Craft install and its autoloader (which
 * already maps both Craft and the symlinked plugin) is authoritative.
 * craft-pest's TestCase boots the application itself; this file only wires
 * autoloading and the Pest `uses()` bindings.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

$craftBase = getcwd();

if ($craftBase === false || !file_exists($craftBase . '/craft')) {
    fwrite(STDERR, "Typesense test bootstrap could not locate a Craft install at cwd={$craftBase}.\n");
    fwrite(STDERR, "Run tests from the playground Craft root via:\n");
    fwrite(STDERR, "  ddev exec --dir=/var/www/html/cms vendor/bin/pest -c vendor/craftpulse/craft-typesense/phpunit.xml.dist\n");
    exit(1);
}

$composerLoader = require $craftBase . '/vendor/autoload.php';

// The plugin's autoload-dev mapping never lands in the consuming project's
// vendor dir, so the test namespace is registered here.
if (is_object($composerLoader) && method_exists($composerLoader, 'addPsr4')) {
    $composerLoader->addPsr4('craftpulse\\typesense\\tests\\', __DIR__ . '/');
}

// Pest discovers Pest.php relative to its own working directory; load the
// plugin's bindings explicitly so `uses()` registers before tests run.
require __DIR__ . '/Pest.php';
