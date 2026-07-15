<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Covers the Sync engine: enable/suspend gating and full-sync/reconcile queueing
 * (orchestration), plus end-to-end indexing, deletion, and reconciliation against
 * the real Typesense server using the heroes entries as a read-only source into a
 * disposable test collection, and multisite collectionPerSite name resolution.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\db\Query;
use craft\elements\Entry;
use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\enums\MultisiteStrategy;
use craftpulse\typesense\Typesense;

const SYNC_TEST_COLLECTION = 'ts_sync_test';

function queuedJobExists(string $descriptionFragment): bool
{
    return (new Query())
        ->from('{{%queue}}')
        ->where(['like', 'description', $descriptionFragment])
        ->exists();
}

function syncTestCollection(): Collection
{
    return Collection::make(SYNC_TEST_COLLECTION)
        ->elementType(Entry::class)
        ->multisite(MultisiteStrategy::SharedWithSiteFilter)
        ->elementQuery(fn($query) => $query->section('heroes'))
        ->transform(fn(Entry $entry, callable $registerDependency) => [
            'id' => (string)$entry->id,
            'title' => (string)$entry->title,
        ]);
}

function dropSyncTestCollection(): void
{
    $client = Typesense::$plugin->getClient()->client();

    if ($client !== null) {
        try {
            $client->collections[SYNC_TEST_COLLECTION]->delete();
        } catch (Throwable) {
            // already gone
        }
    }
}

// =========================================================================
// Orchestration
// =========================================================================

it('is enabled when configured and not suspended', function() {
    $sync = Typesense::$plugin->getSync();
    $suspend = Typesense::$plugin->getSyncSuspend();

    try {
        $suspend->resume();
        expect($sync->isEnabled())->toBeTrue();

        $suspend->suspend();
        expect($sync->isSuspended())->toBeTrue()
            ->and($sync->isEnabled())->toBeFalse();
    } finally {
        $suspend->resume();
    }
});

it('reports a collection suspended when the global switch or its own row is set', function() {
    $suspend = Typesense::$plugin->getSyncSuspend();

    try {
        $suspend->suspend('heroes');
        expect($suspend->isCollectionSuspended('heroes'))->toBeTrue()
            ->and($suspend->isCollectionSuspended('minor-heroes'))->toBeFalse()
            ->and($suspend->isGloballySuspended())->toBeFalse()
            ->and($suspend->suspendedCollections())->toContain('heroes');

        $suspend->resume('heroes');
        expect($suspend->isCollectionSuspended('heroes'))->toBeFalse();

        // The global switch reports every collection as suspended.
        $suspend->suspend();
        expect($suspend->isSuspended('heroes'))->toBeTrue();
    } finally {
        $suspend->resume('heroes');
        $suspend->resume();
    }
});

it('queues a full sync job for a site', function() {
    Typesense::$plugin->getSync()->syncCollection(syncTestCollection(), 1);

    expect(queuedJobExists('Syncing ' . SYNC_TEST_COLLECTION))->toBeTrue();
});

it('queues a reconcile job', function() {
    Typesense::$plugin->getSync()->reconcile(syncTestCollection(), 1);

    expect(queuedJobExists('Reconciling ' . SYNC_TEST_COLLECTION))->toBeTrue();
});

it('resolves collectionPerSite names with a site handle suffix', function() {
    $collection = syncTestCollection()->multisite(MultisiteStrategy::CollectionPerSite);
    $registry = Typesense::$plugin->getCollectionRegistry();

    expect($registry->resolveName($collection, 2))->toBe(SYNC_TEST_COLLECTION . '_fr');
});

// =========================================================================
// End to end against the real server
// =========================================================================

it('indexes elements and deletes them on status change', function() {
    $collection = syncTestCollection();
    $sync = Typesense::$plugin->getSync();
    $client = Typesense::$plugin->getClient()->client();
    dropSyncTestCollection();

    try {
        $sync->ensureCollectionExists($collection, 1);
        $ids = Entry::find()->section('heroes')->status('live')->siteId(1)->limit(5)->ids();
        $sync->indexElements($collection, 1, $ids);

        expect($client->collections[SYNC_TEST_COLLECTION]->retrieve()['num_documents'])->toBe(count($ids));

        $sync->deleteDocuments($collection, 1, [$ids[0]]);

        expect($client->collections[SYNC_TEST_COLLECTION]->retrieve()['num_documents'])->toBe(count($ids) - 1);
    } finally {
        dropSyncTestCollection();
    }
});

it('removes a document end to end when the element leaves active statuses', function() {
    $sync = Typesense::$plugin->getSync();
    $client = Typesense::$plugin->getClient()->client();
    dropSyncTestCollection();

    try {
        $collection = syncTestCollection();
        $sync->ensureCollectionExists($collection, 1);
        $id = Entry::find()->section('heroes')->status('live')->siteId(1)->limit(1)->ids()[0];

        $sync->indexElements($collection, 1, [$id]);
        expect($client->collections[SYNC_TEST_COLLECTION]->retrieve()['num_documents'])->toBe(1);

        // A collection that only treats "pending" as active makes the live entry inactive.
        $strict = syncTestCollection()->activeStatuses(['pending']);
        $sync->indexElements($strict, 1, [$id]);

        expect($client->collections[SYNC_TEST_COLLECTION]->retrieve()['num_documents'])->toBe(0);
    } finally {
        dropSyncTestCollection();
    }
});

it('does not delete another site\'s documents when reconciling a shared collection', function() {
    $collection = syncTestCollection();
    $sync = Typesense::$plugin->getSync();
    $client = Typesense::$plugin->getClient()->client();
    dropSyncTestCollection();

    try {
        $sync->ensureCollectionExists($collection, 1);
        $ids = Entry::find()->section('heroes')->status('live')->siteId(1)->limit(4)->ids();
        $sync->indexElements($collection, 1, $ids);
        expect($client->collections[SYNC_TEST_COLLECTION]->retrieve()['num_documents'])->toBe(count($ids));

        // Reconciling a different site must leave site 1's documents intact.
        $sync->reconcileCollection($collection, 2);

        expect($client->collections[SYNC_TEST_COLLECTION]->retrieve()['num_documents'])->toBe(count($ids));
    } finally {
        dropSyncTestCollection();
    }
});

it('reconciles orphaned documents against the current query', function() {
    $collection = syncTestCollection();
    $sync = Typesense::$plugin->getSync();
    $client = Typesense::$plugin->getClient()->client();
    dropSyncTestCollection();

    try {
        $sync->ensureCollectionExists($collection, 1);
        $ids = Entry::find()->section('heroes')->status('live')->siteId(1)->limit(3)->ids();
        $sync->indexElements($collection, 1, $ids);

        $client->collections[SYNC_TEST_COLLECTION]->documents->upsert([
            'id' => 'orphan',
            'title' => 'orphan',
            'elementId' => 0,
            'siteId' => 1,
        ]);
        expect($client->collections[SYNC_TEST_COLLECTION]->retrieve()['num_documents'])->toBe(count($ids) + 1);

        $sync->reconcileCollection($collection, 1);

        expect($client->collections[SYNC_TEST_COLLECTION]->retrieve()['num_documents'])->toBe(count($ids));
    } finally {
        dropSyncTestCollection();
    }
});
