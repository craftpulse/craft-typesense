<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The P11 analytics gate: with search analytics enabled on the server, a
 * popular-queries rule and a no-hits rule aggregate real queries into their
 * destination collections, and the analytics readers surface them for the
 * dashboard. Runs against the real server and waits for the analytics flush
 * interval, so it is slow; it is skipped when the server does not have analytics
 * enabled. The rules and destination collections are removed afterwards.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\typesense\services\Analytics;
use craftpulse\typesense\Typesense;

const ANALYTICS_POPULAR_DEST = 'ts_pest_popular';
const ANALYTICS_NOHITS_DEST = 'ts_pest_nohits';
const ANALYTICS_POPULAR_RULE = 'ts_pest_popular_rule';
const ANALYTICS_NOHITS_RULE = 'ts_pest_nohits_rule';

function analyticsServerEnabled(): bool
{
    return Typesense::$plugin->getAnalytics()->isServerEnabled();
}

function cleanupAnalyticsFixtures(): void
{
    $analyticsHelper = Typesense::$plugin->getAnalytics();
    $analyticsHelper->deleteRule(ANALYTICS_POPULAR_RULE);
    $analyticsHelper->deleteRule(ANALYTICS_NOHITS_RULE);

    $clientHelper = Typesense::$plugin->getClient();
    $sdkClient = $clientHelper->client();

    if ($sdkClient === null) {
        return;
    }

    // ensureDestination() resolves these through Client::prefixedCollectionName(),
    // so the physical collection actually created (and read back from) is the
    // suite's own prefixed name, not the bare logical one.
    foreach ([ANALYTICS_POPULAR_DEST, ANALYTICS_NOHITS_DEST] as $dest) {
        try {
            $sdkClient->collections[$clientHelper->prefixedCollectionName($dest)]->delete();
        } catch (Throwable) {
            // already gone
        }
    }
}

it('detects the server analytics flag and manages rules', function() {
    $analytics = Typesense::$plugin->getAnalytics();

    expect($analytics->isServerEnabled())->toBeTrue();

    cleanupAnalyticsFixtures();

    try {
        $analytics->ensureDestination(ANALYTICS_POPULAR_DEST);
        expect($analytics->upsertRule(ANALYTICS_POPULAR_RULE, Analytics::TYPE_POPULAR_QUERIES, [
            'source' => ['collections' => ['heroes']],
            'destination' => ['collection' => ANALYTICS_POPULAR_DEST],
            'limit' => 100,
        ]))->toBeTrue();

        $ruleNames = array_column($analytics->rules(), 'name');
        expect($ruleNames)->toContain(ANALYTICS_POPULAR_RULE);
    } finally {
        cleanupAnalyticsFixtures();
    }
})->skip(!analyticsServerEnabled(), 'Server search analytics is not enabled.');

it('completes the query to dashboard loop (popular and no-hit aggregation)', function() {
    $analytics = Typesense::$plugin->getAnalytics();
    $search = Typesense::$plugin->getSearch();

    // Analytics::upsertRule() resolves `source.collections` and
    // `destination.collection` through Client::prefixedCollectionName(), the
    // same seam search() uses below, so the bare logical "heroes" handle
    // registers the rule against the exact physical collection actually being
    // searched, with no manual prefixing needed here.
    cleanupAnalyticsFixtures();

    try {
        $analytics->ensureDestination(ANALYTICS_POPULAR_DEST);
        $analytics->ensureDestination(ANALYTICS_NOHITS_DEST);
        $analytics->upsertRule(ANALYTICS_POPULAR_RULE, Analytics::TYPE_POPULAR_QUERIES, [
            'source' => ['collections' => ['heroes']],
            'destination' => ['collection' => ANALYTICS_POPULAR_DEST],
            'limit' => 100,
        ]);
        $analytics->upsertRule(ANALYTICS_NOHITS_RULE, Analytics::TYPE_NOHITS_QUERIES, [
            'source' => ['collections' => ['heroes']],
            'destination' => ['collection' => ANALYTICS_NOHITS_DEST],
            'limit' => 100,
        ]);

        // A query that hits, repeated, and one that returns nothing.
        $needle = 'herald' . substr(md5((string)microtime(true)), 0, 6);

        for ($i = 0; $i < 5; $i++) {
            $search->search('heroes', ['q' => 'hero', 'query_by' => 'title']);
        }

        $search->search('heroes', ['q' => $needle, 'query_by' => 'title']);

        // Poll for the flush (interval is 60s in the playground compose).
        $popular = [];
        $nohits = [];

        for ($attempt = 0; $attempt < 20; $attempt++) {
            sleep(5);
            $popular = $analytics->popularQueries(ANALYTICS_POPULAR_DEST);
            $nohits = $analytics->noHitsQueries(ANALYTICS_NOHITS_DEST);

            if ($popular !== [] && $nohits !== []) {
                break;
            }
        }

        expect(array_column($popular, 'q'))->toContain('hero')
            ->and(array_column($nohits, 'q'))->toContain($needle);
    } finally {
        cleanupAnalyticsFixtures();
    }
})->skip(!analyticsServerEnabled(), 'Server search analytics is not enabled.');
