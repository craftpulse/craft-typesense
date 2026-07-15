<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The P10 relevance gate: a boost rule authored on a control-panel-managed
 * collection compiles to an indexed boost_score field, is baked into documents
 * at index time, and re-ranks a known query's results DETERMINISTICALLY on the
 * real server. Two documents match the query equally on text; the boosted one
 * ranks first purely because of its baked score. Runs against the real server;
 * the collection is removed afterwards.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\builders\Field;
use craftpulse\typesense\enums\MultisiteStrategy;
use craftpulse\typesense\Typesense;

const BOOST_TEST_COLLECTION = 'ts_boost_test';

it('re-ranks a query deterministically via a baked boost_score', function() {
    $relevance = Typesense::$plugin->getRelevance();
    $boostRules = [
        ['weight' => 100, 'match' => 'all', 'conditions' => [['field' => 'brand', 'operator' => 'eq', 'value' => 'acme']]],
    ];

    // The compiled preset sorts by text match, then by the baked boost score.
    $preset = ['query_by' => 'title'] + $relevance->presetFragment(
        [],
        Typesense::$plugin->getClient()->getServerCapabilities(),
        true,
    );

    $declared = Collection::make(BOOST_TEST_COLLECTION)
        ->multisite(MultisiteStrategy::SharedWithSiteFilter)
        ->preset($preset)
        ->boost($boostRules)
        ->fields(
            Field::string('title'),
            Field::string('brand')->facet(),
            Field::make('boost_score', 'float')->optional()->sort(),
        );

    $settings = Typesense::$plugin->getSettings();
    $original = $settings->collections;
    $settings->collections = [$declared];
    $client = Typesense::$plugin->getClient()->client();
    $registry = Typesense::$plugin->getCollectionRegistry();
    $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;
    $target = $registry->resolveName($declared, $primarySiteId);

    try {
        $client->collections[$target]->delete();
    } catch (Throwable) {
        // not present
    }

    try {
        $client->collections->create($registry->getCreateSchema($declared, $primarySiteId));
        Typesense::$plugin->getPresets()->seedFromConfig($declared);

        // Both match "widget" on title equally; only the acme one is boosted.
        $client->collections[$target]->documents->create(['id' => '1', 'title' => 'Widget', 'brand' => 'other', 'elementId' => 1, 'siteId' => $primarySiteId, 'boost_score' => 0]);
        $client->collections[$target]->documents->create(['id' => '2', 'title' => 'Widget', 'brand' => 'acme', 'elementId' => 2, 'siteId' => $primarySiteId, 'boost_score' => 100]);

        $result = Typesense::$plugin->getSearch()->search(BOOST_TEST_COLLECTION, ['q' => 'widget']);

        expect($result['found'])->toBe(2)
            ->and($result['hits'][0]['document']['id'])->toBe('2')
            ->and($result['hits'][1]['document']['id'])->toBe('1');
    } finally {
        try {
            $client->collections[$target]->delete();
        } catch (Throwable) {
            // already gone
        }
        $settings->collections = $original;
    }
});
