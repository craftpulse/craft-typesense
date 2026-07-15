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
        ->assertSee('Found 488 results', false)
        // Each result is a real anchor to the element (I5) and carries the
        // Datastar click-tracking binding to the event endpoint, sending the CSRF
        // token as a header so the anonymous POST passes Craft's CSRF check (I1).
        ->assertSee('<a class="ts-results__title', false)
        ->assertSee('href="http', false)
        ->assertSee('data-on:click="@post(', false)
        ->assertSee('typesense/search/track-event', false)
        ->assertSee("headers: {'X-CSRF-Token'", false);
});

it('404s an unknown collection (the anonymous proxy has no raw-client fallback)', function() {
    $url = UrlHelper::actionUrl('typesense/search/results', [
        'collection' => 'does_not_exist',
        'q' => 'anything',
    ]);

    // The trust boundary (C1): the anonymous endpoint only serves collections
    // explicitly flagged searchable; an unknown handle 404s.
    $this->withExceptionHandling()->get($url)->assertStatus(404);
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
