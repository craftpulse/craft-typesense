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
    $analytics = Typesense::$plugin->getAnalytics();
    $analytics->deleteRule(ANALYTICS_POPULAR_RULE);
    $analytics->deleteRule(ANALYTICS_NOHITS_RULE);

    $client = Typesense::$plugin->getClient()->client();

    if ($client === null) {
        return;
    }

    foreach ([ANALYTICS_POPULAR_DEST, ANALYTICS_NOHITS_DEST] as $dest) {
        try {
            $client->collections[$dest]->delete();
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

    // Analytics::upsertRule() passes `source.collections` straight through to
    // the raw Typesense API (src/services/Analytics.php - a pre-existing gap,
    // not resolved through Client::prefixedCollectionName() the way search
    // and sync are), so the rule must be told the physical, prefixed
    // collection name that is actually being searched below - the bare
    // logical "heroes" would register the rule against a collection nothing
    // in this suite ever queries.
    $heroesTarget = Typesense::$plugin->getClient()->prefixedCollectionName('heroes');

    cleanupAnalyticsFixtures();

    try {
        $analytics->ensureDestination(ANALYTICS_POPULAR_DEST);
        $analytics->ensureDestination(ANALYTICS_NOHITS_DEST);
        $analytics->upsertRule(ANALYTICS_POPULAR_RULE, Analytics::TYPE_POPULAR_QUERIES, [
            'source' => ['collections' => [$heroesTarget]],
            'destination' => ['collection' => ANALYTICS_POPULAR_DEST],
            'limit' => 100,
        ]);
        $analytics->upsertRule(ANALYTICS_NOHITS_RULE, Analytics::TYPE_NOHITS_QUERIES, [
            'source' => ['collections' => [$heroesTarget]],
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
