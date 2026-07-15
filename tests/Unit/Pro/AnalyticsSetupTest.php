<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The analytics dashboard's recommended-rules action: an admin can create the
 * popular and no-hit rules for the declared collections, and the action is
 * edition- and permission-checked server-side (a crafted POST fails closed on
 * Free, and for a user without viewAnalytics). The rules and destinations it
 * creates are removed afterwards. Skipped when the server lacks analytics.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\User;
use craft\helpers\UrlHelper;
use craftpulse\typesense\Typesense;

function analyticsSetupServerEnabled(): bool
{
    return Typesense::$plugin->getAnalytics()->isServerEnabled();
}

function anAnalyticsAdmin(): User
{
    $suffix = bin2hex(random_bytes(4));
    $admin = new User();
    $admin->admin = true;
    $admin->username = "ts_an_{$suffix}";
    $admin->email = "ts_an_{$suffix}@example.test";
    Craft::$app->getElements()->saveElement($admin);

    return $admin;
}

function withAnalyticsEdition(string $edition, callable $test): void
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

function cleanupHeroesAnalyticsRules(): void
{
    $analytics = Typesense::$plugin->getAnalytics();
    $analytics->deleteRule('heroes_popular');
    $analytics->deleteRule('heroes_nohits');

    $client = Typesense::$plugin->getClient()->client();

    if ($client === null) {
        return;
    }

    foreach (['heroes_popular', 'heroes_nohits'] as $dest) {
        try {
            $client->collections[$dest]->delete();
        } catch (Throwable) {
            // already gone
        }
    }
}

it('creates the recommended rules for declared collections', function() {
    $admin = anAnalyticsAdmin();
    cleanupHeroesAnalyticsRules();

    withAnalyticsEdition(Typesense::EDITION_PRO, function() use ($admin) {
        $this->actingAs($admin)
            ->withExceptionHandling()
            ->post(UrlHelper::actionUrl('typesense/analytics/set-up-rules'))
            ->assertRedirect();

        $ruleNames = array_column(Typesense::$plugin->getAnalytics()->rules(), 'name');
        expect($ruleNames)->toContain('heroes_popular')
            ->and($ruleNames)->toContain('heroes_nohits');
    });

    cleanupHeroesAnalyticsRules();
})->skip(!analyticsSetupServerEnabled(), 'Server search analytics is not enabled.');

it('forbids the recommended-rules action on the Free edition', function() {
    $admin = anAnalyticsAdmin();

    withAnalyticsEdition(Typesense::EDITION_FREE, function() use ($admin) {
        $this->actingAs($admin)
            ->withExceptionHandling()
            ->post(UrlHelper::actionUrl('typesense/analytics/set-up-rules'))
            ->assertForbidden();
    });
});

it('forbids the recommended-rules action for a user without viewAnalytics', function() {
    $suffix = bin2hex(random_bytes(4));
    $user = new User();
    $user->username = "ts_noan_{$suffix}";
    $user->email = "ts_noan_{$suffix}@example.test";
    Craft::$app->getElements()->saveElement($user);

    withAnalyticsEdition(Typesense::EDITION_PRO, function() use ($user) {
        $this->actingAs($user)
            ->withExceptionHandling()
            ->post(UrlHelper::actionUrl('typesense/analytics/set-up-rules'))
            ->assertForbidden();
    });
});
