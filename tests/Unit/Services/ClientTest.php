<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Integration coverage against the real Typesense server configured in the
 * playground. Proves the client builds from settings, health/connection works,
 * the server version is detected and wired into ServerCapabilities, and the
 * search path is fail-soft. Runs against both the v28 (default) and v30.2
 * containers via the compose tag-swap; the capability assertions derive their
 * expectations from the live version so the suite is correct on both.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\typesense\services\Client;
use craftpulse\typesense\Typesense;

function client(): Client
{
    return Typesense::getInstance()->getClient();
}

it('is configured and builds a client', function() {
    expect(client()->isConfigured())->toBeTrue()
        ->and(client()->client())->not->toBeNull();
});

it('reports a healthy connection', function() {
    expect(client()->getHealth())->toBe(['ok' => true])
        ->and(client()->isConnected())->toBeTrue();
});

it('detects the server version and derives supported capabilities', function() {
    $version = client()->getServerVersion();
    $capabilities = client()->getServerCapabilities();

    expect($version)->not->toBeNull()
        ->and($capabilities)->not->toBeNull()
        ->and($capabilities->isSupported())->toBeTrue()
        ->and($capabilities->textMatchBuckets())->toBeTrue();
});

it('flips the synonym-set flag according to the live server version', function() {
    $capabilities = client()->getServerCapabilities();
    $expected = version_compare($capabilities->version, '30.2', '>=');

    expect($capabilities->synonymSets())->toBe($expected)
        ->and($capabilities->curationSets())->toBe($expected);
});

it('summarises status for the control panel', function() {
    $status = client()->getStatus();

    expect($status)->toHaveKeys(['configured', 'connected', 'version', 'supported', 'flags'])
        ->and($status['configured'])->toBeTrue()
        ->and($status['connected'])->toBeTrue();
});

it('returns a well-formed empty result when searching a missing collection', function() {
    $result = client()->search('collection_that_does_not_exist', [
        'q' => '*',
        'query_by' => 'title',
    ]);

    expect($result)->toHaveKeys(['found', 'hits', 'facet_counts'])
        ->and($result['found'])->toBe(0)
        ->and($result['hits'])->toBe([]);
});

it('applies the configured collection prefix', function() {
    $settings = Typesense::getInstance()->getSettings();
    $original = $settings->collectionPrefix;
    $settings->collectionPrefix = 'staging_';

    try {
        expect(client()->prefixedCollectionName('heroes'))->toBe('staging_heroes')
            ->and(client()->prefixedCollectionName('staging_heroes'))->toBe('staging_heroes');
    } finally {
        $settings->collectionPrefix = $original;
    }
});
