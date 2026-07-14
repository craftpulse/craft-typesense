<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The synonyms dual-shape gate. managedBy resolution and the config-override
 * notice are pure logic. The seed/list/clear lifecycle runs against the real
 * Typesense server: on v28/v29 through the per-collection SDK path, on v30.2+
 * through the global synonym-sets path (exercised by the dual-container gate).
 * Callers see identical behaviour either way.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\enums\MultisiteStrategy;
use craftpulse\typesense\models\Settings;
use craftpulse\typesense\Typesense;

const SYNONYMS_TEST_COLLECTION = 'ts_synonyms_test';

function dropSynonymsCollection(): void
{
    $client = Typesense::$plugin->getClient()->client();

    if ($client !== null) {
        try {
            $client->collections[SYNONYMS_TEST_COLLECTION]->delete();
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

it('resolves managedBy from the settings default when no override is declared', function() {
    $collection = Collection::make(SYNONYMS_TEST_COLLECTION);
    Typesense::$plugin->getSettings()->synonymsManagedBy = Settings::MANAGED_BY_CP;

    expect(Typesense::$plugin->getSynonyms()->getManagedBy($collection))->toBe(Settings::MANAGED_BY_CP)
        ->and(Typesense::$plugin->getSynonyms()->isConfigOverridden($collection))->toBeFalse();

    Typesense::$plugin->getSettings()->synonymsManagedBy = Settings::MANAGED_BY_CONFIG;
});

it('lets a per-collection override win over the settings default (config-override notice)', function() {
    Typesense::$plugin->getSettings()->synonymsManagedBy = Settings::MANAGED_BY_CP;
    $collection = Collection::make(SYNONYMS_TEST_COLLECTION)->synonyms(Settings::MANAGED_BY_CONFIG);

    expect(Typesense::$plugin->getSynonyms()->getManagedBy($collection))->toBe(Settings::MANAGED_BY_CONFIG)
        ->and(Typesense::$plugin->getSynonyms()->isConfigOverridden($collection))->toBeTrue();

    Typesense::$plugin->getSettings()->synonymsManagedBy = Settings::MANAGED_BY_CONFIG;
});

it('seeds, lists and clears config-declared synonyms against the real server', function() {
    $declared = Collection::make(SYNONYMS_TEST_COLLECTION)
        ->multisite(MultisiteStrategy::SharedWithSiteFilter)
        ->synonyms(Settings::MANAGED_BY_CONFIG)
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

it('does not seed synonyms for a cp-managed collection', function() {
    $declared = Collection::make(SYNONYMS_TEST_COLLECTION)
        ->multisite(MultisiteStrategy::SharedWithSiteFilter)
        ->synonyms(Settings::MANAGED_BY_CP)
        ->synonymDefinitions([
            ['id' => 'outerwear', 'synonyms' => ['blazer', 'coat']],
        ]);

    withSynonyms($declared, function() use ($declared) {
        $synonyms = Typesense::$plugin->getSynonyms();
        $synonyms->seedFromConfig($declared);

        expect($synonyms->all($declared))->toBe([]);
    });
});
