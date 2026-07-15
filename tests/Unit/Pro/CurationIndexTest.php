<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The curation lookup table: rebuilding a collection's rows from its rules'
 * includes (element id resolved from the bare or composite document id), and the
 * per-element lookup the entry sidebar uses instead of scanning every override.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\typesense\Typesense;

const CI_TEST_COLLECTION = 'ts_ci_test';

it('projects rule includes into the lookup table, keyed by element id', function() {
    $index = Typesense::$plugin->getCurationIndex();

    try {
        $index->rebuildForCollection(CI_TEST_COLLECTION, [
            [
                'id' => 'pin-coat',
                'includes' => [
                    ['id' => '900-1', 'position' => 1],
                    ['id' => '901', 'position' => 3],
                ],
            ],
        ]);

        $forNine = $index->forElement(900);
        expect($forNine)->toHaveCount(1)
            ->and($forNine[0]['collectionHandle'])->toBe(CI_TEST_COLLECTION)
            ->and($forNine[0]['ruleId'])->toBe('pin-coat')
            ->and((int)$forNine[0]['position'])->toBe(1);

        $forOne = $index->forElement(901);
        expect($forOne)->toHaveCount(1)
            ->and((int)$forOne[0]['position'])->toBe(3);

        // A rebuild replaces the collection's rows.
        $index->rebuildForCollection(CI_TEST_COLLECTION, []);
        expect($index->forElement(900))->toBe([]);
    } finally {
        $index->clearForCollection(CI_TEST_COLLECTION);
    }
});
