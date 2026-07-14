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

use craftpulse\typesense\Typesense;
use yii\base\InvalidConfigException;

function keys(): \craftpulse\typesense\services\Keys
{
    return Typesense::$plugin->getKeys();
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
