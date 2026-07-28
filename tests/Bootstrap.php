<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Pest / PHPUnit bootstrap. Run the suite from Typesense's OWN root - its own
 * `vendor/bin/pest` (or `ddev composer test`, which resolves to the same
 * binary) - never via a shared playground's
 * `ddev craft pest -- --configuration=vendor/craftpulse/craft-typesense/phpunit.xml.dist`.
 * That shared invocation leaves the process's working directory at the
 * playground's Craft root, not here, so craft-pest-core's InstallsCraft
 * plugin never finds this plugin's `phpunit.xml.dist` and its `<env>` DB
 * overrides in `phpunit.xml.dist` (see the comment there) never apply -
 * Craft boots against the playground's live `db` instead of `db_test` and
 * every fixture this suite writes commits permanently.
 *
 * With the correct invocation the working directory is this plugin's own
 * root, so its own autoloader (already mapping both Craft and the plugin's
 * own `src`/`tests`) is authoritative and craft-pest's TestCase boots the
 * application itself against `db_test` - this file only wires autoloading,
 * installs the plugin(s) under test, primes the Typesense-side fixtures, and
 * pins the process timezone.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

$craftBase = getcwd();

// Typesense vendors its own `craftcms/cms` (see composer.json) precisely so
// its suite can boot a fully isolated Craft install rather than depend on
// whatever plugins happen to be co-installed in a shared app. Checking for
// `vendor/craftcms/cms` (rather than a `craft` executable, which only exists
// at a consuming project's root, never a plugin's own) confirms this really
// is Typesense's own root before autoloading from it.
if ($craftBase === false || !is_dir($craftBase . '/vendor/craftcms/cms')) {
    fwrite(STDERR, "Typesense test bootstrap could not locate a standalone Craft install at cwd={$craftBase}.\n");
    fwrite(STDERR, "Run tests from Typesense's own root, e.g.:\n");
    fwrite(STDERR, "  ddev exec --dir /Users/Shared/dev/craft-plugins/v5/craft-typesense vendor/bin/pest\n");
    fwrite(STDERR, "  ddev exec --dir /Users/Shared/dev/craft-plugins/v5/craft-typesense composer test\n");
    exit(1);
}

$composerLoader = require $craftBase . '/vendor/autoload.php';

// The plugin's autoload-dev mapping never lands in the consuming project's
// vendor dir, so the test namespace is registered here.
if (is_object($composerLoader) && method_exists($composerLoader, 'addPsr4')) {
    $composerLoader->addPsr4('craftpulse\\typesense\\tests\\', __DIR__ . '/');
}

// Pest auto-discovers tests/Pest.php from the test root once getcwd() is this
// plugin's own root (true for the standalone invocation this file requires
// above), so it is NOT required here as well: doing so registers the same
// `uses()->in(__DIR__)` binding twice and Pest refuses the second, identical
// registration with "Test case can not be used ... already uses the test
// case".

// =============================================================================
// Plugin install - Audit Kit, then Typesense. craft-pest-core's InstallsCraft
// plugin (which already booted Craft by this point in the Kernel sequence,
// see the class docblock above) installs Craft core and applies any pending
// project config, but never installs the plugin(s) under test.
//
// Audit Kit is Typesense's hard `require` dependency (not a suggest-only
// integration): src/services/Audit.php reaches through `AuditKit::$plugin`
// (its dispatch bus, its event-type registry) for every audited action, so
// Audit Kit must be installed before Typesense - a plugin whose Install.php
// or runtime code references another plugin's tables/services fails
// otherwise.
//
// db_test is a long-lived database shared across every plugin suite
// retrofitted against this playground's MySQL instance (Audit Kit, Relay,
// and others all have project config persisted in it from prior runs). This
// standalone bootstrap has no `config/project/` YAML of its own to match
// that state, so craft-pest-core's `InstallsCraft::install()` step - which
// already ran by the time this file executes, per the class docblock above
// - treats every one of those other plugins' stored project config paths as
// externally removed and prunes them via `ProjectConfig::applyExternalChanges()`,
// holding the `project-config` MySQL GET_LOCK() (`ProjectConfig::MUTEX_NAME`)
// for the whole reconciliation. Craft's own `_acquireLock()` calls
// `$mutex->acquire($name)` with no timeout - a single, non-blocking attempt -
// so `installPlugin()` below can trip a `BusyResourceException` if it runs
// while that pruning is still in flight. Retrying with a real wait (rather
// than pre-emptively acquiring and releasing the same lock ourselves, which
// would just contend with the same pruning again) rides out a one-time,
// bounded cost rather than papering over a genuine problem: the lock is
// never held indefinitely, only for as long as this database's accumulated
// cross-plugin project config takes to prune.
// =============================================================================

$installPlugin = static function(string $handle) use (&$installPlugin): void {
    $plugins = Craft::$app->getPlugins();

    if ($plugins->isPluginInstalled($handle)) {
        return;
    }

    $attemptsRemaining = 10;

    while (true) {
        try {
            $plugins->installPlugin($handle);

            return;
        } catch (craft\errors\BusyResourceException $e) {
            $attemptsRemaining--;

            if ($attemptsRemaining <= 0) {
                throw $e;
            }

            sleep(1);
        }
    }
};

if (Craft::$app->getIsInstalled(true)) {
    foreach (['audit-kit', 'typesense'] as $handle) {
        $installPlugin($handle);
    }
}

// =============================================================================
// Typesense-side test fixtures - the "heroes"-handled stand-in section,
// entries, and the dedicated, prefixed Typesense collection behind it (see
// tests/Support/typesense-fixtures.php). Loaded explicitly: this file's
// autoload-dev mapping only covers the PSR-4 `craftpulse\typesense\tests\`
// namespace, not plain function files under tests/Support/, and the priming
// function itself must run once here, after Typesense is installed above,
// rather than from tests/Pest.php's `beforeEach()` (which runs inside every
// test's own rolled-back transaction and would never leave a stable fixture
// standing for the next test to read).
// =============================================================================

require __DIR__ . '/Support/typesense-fixtures.php';

if (Craft::$app->getIsInstalled(true)) {
    typesensefixPrimeFixtures();
}

// =============================================================================
// Timezone - Craft stores and compares every datetime attribute in UTC, but a
// fresh install's `system.timeZone` project-config default is
// `America/Los_Angeles` (see craft\migrations\Install), and
// Application::init() calls date_default_timezone_set() from that value
// during the boot InstallsCraft already performed above - clobbering
// anything set earlier in this file. Pinned back to UTC here, after boot, so
// a DateTime built with the process's ambient timezone that round-trips
// through a saved element attribute (stored via ->format('Y-m-d H:i:s'),
// reloaded assuming UTC) doesn't come back shifted - which would silently
// break any test that persists a DateTime and re-reads it in the same run.
// =============================================================================

date_default_timezone_set('UTC');
