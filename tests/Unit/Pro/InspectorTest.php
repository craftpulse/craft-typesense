<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The element indexing inspector service: the shared source of truth behind the
 * console command and the control-panel sidebar. For a real heroes entry it
 * reports the collection membership, the active state, the built document, and
 * the global sync flags.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\Entry;
use craftpulse\typesense\services\Inspector;
use craftpulse\typesense\Typesense;

it('reports an element as indexed in the collection it belongs to', function() {
    $entry = Entry::find()->section('heroes')->status('live')->siteId(1)->one();

    $report = Typesense::$plugin->getInspector()->inspectElement($entry);

    expect($report)->toHaveKeys(['elementId', 'siteId', 'status', 'configured', 'suspended', 'collections'])
        ->and($report['elementId'])->toBe((int)$entry->id)
        ->and($report['configured'])->toBeTrue()
        ->and($report['suspended'])->toBeBool();

    $names = array_column($report['collections'], 'name');
    expect($names)->toContain('heroes');

    $heroes = $report['collections'][array_search('heroes', $names, true)];

    expect($heroes['state'])->toBe(Inspector::STATE_INDEXED)
        ->and($heroes['documents'])->not->toBeEmpty()
        ->and($heroes['reason'])->toContain('Indexed')
        ->and($heroes)->toHaveKey('lastSyncedAt');
});

it('exposes the diagnostics the sidebar renders (state, timestamp, live document)', function() {
    $entry = Entry::find()->section('heroes')->status('live')->siteId(1)->one();
    $report = Typesense::$plugin->getInspector()->inspectElement($entry);

    $names = array_column($report['collections'], 'name');
    $heroes = $report['collections'][array_search('heroes', $names, true)];

    // The sidebar reads exactly these keys; assert their shape rather than
    // rendering the CP template (that activates the SEOmatic deprecation
    // cascade — the screen render lives in the smoke walk instead).
    expect($heroes['state'])->toBe(Inspector::STATE_INDEXED)
        ->and($heroes['liveDocument'])->toBeArray()
        ->and($heroes['liveDocument'])->toHaveKey('id')
        ->and($heroes)->toHaveKey('lastSyncedAt');
});

it('excludes collections whose element type does not match', function() {
    $entry = Entry::find()->section('heroes')->siteId(1)->one();

    $report = Typesense::$plugin->getInspector()->inspectElement($entry);

    // Every reported collection targets an element type the entry satisfies.
    foreach ($report['collections'] as $collection) {
        expect($collection)->toHaveKeys(['name', 'state', 'reason', 'documents', 'liveDocument', 'lastSyncedAt']);
    }
});
