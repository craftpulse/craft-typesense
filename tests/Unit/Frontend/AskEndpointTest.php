<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The conversational ask endpoint (Fix 20): opt-in and off by default, gated on
 * a searchable collection that declares a conversation model. Pins the gating
 * (404 when not opted in, 404 when not searchable), the one-shot fail-soft render
 * shape (no live model on the playground server, so the answer is empty but the
 * endpoint never 500s), the developer throttle knob, and the SSE append-frame
 * framing a streamed token produces.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\Entry;
use craft\helpers\UrlHelper;
use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\enums\MultisiteStrategy;
use craftpulse\typesense\Typesense;
use starfederation\datastar\enums\ElementPatchMode;
use starfederation\datastar\events\PatchElements;

const ASK_TEST_COLLECTION = 'ts_ask_test';

/**
 * Registers a collection (opted into ask and searchable unless overridden) for
 * the duration of the test, then restores the configured collections.
 *
 * @param Collection $declared
 * @param callable $test
 */
function withAskCollection(Collection $declared, callable $test): void
{
    /** @var \craftpulse\typesense\models\Settings $settings */
    $settings = Typesense::$plugin->getSettings();
    $original = $settings->collections;
    $settings->collections = [$declared];

    try {
        $test();
    } finally {
        $settings->collections = $original;
    }
}

it('404s the ask endpoint when the collection is not opted in (off by default)', function() {
    // heroes is searchable but does not declare a conversation model.
    $this->withExceptionHandling()
        ->get(UrlHelper::actionUrl('typesense/search/ask', ['collection' => 'heroes', 'q' => 'who is fastest']))
        ->assertStatus(404);
});

it('404s the ask endpoint for an unknown collection', function() {
    $this->withExceptionHandling()
        ->get(UrlHelper::actionUrl('typesense/search/ask', ['collection' => 'does_not_exist', 'q' => 'hi']))
        ->assertStatus(404);
});

it('404s the ask endpoint when a collection declares a model but is not searchable', function() {
    $declared = Collection::make(ASK_TEST_COLLECTION)
        ->elementType(Entry::class)
        ->multisite(MultisiteStrategy::SharedWithSiteFilter)
        ->ask('conv-model-x');

    withAskCollection($declared, function() {
        $this->withExceptionHandling()
            ->get(UrlHelper::actionUrl('typesense/search/ask', ['collection' => ASK_TEST_COLLECTION, 'q' => 'hi']))
            ->assertStatus(404);
    });
});

it('answers one-shot without a 500 when opted in (fail-soft with no live model)', function() {
    $declared = Collection::make(ASK_TEST_COLLECTION)
        ->elementType(Entry::class)
        ->multisite(MultisiteStrategy::SharedWithSiteFilter)
        ->searchable()
        ->ask('conv-model-x');

    withAskCollection($declared, function() {
        $body = (string)$this->get(UrlHelper::actionUrl('typesense/search/ask', [
            'collection' => ASK_TEST_COLLECTION,
            'queryBy' => 'title',
            'q' => 'who is the fastest hero',
        ]))->assertStatus(200)->content;

        // The answer region renders (the morph target) even though the fail-soft
        // search returns no answer against a server with no such model.
        expect($body)->toContain('id="ts-answer"')
            ->and($body)->toContain('id="ts-answer-sources"');
    });
});

it('requires a question', function() {
    $declared = Collection::make(ASK_TEST_COLLECTION)
        ->elementType(Entry::class)
        ->multisite(MultisiteStrategy::SharedWithSiteFilter)
        ->searchable()
        ->ask('conv-model-x');

    withAskCollection($declared, function() {
        $this->withExceptionHandling()
            ->get(UrlHelper::actionUrl('typesense/search/ask', ['collection' => ASK_TEST_COLLECTION, 'q' => '']))
            ->assertStatus(400);
    });
});

it('throttles the ask endpoint past the configured cap (developer knob)', function() {
    /** @var \craftpulse\typesense\models\Settings $settings */
    $settings = Typesense::$plugin->getSettings();
    $originalCap = $settings->askThrottlePerWindow;
    $settings->askThrottlePerWindow = 2;
    Craft::$app->getCache()->flush();

    $declared = Collection::make(ASK_TEST_COLLECTION)
        ->elementType(Entry::class)
        ->multisite(MultisiteStrategy::SharedWithSiteFilter)
        ->searchable()
        ->ask('conv-model-x');

    try {
        withAskCollection($declared, function() {
            $url = UrlHelper::actionUrl('typesense/search/ask', [
                'collection' => ASK_TEST_COLLECTION,
                'queryBy' => 'title',
                'q' => 'hero',
            ]);

            // Under the cap: allowed (200).
            $this->get($url)->assertStatus(200);
            $this->get($url)->assertStatus(200);

            // Over the cap: throttled acknowledgement (never a 500).
            $body = (string)$this->get($url)->assertStatus(200)->content;
            expect($body)->toContain('throttled');
        });
    } finally {
        $settings->askThrottlePerWindow = $originalCap;
        Craft::$app->getCache()->flush();
    }
});

it('frames a streamed token as a Datastar append patch into the answer region', function() {
    $frame = (new PatchElements('I would suggest', [
        'selector' => '#ts-answer',
        'mode' => ElementPatchMode::Append,
    ]))->getOutput();

    expect($frame)->toContain('event: datastar-patch-elements')
        ->and($frame)->toContain('selector #ts-answer')
        ->and($frame)->toContain('mode append')
        ->and($frame)->toContain('I would suggest');
});

it('askEnabled reflects the opt-in state', function() {
    expect(Typesense::$plugin->getSettings())->not->toBeNull();

    $declared = Collection::make(ASK_TEST_COLLECTION)
        ->elementType(Entry::class)
        ->multisite(MultisiteStrategy::SharedWithSiteFilter)
        ->searchable()
        ->ask('conv-model-x');

    withAskCollection($declared, function() {
        $variable = new \craftpulse\typesense\variables\TypesenseVariable();
        expect($variable->askEnabled(ASK_TEST_COLLECTION))->toBeTrue()
            ->and($variable->askEnabled('heroes'))->toBeFalse();
    });
});
