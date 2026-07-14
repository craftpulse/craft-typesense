<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Covers the version-to-capability derivation: floor enforcement, the hard
 * refusal of v30.0/v30.1, and each version-gated feature flag across the
 * supported version span (v28, v29, v30.2).
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

it('enforces the v28 support floor', function() {
    expect(caps('27.1')->isBelowFloor())->toBeTrue()
        ->and(caps('27.1')->isSupported())->toBeFalse()
        ->and(caps('28.0')->isBelowFloor())->toBeFalse()
        ->and(caps('28.0')->isSupported())->toBeTrue();
});

it('refuses v30.0 and v30.1', function() {
    expect(caps('30.0')->isRefused())->toBeTrue()
        ->and(caps('30.0')->isSupported())->toBeFalse()
        ->and(caps('30.1')->isRefused())->toBeTrue()
        ->and(caps('30.1')->isSupported())->toBeFalse()
        ->and(caps('30.1.1')->isRefused())->toBeTrue();
});

it('supports v29 and v30.2', function() {
    expect(caps('29.0')->isSupported())->toBeTrue()
        ->and(caps('30.2')->isSupported())->toBeTrue()
        ->and(caps('30.2')->isRefused())->toBeFalse();
});

it('gates text_match buckets and hybrid rerank at v28', function() {
    expect(caps('28.0')->textMatchBuckets())->toBeTrue()
        ->and(caps('28.0')->rerankHybridMatches())->toBeTrue();
});

it('gates analytics tags and NL search at v29', function() {
    expect(caps('28.0')->analyticsTags())->toBeFalse()
        ->and(caps('28.0')->nlSearch())->toBeFalse()
        ->and(caps('29.0')->analyticsTags())->toBeTrue()
        ->and(caps('29.0')->nlSearch())->toBeTrue();
});

it('gates synonym sets, curation sets, and personalization models at v30.2', function() {
    expect(caps('29.0')->synonymSets())->toBeFalse()
        ->and(caps('29.0')->curationSets())->toBeFalse()
        ->and(caps('29.0')->personalizationModels())->toBeFalse()
        ->and(caps('30.2')->synonymSets())->toBeTrue()
        ->and(caps('30.2')->curationSets())->toBeTrue()
        ->and(caps('30.2')->personalizationModels())->toBeTrue();
});

it('gates collection cloning at v30.1 and mmr at v30', function() {
    expect(caps('30.2')->collectionCloning())->toBeTrue()
        ->and(caps('30.2')->mmr())->toBeTrue()
        ->and(caps('29.0')->mmr())->toBeFalse();
});

it('reports the full flag map', function() {
    $flags = caps('30.2')->getFlags();

    expect($flags)->toHaveKeys([
        'textMatchBuckets', 'rerankHybridMatches', 'analyticsTags', 'nlSearch',
        'mmr', 'collectionCloning', 'synonymSets', 'curationSets', 'personalizationModels',
    ])->and($flags['synonymSets'])->toBeTrue();
});

it('gives an unsupported reason only for unsupported versions', function() {
    expect(caps('30.2')->getUnsupportedReason())->toBeNull()
        ->and(caps('30.1')->getUnsupportedReason())->toContain('30.1')
        ->and(caps('27.0')->getUnsupportedReason())->toContain('floor');
});
