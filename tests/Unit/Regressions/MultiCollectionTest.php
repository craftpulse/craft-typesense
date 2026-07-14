<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Pins the multi-collection membership guarantee (issues #65 and PR #66): the
 * legacy plugin keyed indexing on a single section, so an element could only
 * ever live in one index. The rebuild is element-query driven, so one element
 * belongs to every collection whose query matches it, and a save fans out to
 * all of them.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\Entry;
use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\enums\MultisiteStrategy;
use craftpulse\typesense\Typesense;

function multiCollection(string $name, callable $query): Collection
{
    return Collection::make($name)
        ->elementType(Entry::class)
        ->multisite(MultisiteStrategy::SharedWithSiteFilter)
        ->elementQuery($query);
}

it('treats one element as a member of every collection whose query matches (issues #65, #66)', function() {
    $entry = Entry::find()->section('heroes')->siteId(1)->one();

    $everything = multiCollection('ts_multi_all', fn($query) => $query->section('heroes'));
    $bySlug = multiCollection('ts_multi_slug', fn($query) => $query->section('heroes')->slug($entry->slug));

    $sync = Typesense::$plugin->getSync();

    expect($sync->matchesCollection($everything, $entry))->toBeTrue()
        ->and($sync->matchesCollection($bySlug, $entry))->toBeTrue();
});

it('excludes an element from a collection whose query does not match it (issue #66)', function() {
    $entry = Entry::find()->section('heroes')->siteId(1)->one();

    $other = multiCollection('ts_multi_other', fn($query) => $query->section('heroes')->slug($entry->slug . '-does-not-exist'));

    expect(Typesense::$plugin->getSync()->matchesCollection($other, $entry))->toBeFalse();
});
