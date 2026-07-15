<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The curation service, dual-shape behind one interface. Presence-based
 * ownership (declaring curation rules in config marks config-ownership) is pure
 * logic. The seed/list/clear lifecycle runs against the real server:
 * per-collection overrides on v28/v29, global curation sets on v30.2+ (exercised
 * by the dual-container gate).
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\builders\Field;
use craftpulse\typesense\enums\MultisiteStrategy;
use craftpulse\typesense\Typesense;

const CURATION_TEST_COLLECTION = 'ts_curation_test';

function dropCurationCollection(): void
{
    $client = Typesense::$plugin->getClient()->client();

    if ($client !== null) {
        try {
            $client->collections[CURATION_TEST_COLLECTION]->delete();
        } catch (Throwable) {
            // already gone
        }
    }
}

/**
 * @param Collection $declared
 * @param callable $test
 */
function withCuration(Collection $declared, callable $test): void
{
    $settings = Typesense::$plugin->getSettings();
    $original = $settings->collections;
    $settings->collections = [$declared];
    $client = Typesense::$plugin->getClient()->client();
    $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;
    dropCurationCollection();

    try {
        $client->collections->create(Typesense::$plugin->getCollectionRegistry()->getCreateSchema($declared, $primarySiteId));
        $test();
    } finally {
        dropCurationCollection();
        $settings->collections = $original;
    }
}

it('treats a collection as config-owned only when it declares curation rules (presence-based)', function() {
    $curation = Typesense::$plugin->getCuration();
    $cpOwned = Collection::make(CURATION_TEST_COLLECTION);
    $configOwned = Collection::make(CURATION_TEST_COLLECTION)->curationRules([
        ['id' => 'pin-coat', 'rule' => ['query' => 'coat', 'match' => 'exact'], 'includes' => [['id' => '1', 'position' => 1]]],
    ]);

    expect($curation->isConfigOwned($cpOwned))->toBeFalse()
        ->and($curation->isConfigOwned($configOwned))->toBeTrue();
});

it('seeds, lists and clears config-declared curation rules against the real server', function() {
    $declared = Collection::make(CURATION_TEST_COLLECTION)
        ->multisite(MultisiteStrategy::SharedWithSiteFilter)
        ->curationRules([
            [
                'id' => 'pin-coat',
                'rule' => ['query' => 'coat', 'match' => 'exact'],
                'includes' => [['id' => '1', 'position' => 1]],
            ],
        ])
        ->fields(Field::string('title'));

    withCuration($declared, function() use ($declared) {
        $curation = Typesense::$plugin->getCuration();
        $curation->seedFromConfig($declared);

        $all = $curation->all($declared);
        expect($all)->toHaveCount(1)
            ->and($all[0]['id'])->toBe('pin-coat');

        $curation->clear($declared);
        expect($curation->all($declared))->toBe([]);
    });
});
