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
use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\builders\Field;
use craftpulse\typesense\twig\tags\SearchFormTag;
use craftpulse\typesense\Typesense;

it('returns the results, facets and pagination fragments with real hits', function() {
    $url = UrlHelper::actionUrl('typesense/search/results', [
        'collection' => 'heroes',
        'q' => '*',
        'queryBy' => 'title,slug',
        'facetBy' => 'slug',
        'perPage' => 5,
    ]);

    // Computed at test time, never hardcoded: the fixture collection's real
    // document count (see tests/Support/typesense-fixtures.php) is a fact
    // about the fixture data, not a number pinned to whatever it happened to
    // contain when this test was written - a hardcoded count is exactly what
    // drifted against the shared live "heroes" collection before this
    // suite owned its own Typesense-side fixture.
    $expectedFound = Typesense::$plugin->getSearch()->search('heroes', ['q' => '*', 'query_by' => 'title,slug'])['found'];

    $this->get($url)
        ->assertOk()
        ->assertSee('id="ts-results"', false)
        ->assertSee('id="ts-facets"', false)
        ->assertSee('id="ts-pagination"', false)
        ->assertSee("Found {$expectedFound} results", false)
        // Each result is a real anchor to the element (I5) and carries the
        // Datastar click-tracking binding to the event endpoint, sending the CSRF
        // token as a header so the anonymous POST passes Craft's CSRF check (I1).
        ->assertSee('<a class="ts-results__title', false)
        ->assertSee('href="http', false)
        ->assertSee('data-on:click="@post(', false)
        ->assertSee('typesense/search/track-event', false)
        ->assertSee("headers: {'X-CSRF-Token'", false);
});

it('returns the plain fragment HTML for a non-Datastar request (no-JS and back-compat)', function() {
    // No Datastar-Request header: the response is exactly the pre-SSE behaviour,
    // the three fragments concatenated as HTML (no event-stream framing), so the
    // no-JS form GET and any direct/crawler hit are unchanged.
    $url = UrlHelper::actionUrl('typesense/search/results', [
        'collection' => 'heroes',
        'q' => 'hero',
        'queryBy' => 'title,slug',
        'perPage' => 5,
    ]);

    // Computed at test time - see the comment in the previous test.
    $expectedFound = Typesense::$plugin->getSearch()->search('heroes', ['q' => 'hero', 'query_by' => 'title,slug'])['found'];

    $body = (string)$this->get($url)->assertStatus(200)->content;

    expect($body)->toContain('id="ts-results"')
        ->and($body)->toContain('id="ts-facets"')
        ->and($body)->toContain('id="ts-pagination"')
        ->and($body)->toContain("Found {$expectedFound} results")
        ->and($body)->not->toContain('event: datastar-patch-elements');
});

it('rejects an ask request with an undeclared queryBy field on both paths', function() {
    // A searchable, ask-enabled collection declaring only a `title` field.
    $declared = Collection::make('ts_ask_guard')
        ->searchable()
        ->ask('advisor')
        ->preset(['query_by' => 'title'])
        ->fields(Field::string('title'));

    $settings = Typesense::$plugin->getSettings();
    $original = $settings->collections;
    $settings->collections = [$declared];

    try {
        $url = UrlHelper::actionUrl('typesense/search/ask', [
            'collection' => 'ts_ask_guard',
            'q' => 'hi',
            'queryBy' => 'not_a_field',
        ]);

        // One-shot path (no Datastar header): the anonymous trust-boundary guard
        // rejects an undeclared field with a 400 before any search is built.
        $this->withExceptionHandling()->get($url)->assertStatus(400);

        // Streaming path (Datastar header): the guard sits before the
        // stream/one-shot branch, so the undeclared field is rejected either way.
        $this->withExceptionHandling();
        $this->http('get', $url)
            ->addHeader('Datastar-Request', 'true')
            ->send()
            ->assertStatus(400);
    } finally {
        $settings->collections = $original;
    }
});

it('streams SSE patch events for a Datastar request', function() {
    $url = UrlHelper::actionUrl('typesense/search/results', [
        'collection' => 'heroes',
        'queryBy' => 'title,slug',
        'facetBy' => 'slug',
        'perPage' => 5,
    ]) . '&' . http_build_query(['datastar' => json_encode(['q' => 'hero', 'page' => 1, 'facets' => []])]);

    $response = $this->http('get', $url)
        ->addHeader('Datastar-Request', 'true')
        ->send()
        ->assertStatus(200);

    expect($response->getHeaders()->get('content-type'))->toContain('text/event-stream')
        ->and((string)$response->content)->toContain('event: datastar-patch-elements')
        ->and((string)$response->content)->toContain('selector #ts-results')
        ->and((string)$response->content)->toContain('selector #ts-facets')
        ->and((string)$response->content)->toContain('selector #ts-pagination')
        ->and((string)$response->content)->toContain('event: datastar-patch-signals')
        ->and((string)$response->content)->toContain('tsFound');
});

it('reads the query from the datastar signal payload, not a top-level param', function() {
    // Datastar nests signals under the `datastar` param; a nonsense query there
    // must filter to zero, proving readSignals() (not a top-level `q`) drives the
    // search on the SSE path. readSignals() reads the $_GET and $_SERVER
    // superglobals directly, so set them to the shape a Datastar GET presents.
    $signal = json_encode(['q' => 'zzqqxnomatch', 'page' => 1, 'facets' => []]);
    $url = UrlHelper::actionUrl('typesense/search/results', [
        'collection' => 'heroes',
        'queryBy' => 'title,slug',
        'perPage' => 5,
    ]) . '&' . http_build_query(['datastar' => $signal]);

    $originalGet = $_GET;
    $originalMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    $_GET['datastar'] = $signal;
    $_SERVER['REQUEST_METHOD'] = 'GET';

    try {
        $response = $this->http('get', $url)
            ->addHeader('Datastar-Request', 'true')
            ->send()
            ->assertStatus(200);

        expect((string)$response->content)->toContain('"tsFound":0');
    } finally {
        $_GET = $originalGet;
        if ($originalMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $originalMethod;
        }
    }
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
