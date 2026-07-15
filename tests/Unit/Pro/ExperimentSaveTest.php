<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The A/B experiment save flow: saving redirects to the experiments index (not
 * back to the edit screen), and editing an existing experiment updates it in
 * place rather than creating a duplicate (the handle is locked on edit and
 * carried as originalHandle). The experiment is removed within the test.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\User;
use craft\helpers\UrlHelper;
use craftpulse\typesense\Typesense;

function anExperimentAdmin(): User
{
    $suffix = bin2hex(random_bytes(4));
    $admin = new User();
    $admin->admin = true;
    $admin->username = "ts_exp_{$suffix}";
    $admin->email = "ts_exp_{$suffix}@example.test";
    Craft::$app->getElements()->saveElement($admin);

    return $admin;
}

function withExperimentEdition(string $edition, callable $test): void
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

it('redirects to the experiments index after saving', function() {
    $admin = anExperimentAdmin();
    $handle = 'ts_exp_' . bin2hex(random_bytes(3));

    withExperimentEdition(Typesense::EDITION_PRO, function() use ($admin, $handle) {
        $this->actingAs($admin)
            ->withExceptionHandling()
            ->post(UrlHelper::actionUrl('typesense/experiments/save'), [
                'handle' => $handle,
                'name' => 'Redirect test',
                'collection' => 'heroes',
            ])
            ->assertRedirect(UrlHelper::cpUrl('typesense/experiments'));

        expect(Typesense::$plugin->getExperiments()->get($handle))->not->toBeNull();
    });

    Typesense::$plugin->getExperiments()->delete($handle);
});

it('updates an existing experiment in place instead of duplicating on edit', function() {
    $admin = anExperimentAdmin();
    $handle = 'ts_exp_' . bin2hex(random_bytes(3));

    withExperimentEdition(Typesense::EDITION_PRO, function() use ($admin, $handle) {
        // Create.
        $this->actingAs($admin)
            ->withExceptionHandling()
            ->post(UrlHelper::actionUrl('typesense/experiments/save'), [
                'handle' => $handle,
                'name' => 'Original',
                'collection' => 'heroes',
            ]);

        $before = count(Typesense::$plugin->getExperiments()->getAll());

        // Edit: the handle field is locked, so it arrives as originalHandle (a
        // different posted handle must not spawn a second experiment).
        $this->actingAs($admin)
            ->withExceptionHandling()
            ->post(UrlHelper::actionUrl('typesense/experiments/save'), [
                'handle' => 'a-different-handle',
                'originalHandle' => $handle,
                'name' => 'Renamed label',
                'collection' => 'heroes',
            ])
            ->assertRedirect(UrlHelper::cpUrl('typesense/experiments'));

        $after = count(Typesense::$plugin->getExperiments()->getAll());
        $reloaded = Typesense::$plugin->getExperiments()->get($handle);

        expect($after)->toBe($before)
            ->and($reloaded)->not->toBeNull()
            ->and($reloaded->name)->toBe('Renamed label')
            ->and(Typesense::$plugin->getExperiments()->get('a-different-handle'))->toBeNull();
    });

    Typesense::$plugin->getExperiments()->delete($handle);
});
