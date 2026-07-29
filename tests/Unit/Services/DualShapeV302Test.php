<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The dual-shape parity gate against a real Typesense 30.2 server. The v28/v29
 * suites cover the per-collection SDK path; this suite points the plugin at a
 * throwaway 30.2 instance (TYPESENSE_V302_HOST) and drives the same public
 * synonyms and curation interface, proving the global synonym_sets and
 * curation_sets raw-HTTP path yields identical behaviour. Search enriches with
 * synonym_sets on this shape. Skipped unless the 30.2 host is provided.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\builders\Field;
use craftpulse\typesense\enums\MultisiteStrategy;
use craftpulse\typesense\services\Client;
use craftpulse\typesense\Typesense;

const V302_TEST_COLLECTION = 'ts_v302_test';

/**
 * Repoints the plugin at the 30.2 host for the duration of the callback, then
 * restores the original server settings and client component.
 *
 * @param callable $test
 */
function withV302(callable $test): void
{
    /** @var Settings $settings */
    $settings = Typesense::$plugin->getSettings();
    $originalServer = $settings->server;
    $originalPort = $settings->port;
    $originalProtocol = $settings->protocol;

    $settings->server = getenv('TYPESENSE_V302_HOST') ?: 'ts-v302-gate';
    $settings->port = '8108';
    $settings->protocol = 'http';
    Typesense::$plugin->set('client', new Client());

    try {
        $test();
    } finally {
        $settings->server = $originalServer;
        $settings->port = $originalPort;
        $settings->protocol = $originalProtocol;
        Typesense::$plugin->set('client', new Client());
    }
}

it('detects the 30.2 server and elects the set-based shape', function() {
    withV302(function() {
        expect(Typesense::$plugin->getClient()->getServerVersion())->toStartWith('30.')
            ->and(Typesense::$plugin->getSynonyms()->usesSets())->toBeTrue()
            ->and(Typesense::$plugin->getCuration()->usesSets())->toBeTrue();
    });
})->skip(getenv('TYPESENSE_V302_HOST') === false && !@fsockopen('ts-v302-gate', 8108), 'Set TYPESENSE_V302_HOST to run the 30.2 parity gate.');

it('seeds, lists and clears synonyms through the global synonym_sets path', function() {
    withV302(function() {
        $collection = Collection::make(V302_TEST_COLLECTION)
            ->multisite(MultisiteStrategy::SharedWithSiteFilter)
            ->synonymDefinitions([['id' => 'outerwear', 'synonyms' => ['blazer', 'coat', 'jacket']]]);
        $synonyms = Typesense::$plugin->getSynonyms();

        try {
            $synonyms->seedFromConfig($collection);

            $all = $synonyms->all($collection);
            expect($all)->toHaveCount(1)
                ->and($all[0]['synonyms'])->toContain('blazer');
        } finally {
            $synonyms->clear($collection);
        }

        expect($synonyms->all($collection))->toBe([]);
    });
})->skip(getenv('TYPESENSE_V302_HOST') === false && !@fsockopen('ts-v302-gate', 8108), 'Set TYPESENSE_V302_HOST to run the 30.2 parity gate.');

it('seeds, lists and clears curation through the global curation_sets path', function() {
    withV302(function() {
        $collection = Collection::make(V302_TEST_COLLECTION)
            ->multisite(MultisiteStrategy::SharedWithSiteFilter)
            ->curationRules([
                [
                    'id' => 'pin-coat',
                    'rule' => ['query' => 'coat', 'match' => 'exact'],
                    'includes' => [['id' => '1', 'position' => 1]],
                ],
            ]);
        $curation = Typesense::$plugin->getCuration();

        try {
            $curation->seedFromConfig($collection);

            $all = $curation->all($collection);
            expect($all)->toHaveCount(1)
                ->and($all[0]['id'])->toBe('pin-coat');
        } finally {
            $curation->clear($collection);
        }

        expect($curation->all($collection))->toBe([]);
    });
})->skip(getenv('TYPESENSE_V302_HOST') === false && !@fsockopen('ts-v302-gate', 8108), 'Set TYPESENSE_V302_HOST to run the 30.2 parity gate.');

it('returns real hits from a search on the 30.2 server', function() {
    withV302(function() {
        $declared = Collection::make(V302_TEST_COLLECTION)
            ->multisite(MultisiteStrategy::SharedWithSiteFilter)
            ->preset(['query_by' => 'title', 'num_typos' => 2])
            ->fields(Field::string('title'));
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
            $client->collections[$target]->documents->create(['id' => '1', 'title' => 'Winter coat', 'elementId' => 1, 'siteId' => 1]);

            $result = Typesense::$plugin->getSearch()->search(V302_TEST_COLLECTION, ['q' => 'coat']);
            expect($result['found'])->toBe(1)
                ->and($result['hits'][0]['document']['title'])->toBe('Winter coat');
        } finally {
            try {
                $client->collections[$target]->delete();
            } catch (Throwable) {
                // already gone
            }
            $settings->collections = $original;
        }
    });
})->skip(getenv('TYPESENSE_V302_HOST') === false && !@fsockopen('ts-v302-gate', 8108), 'Set TYPESENSE_V302_HOST to run the 30.2 parity gate.');

it('opens the native personalization gate on the 30.2 server', function() {
    withV302(function() {
        $client = Typesense::$plugin->getClient();
        $analytics = Typesense::$plugin->getAnalytics();
        $destination = 'ts_v302_personalization_log';

        // The gate is open on v30.2 (unlike v28, where it refuses). The log-rule
        // wiring is best-effort for an undocumented feature, so the gate result
        // is what is pinned, not the server's acceptance of the rule shape.
        // ensureDestination() resolves $destination through
        // Client::prefixedCollectionName(), so the physical collection created
        // (and cleaned up below) is the prefixed name, not the bare logical one.
        expect($client->getServerCapabilities()?->personalizationModels())->toBeTrue()
            ->and($analytics->configurePersonalizationLog('ts_v302_test', $destination))->toBeTrue();

        $analytics->deleteRule('ts_v302_test_personalization_log');

        try {
            $client->client()->collections[$client->prefixedCollectionName($destination)]->delete();
        } catch (Throwable) {
            // already gone (or the undocumented rule shape was rejected)
        }
    });
})->skip(getenv('TYPESENSE_V302_HOST') === false && !@fsockopen('ts-v302-gate', 8108), 'Set TYPESENSE_V302_HOST to run the 30.2 parity gate.');

it('clones a collection schema via src_name on the 30.2 server', function() {
    withV302(function() {
        $client = Typesense::$plugin->getClient()->client();
        $source = 'ts_v302_clone_src';
        $target = 'ts_v302_clone_dst';

        expect(Typesense::$plugin->getClient()->getServerCapabilities()?->collectionCloning())->toBeTrue();

        foreach ([$source, $target] as $name) {
            try {
                $client->collections[$name]->delete();
            } catch (Throwable) {
                // not present
            }
        }

        try {
            $client->collections->create(['name' => $source, 'fields' => [['name' => 'title', 'type' => 'string']]]);

            expect(Typesense::$plugin->getAliases()->clone($source, $target))->toBeTrue();

            // The clone carries the source schema.
            $fields = array_column($client->collections[$target]->retrieve()['fields'] ?? [], 'name');
            expect($fields)->toContain('title');
        } finally {
            foreach ([$source, $target] as $name) {
                try {
                    $client->collections[$name]->delete();
                } catch (Throwable) {
                    // already gone
                }
            }
        }
    });
})->skip(getenv('TYPESENSE_V302_HOST') === false && !@fsockopen('ts-v302-gate', 8108), 'Set TYPESENSE_V302_HOST to run the 30.2 parity gate.');

it('upserts, lists, and deletes a synonym through the global synonym_sets path', function() {
    withV302(function() {
        $collection = Collection::make(V302_TEST_COLLECTION)
            ->multisite(MultisiteStrategy::SharedWithSiteFilter);
        $synonyms = Typesense::$plugin->getSynonyms();

        expect($synonyms->usesSets())->toBeTrue();

        try {
            $synonyms->upsert($collection, 'outerwear', ['synonyms' => ['blazer', 'coat', 'jacket']]);

            $all = $synonyms->all($collection);
            expect($all)->toHaveCount(1)
                ->and($all[0]['id'])->toBe('outerwear')
                ->and($all[0]['synonyms'])->toContain('coat');

            $synonyms->deleteOne($collection, 'outerwear');
            expect($synonyms->all($collection))->toBe([]);
        } finally {
            $synonyms->clear($collection);
        }
    });
})->skip(getenv('TYPESENSE_V302_HOST') === false && !@fsockopen('ts-v302-gate', 8108), 'Set TYPESENSE_V302_HOST to run the 30.2 parity gate.');

it('upserts, lists, and deletes a curation rule through the global curation_sets path', function() {
    withV302(function() {
        $collection = Collection::make(V302_TEST_COLLECTION)
            ->multisite(MultisiteStrategy::SharedWithSiteFilter);
        $curation = Typesense::$plugin->getCuration();

        expect($curation->usesSets())->toBeTrue();

        try {
            // upsert creates the curation set (read-modify-write whole set).
            $curation->upsert($collection, 'pin-coat', [
                'rule' => ['query' => 'coat', 'match' => 'exact'],
                'includes' => [['id' => '1', 'position' => 1]],
            ]);

            $all = $curation->all($collection);
            expect($all)->toHaveCount(1)
                ->and($all[0]['id'])->toBe('pin-coat')
                ->and(array_column($all[0]['includes'] ?? [], 'id'))->toContain('1');

            $curation->deleteRule($collection, 'pin-coat');
            expect($curation->all($collection))->toBe([]);
        } finally {
            $curation->clear($collection);
        }
    });
})->skip(getenv('TYPESENSE_V302_HOST') === false && !@fsockopen('ts-v302-gate', 8108), 'Set TYPESENSE_V302_HOST to run the 30.2 parity gate.');
