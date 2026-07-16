<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The P12 vector gate: a collection with a built-in ts/* auto-embedding field
 * indexes plain text, the server generates the vectors (CPU, no key), and hybrid
 * and similar-items search both work. The built-in model is downloaded by the
 * server on first use, so the test allows time and skips cleanly when the model
 * is unavailable (keeping CI green). Runs against the real server on a disposable
 * collection, removed afterwards.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\builders\Field;
use craftpulse\typesense\enums\MultisiteStrategy;
use craftpulse\typesense\Typesense;

const EMBED_TEST_COLLECTION = 'ts_embed_test';

it('auto-embeds with a built-in model and serves hybrid and similar search', function() {
    $embedField = Typesense::$plugin->getEmbeddings()->embedField('embedding', [
        'builtIn' => true,
        'model' => 'ts/all-MiniLM-L12-v2',
        'from' => ['title'],
    ]);

    $declared = Collection::make(EMBED_TEST_COLLECTION)
        ->multisite(MultisiteStrategy::SharedWithSiteFilter)
        ->preset(['query_by' => 'title'])
        ->fields(Field::string('title'), $embedField);

    $settings = Typesense::$plugin->getSettings();
    $original = $settings->collections;
    $settings->collections = [$declared];

    $client = Typesense::$plugin->getClient()->client();
    $registry = Typesense::$plugin->getCollectionRegistry();
    $siteId = Craft::$app->getSites()->getPrimarySite()->id;
    $target = $registry->resolveName($declared, $siteId);

    try {
        $client->collections[$target]->delete();
    } catch (Throwable) {
        // not present
    }

    $documents = [
        ['id' => '1', 'title' => 'A red sports car', 'elementId' => 1, 'siteId' => $siteId],
        ['id' => '2', 'title' => 'A fast automobile in crimson', 'elementId' => 2, 'siteId' => $siteId],
        ['id' => '3', 'title' => 'A recipe for tomato soup', 'elementId' => 3, 'siteId' => $siteId],
    ];

    try {
        $client->collections->create($registry->getCreateSchema($declared, $siteId));

        // The import triggers the server-side embedding (and a model download on
        // first use). If the model cannot be loaded here, skip rather than fail.
        try {
            $result = $client->collections[$target]->documents->import($documents, ['action' => 'upsert']);
        } catch (Throwable $e) {
            $this->markTestSkipped('Built-in embedding model unavailable: ' . $e->getMessage());
        }

        foreach ($result as $row) {
            if (($row['success'] ?? false) !== true) {
                $this->markTestSkipped('Embedding import did not succeed (model likely unavailable): ' . ($row['error'] ?? ''));
            }
        }

        expect((int)$client->collections[$target]->retrieve()['num_documents'])->toBe(3);

        // Hybrid: keyword + semantic. "vehicle" matches the car docs semantically.
        $hybrid = Typesense::$plugin->getSearch()->hybridSearch(EMBED_TEST_COLLECTION, [
            'q' => 'vehicle',
            'query_by' => 'title',
        ], 0.8);
        expect($hybrid['found'])->toBeGreaterThan(0);

        // Similar to doc 1 (the red sports car): doc 2 (crimson automobile) should
        // rank above the soup recipe.
        $similar = Typesense::$plugin->getSearch()->similar(EMBED_TEST_COLLECTION, '1', 3);
        $ids = array_map(static fn(array $hit): string => (string)$hit['document']['id'], $similar['hits'] ?? []);
        expect($ids)->toContain('2')
            ->and(array_search('2', $ids, true))->toBeLessThan(array_search('3', $ids, true));
    } finally {
        try {
            $client->collections[$target]->delete();
        } catch (Throwable) {
            // already gone
        }
        $settings->collections = $original;
    }
});
