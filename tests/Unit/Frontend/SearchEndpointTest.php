<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The front-end search vertical: the fragment endpoint returns the three id'd
 * regions (results, facets, pagination) with real hits from the playground's
 * heroes collection, and the searchForm builder server-renders the whole widget
 * for the no-JavaScript path. Runs against the real Typesense server.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\helpers\UrlHelper;
use craftpulse\typesense\twig\tags\SearchFormTag;

it('returns the results, facets and pagination fragments with real hits', function() {
    $url = UrlHelper::actionUrl('typesense/search/results', [
        'collection' => 'heroes',
        'q' => '*',
        'queryBy' => 'title,slug',
        'facetBy' => 'slug',
        'perPage' => 5,
    ]);

    $this->get($url)
        ->assertOk()
        ->assertSee('id="ts-results"', false)
        ->assertSee('id="ts-facets"', false)
        ->assertSee('id="ts-pagination"', false)
        ->assertSee('Found 488 results', false);
});

it('fails soft with well-formed fragments for an unknown collection', function() {
    $url = UrlHelper::actionUrl('typesense/search/results', [
        'collection' => 'does_not_exist',
        'q' => 'anything',
    ]);

    $this->get($url)
        ->assertOk()
        ->assertSee('id="ts-results"', false)
        ->assertSee('No results.', false);
});

it('server-renders the whole widget for the no-JavaScript path', function() {
    $html = (string)(new SearchFormTag([
        'collection' => 'heroes',
        'queryBy' => 'title,slug',
        'facetBy' => 'slug',
        'perPage' => 5,
    ]))->render();

    expect($html)->toContain('<form')
        ->and($html)->toContain('method="get"')
        ->and($html)->toContain('data-signals')
        ->and($html)->toContain('id="ts-results"')
        ->and($html)->toContain('id="ts-facets"')
        ->and($html)->toContain('id="ts-pagination"')
        ->and($html)->toContain('data-bind:q');
});
