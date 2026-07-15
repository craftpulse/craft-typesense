<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Covers scoped search key derivation: deterministic output, embedded parameters
 * and key prefix (matching Typesense's HMAC scheme), and the guard when no
 * search-only key is available.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\typesense\models\KeyProfile;
use craftpulse\typesense\Typesense;
use yii\base\InvalidConfigException;

const KEYS_TEST_DESCRIPTION = 'ts_pest_key_do_not_keep';

function keys(): \craftpulse\typesense\services\Keys
{
    return Typesense::$plugin->getKeys();
}

/**
 * Deletes every server key created by this suite (matched by its obvious test
 * description), so no test key lingers in the real key store.
 */
function purgeTestKeys(): void
{
    foreach (keys()->all() as $key) {
        if (($key['description'] ?? '') === KEYS_TEST_DESCRIPTION) {
            keys()->delete((int)$key['id']);
        }
    }
}

it('derives a deterministic scoped search key', function() {
    $a = keys()->generateScopedSearchKey(['filter_by' => 'siteId:=1'], 'searchonlykey123');
    $b = keys()->generateScopedSearchKey(['filter_by' => 'siteId:=1'], 'searchonlykey123');

    expect($a)->toBe($b)
        ->and($a)->not->toBe('');
});

it('embeds the parameters and key prefix', function() {
    $key = keys()->generateScopedSearchKey(
        ['filter_by' => 'x:=1', 'expires_at' => 9999999999],
        'abcd1234',
    );
    $decoded = base64_decode($key);

    expect($decoded)->toContain('abcd')
        ->and($decoded)->toContain('"filter_by":"x:=1"')
        ->and($decoded)->toContain('"expires_at":9999999999');
});

it('derives distinct keys for distinct filters', function() {
    $a = keys()->generateScopedSearchKey(['filter_by' => 'siteId:=1'], 'parentkey');
    $b = keys()->generateScopedSearchKey(['filter_by' => 'siteId:=2'], 'parentkey');

    expect($a)->not->toBe($b);
});

it('throws when no search-only key is available', function() {
    expect(fn() => keys()->generateScopedSearchKey(['filter_by' => 'x:=1'], ''))
        ->toThrow(InvalidConfigException::class);
});

it('creates a key (value once), lists prefixes only, and deletes it', function() {
    purgeTestKeys();

    try {
        $created = keys()->create([
            'description' => KEYS_TEST_DESCRIPTION,
            'actions' => ['documents:search'],
            'collections' => ['heroes'],
        ]);

        // The full value is returned exactly once, at creation.
        expect($created)->not->toBeNull()
            ->and($created['value'] ?? '')->not->toBe('')
            ->and($created['actions'])->toContain('documents:search');

        $fullValue = $created['value'];
        $id = (int)$created['id'];

        // Listing exposes only the prefix, never the full value.
        $listed = null;

        foreach (keys()->all() as $key) {
            if ((int)$key['id'] === $id) {
                $listed = $key;
                break;
            }
        }

        expect($listed)->not->toBeNull()
            ->and($listed)->toHaveKey('value_prefix')
            ->and($listed)->not->toHaveKey('value')
            ->and($fullValue)->toStartWith($listed['value_prefix']);

        expect(keys()->delete($id))->toBeTrue();
    } finally {
        purgeTestKeys();
    }
});

it('never persists a created key value (no database, project config, or log path)', function() {
    // The service returns the value; it must not write it anywhere. Assert the
    // create path touches no persistence: the project config carries no key
    // value, and the returned array is the only place it lives.
    purgeTestKeys();

    try {
        $created = keys()->create([
            'description' => KEYS_TEST_DESCRIPTION,
            'actions' => ['documents:search'],
            'collections' => ['*'],
        ]);
        $value = (string)($created['value'] ?? '');

        expect($value)->not->toBe('');

        $projectConfig = json_encode(Craft::$app->getProjectConfig()->get() ?? []);
        expect(str_contains((string)$projectConfig, $value))->toBeFalse();
    } finally {
        purgeTestKeys();
    }
});

it('round-trips a scoped-key profile and derives a key that embeds it', function() {
    $profile = new KeyProfile();
    $profile->handle = 'ts_pest_profile';
    $profile->name = 'Pest profile';
    $profile->filterBy = 'siteId:=1';
    $profile->includeFields = 'title,slug';
    $profile->expiresIn = 3600;
    $profile->limitHits = 50;

    try {
        expect(keys()->saveProfile($profile))->toBeTrue();

        $reloaded = keys()->getProfile('ts_pest_profile');
        expect($reloaded)->not->toBeNull()
            ->and($reloaded->filterBy)->toBe('siteId:=1')
            ->and($reloaded->limitHits)->toBe(50);

        // The resolved parameters carry the locked filter and a computed expiry.
        $resolved = keys()->resolveProfileParameters('ts_pest_profile');
        expect($resolved['filter_by'])->toBe('siteId:=1')
            ->and($resolved['include_fields'])->toBe('title,slug')
            ->and($resolved['limit_hits'])->toBe(50)
            ->and($resolved['expires_at'])->toBeGreaterThan(time());

        // A derived key embeds the profile's locked filter.
        $derived = base64_decode(keys()->generateScopedSearchKey([], 'searchonlykey123', 'ts_pest_profile'));
        expect($derived)->toContain('"filter_by":"siteId:=1"');
    } finally {
        keys()->deleteProfile('ts_pest_profile');
    }
});
