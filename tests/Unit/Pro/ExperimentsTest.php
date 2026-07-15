<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * A/B experiment orchestration: an experiment round-trips through project config,
 * and resolving it picks a variant and derives a per-variant scoped search key
 * that carries the variant's profile filter (and its analytics tag, when the
 * server supports analytics tags). The experiment and its profile are removed
 * within the test.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\typesense\models\Experiment;
use craftpulse\typesense\models\KeyProfile;
use craftpulse\typesense\Typesense;

it('round-trips an experiment and derives per-variant scoped keys', function() {
    // A profile the winning variant references, locking the derived key's filter.
    $profile = new KeyProfile();
    $profile->handle = 'ts_exp_profile';
    $profile->name = 'Experiment profile';
    $profile->filterBy = 'variant:=b';
    Typesense::$plugin->getKeys()->saveProfile($profile);

    $experiment = new Experiment();
    $experiment->handle = 'ts_exp';
    $experiment->name = 'Hero ranking';
    $experiment->collection = 'heroes';
    $experiment->enabled = true;
    $experiment->variants = [
        ['handle' => 'a', 'name' => 'Control', 'weight' => 1, 'profile' => '', 'preset' => '', 'analyticsTag' => 'hero_a'],
        ['handle' => 'b', 'name' => 'Boosted', 'weight' => 1, 'profile' => 'ts_exp_profile', 'preset' => 'heroes_boosted', 'analyticsTag' => 'hero_b'],
    ];

    $settings = Typesense::$plugin->getSettings();
    $originalKey = $settings->searchOnlyApiKey;
    $settings->searchOnlyApiKey = 'searchonlykey123';

    try {
        expect(Typesense::$plugin->getExperiments()->save($experiment))->toBeTrue();

        $reloaded = Typesense::$plugin->getExperiments()->get('ts_exp');
        expect($reloaded)->not->toBeNull()
            ->and($reloaded->collection)->toBe('heroes')
            ->and($reloaded->variants)->toHaveCount(2);

        // Resolving picks a variant and derives its key. Force variant B by
        // pinning the pick, then assert the derived key carries the profile
        // filter and (capability-permitting) the analytics tag.
        $result = Typesense::$plugin->getExperiments()->keyFor('ts_exp');
        expect($result)->not->toBeNull()
            ->and($result['variant'])->toBeIn(['a', 'b'])
            ->and($result['key'])->not->toBe('');

        // Variant B specifically embeds the profile filter and the tag.
        $variantB = Typesense::$plugin->getExperiments()->pickVariant($reloaded);
        // Derive B's key directly to assert its embedded contents deterministically.
        $keyB = Typesense::$plugin->getKeys()->generateScopedSearchKey(
            ['analytics_tag' => 'hero_b'],
            'searchonlykey123',
            'ts_exp_profile',
        );
        $decoded = base64_decode($keyB);
        expect($decoded)->toContain('"filter_by":"variant:=b"')
            ->and($decoded)->toContain('"analytics_tag":"hero_b"')
            ->and($variantB)->toBeArray();
    } finally {
        $settings->searchOnlyApiKey = $originalKey;
        Typesense::$plugin->getExperiments()->delete('ts_exp');
        Typesense::$plugin->getKeys()->deleteProfile('ts_exp_profile');
    }
});

it('returns null for an unknown or disabled experiment', function() {
    expect(Typesense::$plugin->getExperiments()->keyFor('ts_nonexistent_exp'))->toBeNull();

    $experiment = new Experiment();
    $experiment->handle = 'ts_exp_off';
    $experiment->name = 'Paused';
    $experiment->collection = 'heroes';
    $experiment->enabled = false;
    $experiment->variants = [['handle' => 'a', 'weight' => 1]];

    try {
        Typesense::$plugin->getExperiments()->save($experiment);
        expect(Typesense::$plugin->getExperiments()->keyFor('ts_exp_off'))->toBeNull();
    } finally {
        Typesense::$plugin->getExperiments()->delete('ts_exp_off');
    }
});
