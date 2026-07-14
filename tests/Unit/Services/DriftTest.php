<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The P5 drift gate: the schema comparator detects a config change (a declared
 * field the live collection lacks) and a manual server-side edit (a live field
 * not declared), and reports in-sync when they match. Runs against the real
 * Typesense server with a disposable collection registered through the settings.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\builders\Field;
use craftpulse\typesense\enums\MultisiteStrategy;
use craftpulse\typesense\services\Drift;
use craftpulse\typesense\Typesense;

const DRIFT_TEST_COLLECTION = 'ts_drift_test';

function dropDriftCollection(): void
{
    $client = Typesense::$plugin->getClient()->client();

    if ($client !== null) {
        try {
            $client->collections[DRIFT_TEST_COLLECTION]->delete();
        } catch (Throwable) {
            // already gone
        }
    }
}

/**
 * Registers a declared collection and creates a live collection with the given
 * fields, runs the drift comparator, and restores state.
 *
 * @param Collection $declared
 * @param array<int, array<string, mixed>> $liveFields
 * @param callable $test
 */
function withDrift(Collection $declared, array $liveFields, callable $test): void
{
    $settings = Typesense::$plugin->getSettings();
    $original = $settings->collections;
    $settings->collections = [$declared];
    $client = Typesense::$plugin->getClient()->client();
    dropDriftCollection();

    try {
        $client->collections->create(['name' => DRIFT_TEST_COLLECTION, 'fields' => $liveFields]);
        $finding = Typesense::$plugin->getDrift()->diff()[0];
        $test($finding);
    } finally {
        dropDriftCollection();
        $settings->collections = $original;
    }
}

it('detects a config change: a declared field the server lacks', function() {
    $declared = Collection::make(DRIFT_TEST_COLLECTION)
        ->multisite(MultisiteStrategy::SharedWithSiteFilter)
        ->fields(Field::string('title'), Field::string('subtitle'));

    withDrift($declared, [
        ['name' => 'title', 'type' => 'string'],
        ['name' => 'elementId', 'type' => 'int64'],
        ['name' => 'siteId', 'type' => 'int32'],
    ], function(array $finding) {
        expect($finding['status'])->toBe(Drift::STATUS_DRIFTED)
            ->and($finding['details']['missingFields'])->toContain('subtitle');
    });
});

it('detects a manual server-side edit: a live field not declared', function() {
    $declared = Collection::make(DRIFT_TEST_COLLECTION)
        ->multisite(MultisiteStrategy::SharedWithSiteFilter)
        ->fields(Field::string('title'));

    withDrift($declared, [
        ['name' => 'title', 'type' => 'string'],
        ['name' => 'manualField', 'type' => 'string'],
        ['name' => 'elementId', 'type' => 'int64'],
        ['name' => 'siteId', 'type' => 'int32'],
    ], function(array $finding) {
        expect($finding['status'])->toBe(Drift::STATUS_DRIFTED)
            ->and($finding['details']['extraFields'])->toContain('manualField');
    });
});

it('reports in sync when declared and live schemas match', function() {
    $declared = Collection::make(DRIFT_TEST_COLLECTION)
        ->multisite(MultisiteStrategy::SharedWithSiteFilter)
        ->fields(Field::string('title'));

    withDrift($declared, [
        ['name' => 'title', 'type' => 'string'],
        ['name' => 'elementId', 'type' => 'int64'],
        ['name' => 'siteId', 'type' => 'int32'],
    ], function(array $finding) {
        expect($finding['status'])->toBe(Drift::STATUS_IN_SYNC);
    });
});
