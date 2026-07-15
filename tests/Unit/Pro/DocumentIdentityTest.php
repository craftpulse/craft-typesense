<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Document identity across the multisite strategies: a sharedWithSiteFilter
 * collection whose scope spans more than one site gives each site its own
 * document (composite id), while a single-site scope and collectionPerSite keep
 * the bare element id, so existing document identity never churns where there is
 * no collision. Deletion by the reserved elementId field removes every document
 * of an element regardless of id shape. Runs against the real server.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\Entry;
use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\builders\Field;
use craftpulse\typesense\enums\MultisiteStrategy;
use craftpulse\typesense\Typesense;

const IDENTITY_TEST_COLLECTION = 'ts_identity_test';

it('derives composite ids only for a multi-site shared collection', function() {
    $documents = Typesense::$plugin->getDocuments();
    $shared = Collection::make('x')->multisite(MultisiteStrategy::SharedWithSiteFilter);
    $perSite = Collection::make('x')->multisite(MultisiteStrategy::CollectionPerSite);

    // A shared collection spanning more than one site: composite.
    expect($documents->defaultDocumentId($shared, 42, 2, [1, 2]))->toBe('42-2');

    // A single-site shared scope keeps the bare id (no collision, no churn).
    expect($documents->defaultDocumentId($shared, 42, 1, [1]))->toBe('42');

    // collectionPerSite always keeps the bare id (a collection per site).
    expect($documents->defaultDocumentId($perSite, 42, 2, [1, 2]))->toBe('42');
});

it('builds distinct per-site documents for a multi-site shared collection', function() {
    $entry = Entry::find()->section('heroes')->siteId(1)->one();
    $collection = Collection::make(IDENTITY_TEST_COLLECTION)
        ->elementType(Entry::class)
        ->multisite(MultisiteStrategy::SharedWithSiteFilter)
        ->transform(static fn(Entry $element): array => ['title' => (string)$element->title]);

    $documents = Typesense::$plugin->getDocuments();
    $forSiteOne = $documents->build($collection, $entry, 1)->documents[0];
    $forSiteTwo = $documents->build($collection, $entry, 2)->documents[0];

    expect($forSiteOne['id'])->toBe($entry->id . '-1')
        ->and($forSiteTwo['id'])->toBe($entry->id . '-2')
        ->and($forSiteOne['id'])->not->toBe($forSiteTwo['id'])
        ->and((int)$forSiteOne['elementId'])->toBe((int)$entry->id)
        ->and((int)$forSiteOne['siteId'])->toBe(1)
        ->and((int)$forSiteTwo['siteId'])->toBe(2);
});

it('keeps the heroes documents on their bare element ids (no identity churn)', function() {
    $client = Typesense::$plugin->getClient()->client();
    $result = $client->collections['heroes']->documents->search(['q' => '*', 'query_by' => 'title', 'per_page' => 1]);

    expect($result['found'])->toBe(488)
        ->and($result['hits'][0]['document']['id'])->not->toContain('-');
});

it('deletes every document of an element regardless of id shape', function() {
    $settings = Typesense::$plugin->getSettings();
    $original = $settings->collections;
    $collection = Collection::make(IDENTITY_TEST_COLLECTION)
        ->elementType(Entry::class)
        ->multisite(MultisiteStrategy::SharedWithSiteFilter)
        ->fields(Field::string('title'));
    $settings->collections = [$collection];

    $sync = Typesense::$plugin->getSync();
    $registry = Typesense::$plugin->getCollectionRegistry();
    $client = Typesense::$plugin->getClient()->client();
    $target = $registry->resolveName($collection, Craft::$app->getSites()->getPrimarySite()->id);

    try {
        try {
            $client->collections[$target]->delete();
        } catch (Throwable) {
            // not present
        }

        $sync->ensureCollectionExists($collection, 1);

        // Two composite documents for one element, one per site.
        $client->collections[$target]->documents->upsert(['id' => '900-1', 'title' => 'a', 'elementId' => 900, 'siteId' => 1]);
        $client->collections[$target]->documents->upsert(['id' => '900-2', 'title' => 'b', 'elementId' => 900, 'siteId' => 2]);
        expect($client->collections[$target]->retrieve()['num_documents'])->toBe(2);

        $sync->deleteDocuments($collection, 1, [900]);

        expect($client->collections[$target]->retrieve()['num_documents'])->toBe(0);
    } finally {
        try {
            $client->collections[$target]->delete();
        } catch (Throwable) {
            // already gone
        }
        $settings->collections = $original;
    }
});
