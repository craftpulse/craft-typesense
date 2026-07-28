<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The synonyms dual-shape gate. Presence-based ownership (declaring synonyms in
 * config marks config-ownership) is pure logic. The seed/list/clear lifecycle
 * runs against the real
 * Typesense server: on v28/v29 through the per-collection SDK path, on v30.2+
 * through the global synonym-sets path (exercised by the dual-container gate).
 * Callers see identical behaviour either way.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\enums\MultisiteStrategy;
use craftpulse\typesense\Typesense;

const SYNONYMS_TEST_COLLECTION = 'ts_synonyms_test';

function dropSynonymsCollection(): void
{
    $client = Typesense::$plugin->getClient()->client();

    if ($client !== null) {
        try {
            // Prefix-aware: withSynonyms() below creates the physical
            // collection via getCreateSchema(), which already resolves it
            // through the registry (see TYPESENSE_COLLECTION_PREFIX). A raw,
            // unprefixed delete here would miss it, leaking the physical
            // collection across runs.
            $client->collections[Typesense::$plugin->getClient()->prefixedCollectionName(SYNONYMS_TEST_COLLECTION)]->delete();
        } catch (Throwable) {
            // already gone
        }
    }
}

/**
 * Registers a declared collection, creates it on the server, runs the test, and
 * restores state.
 *
 * @param Collection $declared
 * @param callable $test
 */
function withSynonyms(Collection $declared, callable $test): void
{
    $settings = Typesense::$plugin->getSettings();
    $original = $settings->collections;
    $settings->collections = [$declared];
    $client = Typesense::$plugin->getClient()->client();
    $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;
    dropSynonymsCollection();

    try {
        $client->collections->create(Typesense::$plugin->getCollectionRegistry()->getCreateSchema($declared, $primarySiteId));
        $test();
    } finally {
        dropSynonymsCollection();
        $settings->collections = $original;
    }
}

it('treats a collection as config-owned only when it declares synonyms (presence-based)', function() {
    $synonyms = Typesense::$plugin->getSynonyms();
    $cpOwned = Collection::make(SYNONYMS_TEST_COLLECTION);
    $configOwned = Collection::make(SYNONYMS_TEST_COLLECTION)
        ->synonymDefinitions([['id' => 'outerwear', 'synonyms' => ['blazer', 'coat']]]);

    expect($synonyms->isConfigOwned($cpOwned))->toBeFalse()
        ->and($synonyms->isConfigOwned($configOwned))->toBeTrue();
});

it('seeds, lists and clears config-declared synonyms against the real server', function() {
    $declared = Collection::make(SYNONYMS_TEST_COLLECTION)
        ->multisite(MultisiteStrategy::SharedWithSiteFilter)
        ->synonymDefinitions([
            ['id' => 'outerwear', 'synonyms' => ['blazer', 'coat', 'jacket']],
        ]);

    withSynonyms($declared, function() use ($declared) {
        $synonyms = Typesense::$plugin->getSynonyms();
        $synonyms->seedFromConfig($declared);

        $all = $synonyms->all($declared);
        expect($all)->toHaveCount(1)
            ->and($all[0]['synonyms'])->toContain('blazer');

        $synonyms->clear($declared);
        expect($synonyms->all($declared))->toBe([]);
    });
});

it('upserts one-way and multi-way synonyms and deletes one (the manager round-trip)', function() {
    $declared = Collection::make(SYNONYMS_TEST_COLLECTION)
        ->multisite(MultisiteStrategy::SharedWithSiteFilter);

    withSynonyms($declared, function() use ($declared) {
        $synonyms = Typesense::$plugin->getSynonyms();

        $synonyms->upsert($declared, 'outerwear', ['synonyms' => ['blazer', 'coat', 'jacket']]);
        $synonyms->upsert($declared, 'shoe-root', ['root' => 'shoe', 'synonyms' => ['sneaker', 'trainer']]);

        $all = $synonyms->all($declared);
        $byId = array_column($all, null, 'id');
        expect($all)->toHaveCount(2)
            ->and($byId['outerwear']['synonyms'])->toContain('coat')
            ->and($byId['shoe-root']['root'] ?? '')->toBe('shoe');

        $synonyms->deleteOne($declared, 'outerwear');
        $remaining = array_column($synonyms->all($declared), 'id');
        expect($remaining)->toBe(['shoe-root']);
    });
});

it('does not seed a collection that declares no synonyms in config (cp-owned)', function() {
    $declared = Collection::make(SYNONYMS_TEST_COLLECTION)
        ->multisite(MultisiteStrategy::SharedWithSiteFilter);

    withSynonyms($declared, function() use ($declared) {
        $synonyms = Typesense::$plugin->getSynonyms();
        $synonyms->seedFromConfig($declared);

        expect($synonyms->all($declared))->toBe([]);
    });
});
