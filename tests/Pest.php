<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Pest configuration. Binds craft-pest's TestCase (which boots Craft and wraps
 * each test in a database transaction) to every test in this suite. A per-test
 * in-memory cache keeps state isolated between tests and avoids the playground
 * FileCache's filemtime() stat warnings.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\enums\CmsEdition;
use craft\web\twig\Extension;
use craft\web\View;
use craftpulse\auditkit\AuditKit;
use craftpulse\typesense\models\ServerCapabilities;
use craftpulse\typesense\Typesense;
use markhuot\craftpest\test\RefreshesDatabase;
use markhuot\craftpest\test\TestCase;
use yii\caching\ArrayCache;

/**
 * Silences Typesense's one audit-emitting surface: Audit Kit's dispatch bus.
 * `AuditKit\services\Bus::getSinks()` lazily assembles its sink registry from
 * `EVENT_REGISTER_AUDIT_SINKS` on first read and memoizes the result for the
 * life of the process, so a sink a previous test left registered would
 * otherwise still be live for the next test unless explicitly cleared.
 * `AuditEmissionTest` registers its own capturing sink per test and restores
 * the bus's original sinks in its own `finally` block; this beforeEach is the
 * backstop for every other test file so a test that never touches audit at
 * all never inherits a stale sink from one that does.
 *
 * @return void
 */
function muteAuditSurfaces(): void
{
    AuditKit::$plugin->getBus()->setSinks([]);
}

// Craft edition is pinned to Pro on every test rather than inherited from
// whatever a fresh `db_test` install defaults to (Solo): several fixtures and
// tests across this suite exercise multi-site and user-group scoped
// behaviour that Craft core gates behind Pro. `setEdition()` writes to
// project config, so it rolls back with the rest of the per-test transaction
// and must be re-pinned every test rather than once at bootstrap.
//
// The plugin's OWN edition (Typesense::$plugin->edition, a distinct concept
// from Craft's CmsEdition - see EditionGateTest) is likewise pinned to Pro on
// every test: it is a plain in-memory property, not project-config-backed,
// so nothing rolls it back automatically, and most of this suite exercises
// Pro-only surfaces. Tests that specifically exercise Free-edition gating
// already pin and restore it locally (see EditionGateTest's withEdition());
// resetting it here on every test is the backstop that keeps a test which
// fails before its own restore from leaking a Free pin into the next test.
//
// `Settings::$collections` is reset to the "heroes" fixture baseline on
// every test for the identical reason: several tests replace it wholesale
// for their own scoped fixture collection and restore the original in a
// `finally` block, but a test that fails before that restore runs must never
// leave a bare/empty collections array for the next test to read. This is
// the root cause the suite's own smoke run found in ConfigCollectionScreenTest
// (passes in isolation, fails in the full suite) - re-establishing the
// baseline here, before every test, makes that leak impossible regardless of
// which earlier test caused it.
//
// `frontendTemplatesDir` and `frontendThemeConfig` are reset to their
// shipped defaults ('' and []) for the same reason: FrontendTemplatesTest and
// ThemeConfigTest both mutate them to exercise the override/theme resolution
// paths and restore in a `finally`, but the same "a test that fails before
// its restore leaks into the next one" hazard applies - and the resulting
// symptom is easy to misdiagnose (a stray override directory value makes
// FrontendTemplates::_overridePath() resolve component atoms, like the
// per-result-card or per-facet-item partials, against a directory that
// doesn't carry them, rendering silently empty rather than throwing).
uses(TestCase::class, RefreshesDatabase::class)
    ->beforeEach(function() {
        Craft::$app->set('cache', new ArrayCache());
        Craft::$app->setEdition(CmsEdition::Pro);
        Typesense::$plugin->edition = Typesense::EDITION_PRO;

        $settings = Typesense::$plugin->getSettings();
        $settings->collections = typesensefixBaselineCollections();
        $settings->frontendTemplatesDir = '';
        $settings->frontendThemeConfig = [];

        // Every test in this file's directory tree runs through the SAME
        // `craft\web\View` instance and its memoized site-mode Twig
        // Environment - unlike a real web app, where each request gets a
        // fresh one. A CP-mode test (any `$this->get()`/`$this->post()`
        // against a CP path, e.g. Controllers/SettingsControllerTest) calls
        // craft-pest-core's own `RequestHandler::registerWithCraft()`, which
        // does two things for that one fake request: switches the view to
        // 'cp' template mode, and re-registers Twig's globals (the "craft"
        // variable this plugin's `craft.typesense.*` calls resolve through)
        // via a fresh `craft\web\twig\Extension`. Neither is undone
        // afterward - fine in a real app (one mode per request), not fine
        // here (one process, many tests). A test later in the suite that
        // renders a *site* template directly (this suite's own
        // FrontendTemplates::render() calls, direct SearchFormTag
        // construction) without first making its own `$this->get()`/`$this->post()`
        // call inherits both the stale 'cp' mode and Twig globals bound to
        // whatever state existed when they were last registered. The
        // observed symptom is narrow and easy to misdiagnose: the *outer*
        // template of a region (results.twig, facets.twig) renders fine, but
        // every *nested* `craft.typesense.component()`/`card()` call inside
        // it silently renders empty (Twig resolves "craft" fine at the
        // top level; nested rendering is where the stale binding bites).
        // Reset both explicitly before every test, replicating exactly what
        // `registerWithCraft()` does for the requests this suite doesn't
        // itself issue.
        $view = Craft::$app->getView();
        $view->setTemplateMode(View::TEMPLATE_MODE_SITE);

        $globals = (new Extension($view, $view->getTwig()))->getGlobals();

        foreach ($globals as $key => $value) {
            $view->getTwig()->addGlobal($key, $value);
        }

        muteAuditSurfaces();
    })
    ->afterEach(function() {
        // A non-admin acting identity from a controller test must never leak
        // into the next test's permission checks; clear it so each test sets
        // its own actor.
        Craft::$app->getUser()->setIdentity(null);
    })
    ->in(__DIR__);

/**
 * Builds a ServerCapabilities value object pinned to a version string.
 */
function caps(string $version): ServerCapabilities
{
    $capabilities = new ServerCapabilities();
    $capabilities->version = $version;

    return $capabilities;
}
