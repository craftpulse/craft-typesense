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

/**
 * Resolves the physical, prefix-aware Typesense collection name for the
 * declared drift-test collection. Drift::diff() reads every declared
 * collection through the same registry resolver (see
 * Collections::resolveName()), so a raw, unprefixed
 * `$client->collections[DRIFT_TEST_COLLECTION]` call here would create or
 * inspect a physical collection Drift never looks at under this suite's
 * TYPESENSE_COLLECTION_PREFIX isolation - it would report every finding as
 * "missing" regardless of the live schema this test hands it.
 *
 * @return string
 */
function driftTestTarget(): string
{
    return Typesense::$plugin->getClient()->prefixedCollectionName(DRIFT_TEST_COLLECTION);
}

function dropDriftCollection(): void
{
    $client = Typesense::$plugin->getClient()->client();

    if ($client !== null) {
        try {
            $client->collections[driftTestTarget()]->delete();
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
        $client->collections->create(['name' => driftTestTarget(), 'fields' => $liveFields]);

        // Select this collection's finding by name rather than assuming it is
        // first: other registry collections (config, control-panel-managed, or
        // event-registered) may also be present.
        $findings = array_values(array_filter(
            Typesense::$plugin->getDrift()->diff(),
            static fn(array $finding): bool => $finding['collection'] === DRIFT_TEST_COLLECTION,
        ));
        $test($findings[0] ?? []);
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

it('detects synonyms drift for a config-managed collection, then clears once seeded', function() {
    $declared = Collection::make(DRIFT_TEST_COLLECTION)
        ->multisite(MultisiteStrategy::SharedWithSiteFilter)
        ->synonymDefinitions([['id' => 'outerwear', 'synonyms' => ['coat', 'jacket']]])
        ->fields(Field::string('title'));

    withDrift($declared, [
        ['name' => 'title', 'type' => 'string'],
        ['name' => 'elementId', 'type' => 'int64'],
        ['name' => 'siteId', 'type' => 'int32'],
    ], function() use ($declared) {
        // Scope to this collection's synonyms finding: other registry
        // collections may contribute synonyms findings of their own.
        $synonymsFinding = fn(): array => array_values(array_filter(
            Typesense::$plugin->getDrift()->diff(),
            static fn(array $finding): bool => ($finding['collection'] ?? null) === DRIFT_TEST_COLLECTION
                && ($finding['aspect'] ?? null) === 'synonyms',
        ))[0] ?? [];

        expect($synonymsFinding()['status'])->toBe(Drift::STATUS_DRIFTED)
            ->and($synonymsFinding()['details']['missing'])->toContain('outerwear');

        Typesense::$plugin->getSynonyms()->seedFromConfig($declared);

        expect($synonymsFinding()['status'])->toBe(Drift::STATUS_IN_SYNC);

        Typesense::$plugin->getSynonyms()->clear($declared);
    });
});
