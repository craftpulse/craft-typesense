<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The P3 gate: a memory-bounded multisite full sync at 4k-plus scale against the
 * real Typesense server, using the existing parleyTest entries (read-only) into
 * disposable per-site test collections. Skipped by default because it syncs
 * thousands of documents; run it explicitly with TYPESENSE_E2E=1.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\Entry;
use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\enums\MultisiteStrategy;
use craftpulse\typesense\Typesense;

it('performs a memory-bounded multisite full sync at 4k-plus scale', function() {
    $collection = Collection::make('ts_p3_e2e')
        ->elementType(Entry::class)
        ->multisite(MultisiteStrategy::CollectionPerSite)
        ->elementQuery(fn($query) => $query->section('parleyTest'))
        ->transform(fn(Entry $entry, callable $registerDependency) => [
            'id' => (string)$entry->id,
            'title' => (string)$entry->title,
        ])
        ->pageSize(500);

    $sync = Typesense::$plugin->getSync();
    $registry = Typesense::$plugin->getCollectionRegistry();
    $client = Typesense::$plugin->getClient()->client();
    $siteIds = [1, 2];

    $drop = function() use ($client, $registry, $collection, $siteIds): void {
        foreach ($siteIds as $siteId) {
            try {
                $client->collections[$registry->resolveName($collection, $siteId)]->delete();
            } catch (Throwable) {
                // already gone
            }
        }
    };

    $drop();
    $peakBefore = memory_get_peak_usage(true);

    try {
        foreach ($siteIds as $siteId) {
            $sync->ensureCollectionExists($collection, $siteId);
            $offset = 0;

            while (($processed = $sync->syncSlice($collection, $siteId, $offset, 500)) > 0) {
                $offset += $processed;
            }
        }

        $peakGrowth = memory_get_peak_usage(true) - $peakBefore;
        $elementsProcessed = 0;
        $documentsIndexed = 0;

        foreach ($siteIds as $siteId) {
            $elementsProcessed += $sync->countForSync($collection, $siteId);
            $documentsIndexed += $client->collections[$registry->resolveName($collection, $siteId)]->retrieve()['num_documents'];
        }

        // 4k-plus elements walked across the multisite collections, live documents
        // indexed into each per-site collection, with bounded memory growth.
        expect($elementsProcessed)->toBeGreaterThan(4000)
            ->and($documentsIndexed)->toBeGreaterThan(3000)
            ->and($peakGrowth)->toBeLessThan(128 * 1024 * 1024);
    } finally {
        $drop();
    }
})->skip(getenv('TYPESENSE_E2E') !== '1', 'Set TYPESENSE_E2E=1 to run the 4k multisite sync e2e.');
