<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The P9 curation gate: a pin created through the sidebar controller path lands
 * as an include (with position) on the matching rule on the real server, appears
 * in the element-to-rule lookup table, and surfaces the element at that position
 * in search. Runs against the real v28 server.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\builders\Field;
use craftpulse\typesense\enums\MultisiteStrategy;
use craftpulse\typesense\Typesense;

const PIN_TEST_COLLECTION = 'ts_pin_test';

it('pins an element from the sidebar path: include on the rule, lookup row, and search reflects it', function() {
    $suffix = bin2hex(random_bytes(4));
    $admin = new User();
    $admin->admin = true;
    $admin->username = "ts_pin_{$suffix}";
    $admin->email = "ts_pin_{$suffix}@example.test";
    Craft::$app->getElements()->saveElement($admin);

    // No config curation rules declared, so the collection is control-panel-owned
    // (editable), which the sidebar quick-pin requires.
    $collection = Collection::make(PIN_TEST_COLLECTION)
        ->elementType(Entry::class)
        ->multisite(MultisiteStrategy::SharedWithSiteFilter)
        ->fields(Field::string('title'))
        ->elementQuery(fn($query) => $query->section('heroes'))
        ->transform(fn(Entry $entry) => ['title' => (string)$entry->title]);

    $settings = Typesense::$plugin->getSettings();
    $original = $settings->collections;
    $settings->collections = [$collection];

    $client = Typesense::$plugin->getClient()->client();
    $registry = Typesense::$plugin->getCollectionRegistry();
    $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;
    $target = $registry->resolveName($collection, $primarySiteId);
    $entry = Entry::find()->section('heroes')->status('live')->siteId($primarySiteId)->one();

    try {
        try {
            $client->collections[$target]->delete();
        } catch (Throwable) {
            // not present
        }

        $sync = Typesense::$plugin->getSync();
        $sync->ensureCollectionExists($collection, $primarySiteId);
        $sync->indexElements($collection, $primarySiteId, [$entry->id]);

        $documentId = (string)(Typesense::$plugin->getDocuments()->build($collection, $entry, $primarySiteId)->documents[0]['id']);
        $query = "pin{$suffix}";

        $this->actingAs($admin)
            ->post(UrlHelper::actionUrl('typesense/curation/pin'), [
                'collection' => PIN_TEST_COLLECTION,
                'elementId' => $entry->id,
                'query' => $query,
                'position' => 1,
            ])
            ->assertRedirect();

        // The include landed on the matching rule server-side.
        $rules = Typesense::$plugin->getCuration()->all($collection);
        $rule = null;

        foreach ($rules as $candidate) {
            if (($candidate['rule']['query'] ?? null) === $query) {
                $rule = $candidate;
                break;
            }
        }

        expect($rule)->not->toBeNull()
            ->and(array_column($rule['includes'] ?? [], 'id'))->toContain($documentId);

        // The lookup table has the element's pin row.
        $pins = Typesense::$plugin->getCurationIndex()->forElement((int)$entry->id);
        $handles = array_column($pins, 'collectionHandle');
        expect($handles)->toContain(PIN_TEST_COLLECTION);

        // Search for the query surfaces the pinned document at position 1.
        $result = Typesense::$plugin->getSearch()->search(PIN_TEST_COLLECTION, ['q' => $query, 'query_by' => 'title']);
        expect((string)($result['hits'][0]['document']['id'] ?? ''))->toBe($documentId);
    } finally {
        try {
            Typesense::$plugin->getCuration()->clear($collection);
        } catch (Throwable) {
            // ignore
        }
        try {
            $client->collections[$target]->delete();
        } catch (Throwable) {
            // already gone
        }
        Typesense::$plugin->getCurationIndex()->clearForCollection(PIN_TEST_COLLECTION);
        $settings->collections = $original;
    }
});
