<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The SSE stream contract for the front-end search morph (Fix 18/19). Pins the
 * event frame order, multi-cycle morph consistency (query, facet, page as
 * successive requests with signals that stay coherent), the SSE line-framing
 * integrity of the HTML payloads (so a multi-line fragment never breaks the
 * stream), fail-soft behaviour over SSE (empty results and an unknown collection
 * never 500), and the readSignals guard (a Datastar request without a payload
 * must not trip the SDK's unguarded superglobal read). Runs against the real
 * playground heroes collection.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\helpers\UrlHelper;

/**
 * Requests the fragment endpoint as Datastar does (the `Datastar-Request` header
 * plus the client signals nested under a `datastar` query param) and returns the
 * raw SSE body. readSignals() reads the $_GET and $_SERVER superglobals, so they
 * are set to the shape a Datastar GET presents and restored afterwards.
 *
 * @param object $test the Pest test case ($this)
 * @param array<string, mixed> $config the fixed endpoint config (collection, queryBy, ...)
 * @param array<string, mixed> $signals the client signals (q, page, facets)
 * @return string the SSE response body
 */
function tsSseBody(object $test, array $config, array $signals): string
{
    $signal = json_encode($signals);
    $url = UrlHelper::actionUrl('typesense/search/results', $config) . '&' . http_build_query(['datastar' => $signal]);

    $originalGet = $_GET;
    $originalMethod = $_SERVER['REQUEST_METHOD'] ?? null;
    $_GET['datastar'] = $signal;
    $_SERVER['REQUEST_METHOD'] = 'GET';

    try {
        return (string)$test->http('get', $url)
            ->addHeader('Datastar-Request', 'true')
            ->send()
            ->assertStatus(200)
            ->content;
    } finally {
        $_GET = $originalGet;

        if ($originalMethod === null) {
            unset($_SERVER['REQUEST_METHOD']);
        } else {
            $_SERVER['REQUEST_METHOD'] = $originalMethod;
        }
    }
}

$config = ['collection' => 'heroes', 'queryBy' => 'title,slug', 'facetBy' => 'slug', 'perPage' => 5];

it('emits the region frames in a stable order, signals last', function() use ($config) {
    $body = tsSseBody($this, $config, ['q' => 'hero', 'page' => 1, 'facets' => []]);

    $results = strpos($body, 'selector #ts-results');
    $facets = strpos($body, 'selector #ts-facets');
    $pagination = strpos($body, 'selector #ts-pagination');
    $signals = strpos($body, 'event: datastar-patch-signals');

    expect($results)->toBeInt()
        ->and($facets)->toBeGreaterThan($results)
        ->and($pagination)->toBeGreaterThan($facets)
        ->and($signals)->toBeGreaterThan($pagination);
});

it('keeps the found count consistent between the results frame and the signal frame', function() use ($config) {
    $body = tsSseBody($this, $config, ['q' => 'hero', 'page' => 1, 'facets' => []]);

    preg_match('/Found (\d+) results/', $body, $shown);
    preg_match('/"tsFound":(\d+)/', $body, $signal);

    expect($shown[1] ?? null)->not->toBeNull()
        ->and($signal[1] ?? null)->not->toBeNull()
        ->and($signal[1])->toBe($shown[1]);
});

it('stays coherent across a multi-cycle morph (query then facet then page)', function() use ($config) {
    $cycles = [
        ['q' => 'hero', 'page' => 1, 'facets' => []],
        ['q' => 'hero', 'page' => 1, 'facets' => ['nonexistent-facet-value']],
        ['q' => 'hero', 'page' => 2, 'facets' => []],
    ];

    foreach ($cycles as $signals) {
        $body = tsSseBody($this, $config, $signals);

        preg_match('/Found (\d+) results/', $body, $shown);
        preg_match('/"tsFound":(\d+)/', $body, $signal);

        expect($body)->toContain('selector #ts-results')
            ->and($body)->toContain('selector #ts-facets')
            ->and($body)->toContain('selector #ts-pagination')
            ->and($body)->toContain('"tsSearching":false')
            ->and($signal[1] ?? null)->toBe($shown[1] ?? null);
    }
});

it('wraps every multi-line HTML payload as valid SSE data lines (framing integrity)', function() use ($config) {
    $body = tsSseBody($this, $config, ['q' => 'hero', 'page' => 1, 'facets' => []]);

    // Every non-blank line of an SSE stream must be a field line; if the HTML
    // payload leaked a raw newline it would produce a line that is not one of
    // these, breaking the stream. The SDK splits multi-line content into
    // `data:` lines, so this holds.
    foreach (explode("\n", trim($body)) as $line) {
        if ($line === '') {
            continue;
        }

        expect($line)->toMatch('/^(event|data|id|retry): /');
    }
});

it('fail-softs an empty result over SSE without a 500', function() use ($config) {
    $body = tsSseBody($this, $config, ['q' => 'zzqqxnomatch', 'page' => 1, 'facets' => []]);

    expect($body)->toContain('selector #ts-results')
        ->and($body)->toContain('"tsFound":0')
        ->and($body)->toContain('No results');
});

it('404s an unknown collection on the SSE path (not a 500, no partial stream)', function() {
    $signal = json_encode(['q' => 'hero', 'page' => 1, 'facets' => []]);
    $url = UrlHelper::actionUrl('typesense/search/results', ['collection' => 'does_not_exist']) . '&' . http_build_query(['datastar' => $signal]);

    $original = $_GET;
    $_GET['datastar'] = $signal;

    try {
        $this->withExceptionHandling()
            ->http('get', $url)
            ->addHeader('Datastar-Request', 'true')
            ->send()
            ->assertStatus(404);
    } finally {
        $_GET = $original;
    }
});

it('does not 500 when a Datastar request carries no signal payload (readSignals guard)', function() use ($config) {
    // The Datastar-Request header without a `datastar` param: the guard must skip
    // readSignals() (which reads $_GET['datastar'] unguarded) and fall back to
    // top-level params, still streaming.
    $url = UrlHelper::actionUrl('typesense/search/results', $config);

    $body = (string)$this->http('get', $url)
        ->addHeader('Datastar-Request', 'true')
        ->send()
        ->assertStatus(200)
        ->content;

    expect($body)->toContain('event: datastar-patch-elements')
        ->and($body)->toContain('selector #ts-results');
});
