<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The P10 zero-downtime gate: a live collection served through an alias is
 * rebuilt (fresh physical collection, documents copied, atomic alias swap, old
 * physical dropped) while the logical name resolves to a queryable collection at
 * every observable step. Runs against the real server on a disposable collection
 * (never the heroes collection); every physical collection and the alias are
 * removed afterwards.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\builders\Field;
use craftpulse\typesense\enums\MultisiteStrategy;
use craftpulse\typesense\Typesense;

const ALIAS_TEST_LOGICAL = 'ts_alias_test';

/**
 * Removes the alias and every physical collection the suite may have created.
 *
 * Matches on `str_contains()` against the bare `ALIAS_TEST_LOGICAL` name,
 * never `str_starts_with()`: every physical collection this test creates
 * (`getCreateSchema()`, `$aliases->rebuild()`) is resolved through the
 * registry, which prepends TYPESENSE_COLLECTION_PREFIX (see
 * phpunit.xml.dist) ahead of the logical name, so a starts-with check
 * against the bare name would never match and every physical collection
 * this test creates would leak across runs. The one raw, unprefixed
 * collection the third test below creates directly still contains the bare
 * name as a substring, so this also still covers it. Same reasoning for the
 * alias itself: `rebuild()` manages it under the prefixed logical name, so
 * both the bare and prefixed alias name are attempted.
 */
function cleanupAliasTest(): void
{
    $client = Typesense::$plugin->getClient()->client();

    if ($client === null) {
        return;
    }

    foreach ([ALIAS_TEST_LOGICAL, Typesense::$plugin->getClient()->prefixedCollectionName(ALIAS_TEST_LOGICAL)] as $aliasName) {
        try {
            $client->aliases[$aliasName]->delete();
        } catch (Throwable) {
            // no alias
        }
    }

    foreach ($client->collections->retrieve() as $collection) {
        $name = (string)($collection['name'] ?? '');

        if (str_contains($name, ALIAS_TEST_LOGICAL)) {
            try {
                $client->collections[$name]->delete();
            } catch (Throwable) {
                // already gone
            }
        }
    }
}

it('rebuilds behind an alias with zero downtime and cleans up the old collection', function() {
    $declared = Collection::make(ALIAS_TEST_LOGICAL)
        ->multisite(MultisiteStrategy::SharedWithSiteFilter)
        ->preset(['query_by' => 'title'])
        ->fields(Field::string('title'));

    $settings = Typesense::$plugin->getSettings();
    $original = $settings->collections;
    $settings->collections = [$declared];

    $client = Typesense::$plugin->getClient()->client();
    $registry = Typesense::$plugin->getCollectionRegistry();
    $aliases = Typesense::$plugin->getAliases();
    $search = Typesense::$plugin->getSearch();
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;
    $logical = $registry->resolveName($declared, $siteId);

    cleanupAliasTest();

    try {
        // Seed the alias pattern: a physical collection with two docs, served
        // through the logical alias.
        $seed = $logical . '_seed';
        $schema = $registry->getCreateSchema($declared, $siteId);
        $schema['name'] = $seed;
        $client->collections->create($schema);
        $client->collections[$seed]->documents->create(['id' => '1', 'title' => 'Alpha', 'elementId' => 1, 'siteId' => $siteId]);
        $client->collections[$seed]->documents->create(['id' => '2', 'title' => 'Beta', 'elementId' => 2, 'siteId' => $siteId]);
        $client->aliases->upsert($logical, ['collection_name' => $seed]);

        // BEFORE: the logical name resolves to a queryable collection.
        expect($aliases->resolve($logical))->toBe($seed)
            ->and($search->search($logical, ['q' => '*', 'query_by' => 'title'])['found'])->toBe(2);

        // Rebuild: fresh physical, copy, atomic swap, drop the old physical.
        $result = $aliases->rebuild($declared, $siteId);

        // AFTER: the alias now points at the new physical collection, which is
        // still queryable with the same documents, and the old one is gone.
        expect($result['previous'])->toBe($seed)
            ->and($result['physical'])->not->toBe($seed)
            ->and($aliases->resolve($logical))->toBe($result['physical'])
            ->and($search->search($logical, ['q' => '*', 'query_by' => 'title'])['found'])->toBe(2);

        // The old physical collection was cleaned up.
        $stillThere = true;

        try {
            $client->collections[$seed]->retrieve();
        } catch (Throwable) {
            $stillThere = false;
        }

        expect($stillThere)->toBeFalse();
    } finally {
        cleanupAliasTest();
        $settings->collections = $original;
    }
});

it('refuses to clone on a server below the cloning version (hide-not-badge gate)', function() {
    // The playground runs v28, which does not support src_name cloning. The gate
    // fails closed: clone() returns false and creates nothing on the server.
    $capabilities = Typesense::$plugin->getClient()->getServerCapabilities();

    expect($capabilities?->collectionCloning() ?? false)->toBeFalse();

    $target = ALIAS_TEST_LOGICAL . '_clone';
    cleanupAliasTest();

    try {
        expect(Typesense::$plugin->getAliases()->clone('heroes', $target))->toBeFalse();

        $client = Typesense::$plugin->getClient()->client();
        $exists = true;

        try {
            $client->collections[$target]->retrieve();
        } catch (Throwable) {
            $exists = false;
        }

        expect($exists)->toBeFalse();
    } finally {
        cleanupAliasTest();
    }
});

it('lists aliases and resolves a plain collection to itself', function() {
    $client = Typesense::$plugin->getClient()->client();
    $aliases = Typesense::$plugin->getAliases();
    $plain = ALIAS_TEST_LOGICAL . '_plain';

    cleanupAliasTest();

    try {
        $client->collections->create(['name' => $plain, 'fields' => [['name' => 'title', 'type' => 'string']]]);

        // A plain collection resolves to itself (not via an alias).
        expect($aliases->resolve($plain))->toBe($plain)
            ->and($aliases->resolve('ts_nonexistent_xyz'))->toBeNull();

        $client->aliases->upsert(ALIAS_TEST_LOGICAL, ['collection_name' => $plain]);
        expect($aliases->all())->toHaveKey(ALIAS_TEST_LOGICAL)
            ->and($aliases->all()[ALIAS_TEST_LOGICAL])->toBe($plain);
    } finally {
        cleanupAliasTest();
    }
});
