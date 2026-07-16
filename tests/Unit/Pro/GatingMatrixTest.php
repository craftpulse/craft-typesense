<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The estate gating matrix (P13 audit). Every Pro controller enforces its gates
 * in beforeAction (shared by all its actions), so one action per controller
 * proves the gate for the whole controller. This asserts, across EVERY Pro
 * controller: a crafted request on the Free edition fails closed (edition gate),
 * and a request from a user without the controller's permission fails closed
 * (permission gate). The permitted-admin path is covered by the per-feature
 * tests; here we pin the deny side systematically.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\User;
use craft\helpers\UrlHelper;
use craftpulse\typesense\Typesense;

/**
 * One reachable action per Pro controller and the permission it enforces. GET
 * for read actions, POST for write-only controllers.
 *
 * @return array<string, array{0: string, 1: string, 2: string}>
 */
dataset('proActions', [
    'ai-providers' => ['typesense/ai-providers/index', 'get', 'typesense:manageAiProviders'],
    'aliases' => ['typesense/aliases/index', 'get', 'typesense:manageAliases'],
    'analytics' => ['typesense/analytics/index', 'get', 'typesense:viewAnalytics'],
    'collections' => ['typesense/collections/edit', 'get', 'typesense:manageCollections'],
    'curation' => ['typesense/curation/list', 'get', 'typesense:manageCuration'],
    'dictionaries' => ['typesense/dictionaries/index', 'get', 'typesense:manageDictionaries'],
    'experiments' => ['typesense/experiments/index', 'get', 'typesense:manageExperiments'],
    'keys' => ['typesense/keys/index', 'get', 'typesense:manageKeys'],
    'ops' => ['typesense/ops/clear-cache', 'post', 'typesense:manageOps'],
    'playground' => ['typesense/playground/index', 'get', 'typesense:viewDiagnostics'],
    'relevance' => ['typesense/relevance/edit', 'get', 'typesense:manageRelevance'],
    'synonyms' => ['typesense/synonyms/list', 'get', 'typesense:manageSynonyms'],
]);

function aGatingAdmin(): User
{
    $suffix = bin2hex(random_bytes(4));
    $admin = new User();
    $admin->admin = true;
    $admin->username = "ts_gate_{$suffix}";
    $admin->email = "ts_gate_{$suffix}@example.test";
    Craft::$app->getElements()->saveElement($admin);

    return $admin;
}

function aPermissionlessUser(): User
{
    $suffix = bin2hex(random_bytes(4));
    $user = new User();
    $user->username = "ts_bare_{$suffix}";
    $user->email = "ts_bare_{$suffix}@example.test";
    Craft::$app->getElements()->saveElement($user);

    return $user;
}

function withGatingEdition(string $edition, callable $test): void
{
    $plugin = Typesense::$plugin;
    $original = $plugin->edition;
    $plugin->edition = $edition;

    try {
        $test();
    } finally {
        $plugin->edition = $original;
    }
}

it('fails closed on the Free edition for every Pro controller', function(string $action, string $method) {
    $admin = aGatingAdmin();

    withGatingEdition(Typesense::EDITION_FREE, function() use ($admin, $action, $method) {
        $this->actingAs($admin)->withExceptionHandling();
        $response = $method === 'post'
            ? $this->post(UrlHelper::actionUrl($action))
            : $this->get(UrlHelper::actionUrl($action));
        $response->assertForbidden();
    });
})->with('proActions');

it('fails closed for a user without the permission on every Pro controller', function(string $action, string $method) {
    $user = aPermissionlessUser();

    withGatingEdition(Typesense::EDITION_PRO, function() use ($user, $action, $method) {
        $this->actingAs($user)->withExceptionHandling();
        $response = $method === 'post'
            ? $this->post(UrlHelper::actionUrl($action))
            : $this->get(UrlHelper::actionUrl($action));
        $response->assertForbidden();
    });
})->with('proActions');

it('grants nothing beyond its own screen family to a single-permission user (no umbrella)', function(string $action, string $method, string $permission) {
    withGatingEdition(Typesense::EDITION_PRO, function() use ($permission) {
        $user = aPermissionlessUser();
        Craft::$app->getUserPermissions()->saveUserPermissions((int)$user->id, [$permission]);

        // Holding exactly one handle grants nothing else: a screen from a
        // different family stays forbidden (the allow side of each handle is
        // covered by the per-feature admin tests).
        $other = $permission === 'typesense:viewAnalytics'
            ? 'typesense/keys/index'
            : 'typesense/analytics/index';

        $this->actingAs($user)
            ->withExceptionHandling()
            ->get(UrlHelper::actionUrl($other))
            ->assertForbidden();
    });
})->with('proActions');
