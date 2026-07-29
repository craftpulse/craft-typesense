<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Regression coverage for the collection-prefix gap in the Analytics service:
 * ensureDestination(), upsertRule(), and the popular/no-hits readers used to
 * bypass Client::prefixedCollectionName() entirely and write or read raw,
 * unprefixed collection names even on an install using a collection prefix
 * (this suite's own "typesensefix_" prefix, forced in phpunit.xml.dist),
 * unlike Search and Sync which already resolve every collection name through
 * that seam. Proves each of those paths now targets the physical, prefixed
 * collection. Every collection and rule created here is run-scoped (a random
 * suffix) and dropped afterwards; nothing is asserted against the shared
 * "heroes" fixture collection's document counts.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\typesense\services\Analytics;
use craftpulse\typesense\Typesense;

/**
 * Deletes a rule (by name) and a list of physical collection names, fail-soft.
 *
 * @param array<int, string> $ruleNames
 * @param array<int, string> $physicalCollectionNames
 * @return void
 */
function cleanupAnalyticsPrefixFixtures(array $ruleNames, array $physicalCollectionNames): void
{
    $analytics = Typesense::$plugin->getAnalytics();

    foreach ($ruleNames as $ruleName) {
        $analytics->deleteRule($ruleName);
    }

    $sdkClient = Typesense::$plugin->getClient()->client();

    if ($sdkClient === null) {
        return;
    }

    foreach ($physicalCollectionNames as $name) {
        try {
            $sdkClient->collections[$name]->delete();
        } catch (Throwable) {
            // already gone
        }
    }
}

it("creates ensureDestination()'s collection under its prefixed physical name, never the bare logical one", function() {
    $client = Typesense::$plugin->getClient();
    $sdkClient = $client->client();

    expect($sdkClient)->not->toBeNull();

    $analytics = Typesense::$plugin->getAnalytics();
    $logical = 'ts_pest_analytics_prefix_dest_' . bin2hex(random_bytes(4));
    $physical = $client->prefixedCollectionName($logical);

    // The suite forces a non-empty prefix, so the physical name always differs
    // from the bare logical one.
    expect($physical)->not->toBe($logical);

    cleanupAnalyticsPrefixFixtures([], [$physical, $logical]);

    try {
        $analytics->ensureDestination($logical);

        $physicalExists = true;

        try {
            $sdkClient->collections[$physical]->retrieve();
        } catch (Throwable) {
            $physicalExists = false;
        }

        $logicalExists = true;

        try {
            $sdkClient->collections[$logical]->retrieve();
        } catch (Throwable) {
            $logicalExists = false;
        }

        expect($physicalExists)->toBeTrue()
            ->and($logicalExists)->toBeFalse();
    } finally {
        cleanupAnalyticsPrefixFixtures([], [$physical, $logical]);
    }
});

it("resolves upsertRule()'s source and destination collection references to their prefixed physical names", function() {
    $client = Typesense::$plugin->getClient();
    $analytics = Typesense::$plugin->getAnalytics();
    $suffix = bin2hex(random_bytes(4));

    $sourceLogical = "ts_pest_analytics_prefix_source_{$suffix}";
    $destLogical = "ts_pest_analytics_prefix_dest_{$suffix}";
    $ruleName = "ts_pest_analytics_prefix_rule_{$suffix}";
    $sourcePhysical = $client->prefixedCollectionName($sourceLogical);
    $destPhysical = $client->prefixedCollectionName($destLogical);

    cleanupAnalyticsPrefixFixtures([$ruleName], [$sourcePhysical, $destPhysical]);

    try {
        $analytics->ensureDestination($destLogical);

        expect($analytics->upsertRule($ruleName, Analytics::TYPE_POPULAR_QUERIES, [
            'source' => ['collections' => [$sourceLogical]],
            'destination' => ['collection' => $destLogical],
            'limit' => 100,
        ]))->toBeTrue();

        $stored = null;

        foreach ($analytics->rules() as $rule) {
            if (($rule['name'] ?? null) === $ruleName) {
                $stored = $rule;

                break;
            }
        }

        expect($stored)->not->toBeNull()
            ->and($stored['params']['source']['collections'] ?? null)->toBe([$sourcePhysical])
            ->and($stored['params']['destination']['collection'] ?? null)->toBe($destPhysical);
    } finally {
        cleanupAnalyticsPrefixFixtures([$ruleName], [$sourcePhysical, $destPhysical]);
    }
})->skip(!Typesense::$plugin->getAnalytics()->isServerEnabled(), 'Server search analytics is not enabled.');

it("reads popularQueries() and noHitsQueries() from the prefixed physical destination", function() {
    $client = Typesense::$plugin->getClient();
    $sdkClient = $client->client();

    expect($sdkClient)->not->toBeNull();

    $analytics = Typesense::$plugin->getAnalytics();
    $logical = 'ts_pest_analytics_prefix_read_' . bin2hex(random_bytes(4));
    $physical = $client->prefixedCollectionName($logical);
    $needle = 'prefix-needle-' . bin2hex(random_bytes(4));

    cleanupAnalyticsPrefixFixtures([], [$physical, $logical]);

    try {
        $analytics->ensureDestination($logical);
        $sdkClient->collections[$physical]->documents->create(['q' => $needle, 'count' => 7]);

        $popular = $analytics->popularQueries($logical);
        $noHits = $analytics->noHitsQueries($logical);

        expect(array_column($popular, 'q'))->toContain($needle)
            ->and(array_column($noHits, 'q'))->toContain($needle);
    } finally {
        cleanupAnalyticsPrefixFixtures([], [$physical, $logical]);
    }
});
