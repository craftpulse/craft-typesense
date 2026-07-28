<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The front-end search layer. An unknown collection and an unreachable query
 * both fail soft (a well-formed empty result, never an error). A registered
 * collection resolves to its prefixed, site-aware target, is enriched with its
 * declared preset, and returns real hits. Multi-search unions several
 * collections. Runs against the real Typesense server.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\builders\Field;
use craftpulse\typesense\enums\MultisiteStrategy;
use craftpulse\typesense\Typesense;

const SEARCH_TEST_COLLECTION = 'ts_search_test';

function dropSearchCollection(): void
{
    $client = Typesense::$plugin->getClient()->client();

    if ($client !== null) {
        try {
            // Prefix-aware: withSearch() below creates the physical collection
            // via getCreateSchema(), which already resolves it through the
            // registry (see TYPESENSE_COLLECTION_PREFIX). A raw, unprefixed
            // delete here would miss it, leaking the physical collection
            // across runs.
            $client->collections[Typesense::$plugin->getClient()->prefixedCollectionName(SEARCH_TEST_COLLECTION)]->delete();
        } catch (Throwable) {
            // already gone
        }
    }
}

/**
 * Registers a declared collection, creates it, seeds its preset, indexes the
 * given documents, runs the test, and restores state.
 *
 * @param Collection $declared
 * @param array<int, array<string, mixed>> $documents
 * @param callable $test
 */
function withSearch(Collection $declared, array $documents, callable $test): void
{
    $settings = Typesense::$plugin->getSettings();
    $original = $settings->collections;
    $settings->collections = [$declared];
    $client = Typesense::$plugin->getClient()->client();
    $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;
    $target = Typesense::$plugin->getCollectionRegistry()->resolveName($declared, $primarySiteId);
    dropSearchCollection();

    try {
        $client->collections->create(Typesense::$plugin->getCollectionRegistry()->getCreateSchema($declared, $primarySiteId));
        Typesense::$plugin->getPresets()->seedFromConfig($declared);

        foreach ($documents as $document) {
            $client->collections[$target]->documents->create($document);
        }

        $test();
    } finally {
        dropSearchCollection();
        $settings->collections = $original;
    }
}

it('fails soft with a well-formed empty result for an unknown collection', function() {
    $result = Typesense::$plugin->getSearch()->search('does_not_exist_at_all', [
        'q' => 'anything',
        'query_by' => 'title',
    ]);

    expect($result['found'])->toBe(0)
        ->and($result['hits'])->toBe([]);
});

it('resolves the target, applies the declared preset and returns real hits', function() {
    $declared = Collection::make(SEARCH_TEST_COLLECTION)
        ->multisite(MultisiteStrategy::SharedWithSiteFilter)
        ->preset(['query_by' => 'title', 'num_typos' => 2])
        ->fields(Field::string('title'));

    withSearch($declared, [
        ['id' => '1', 'title' => 'Winter coat', 'elementId' => 1, 'siteId' => 1],
        ['id' => '2', 'title' => 'Summer hat', 'elementId' => 2, 'siteId' => 1],
    ], function() {
        $result = Typesense::$plugin->getSearch()->search(SEARCH_TEST_COLLECTION, ['q' => 'coat']);

        expect($result['found'])->toBe(1)
            ->and($result['hits'][0]['document']['title'])->toBe('Winter coat');
    });
});

it('unions several collections through multi-search', function() {
    $declared = Collection::make(SEARCH_TEST_COLLECTION)
        ->multisite(MultisiteStrategy::SharedWithSiteFilter)
        ->fields(Field::string('title'));

    withSearch($declared, [
        ['id' => '1', 'title' => 'Winter coat', 'elementId' => 1, 'siteId' => 1],
    ], function() {
        $result = Typesense::$plugin->getSearch()->multiSearch([
            ['collection' => SEARCH_TEST_COLLECTION, 'q' => 'coat', 'query_by' => 'title'],
        ]);

        expect($result['results'])->toHaveCount(1)
            ->and($result['results'][0]['found'])->toBe(1);
    });
});
