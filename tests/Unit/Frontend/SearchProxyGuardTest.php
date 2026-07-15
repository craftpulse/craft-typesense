<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The trust boundary on the anonymous front-end search proxy (C1): only a
 * collection explicitly flagged searchable may be queried anonymously, unknown
 * handles 404 (no raw-client fallback), and every field name referenced by the
 * request must be a declared field of the collection (a field-name allowlist).
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\helpers\UrlHelper;
use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\builders\Field;
use craftpulse\typesense\events\RegisterCollectionsEvent;
use craftpulse\typesense\services\Collections;
use yii\base\Event;

function resultsUrl(array $params): string
{
    return UrlHelper::actionUrl('typesense/search/results', $params);
}

it('serves a searchable collection anonymously', function() {
    // The playground heroes collection is flagged searchable in config.
    $this->get(resultsUrl(['collection' => 'heroes', 'q' => '*', 'queryBy' => 'title']))
        ->assertOk()
        ->assertSee('id="ts-results"', false);
});

it('404s an unknown collection handle (no raw-client fallback)', function() {
    $this->withExceptionHandling()
        ->get(resultsUrl(['collection' => 'definitely_not_a_collection', 'q' => '*']))
        ->assertStatus(404);
});

it('404s a known collection that is not flagged searchable', function() {
    $handler = static function(RegisterCollectionsEvent $event): void {
        $event->collections[] = Collection::make('ts_unflagged_test')->fields(Field::string('title'));
    };
    Event::on(Collections::class, Collections::EVENT_REGISTER_COLLECTIONS, $handler);

    try {
        $this->withExceptionHandling()
            ->get(resultsUrl(['collection' => 'ts_unflagged_test', 'q' => '*', 'queryBy' => 'title']))
            ->assertStatus(404);
    } finally {
        Event::off(Collections::class, Collections::EVENT_REGISTER_COLLECTIONS, $handler);
    }
});

it('rejects a field name outside the collection schema', function() {
    // "password" is not a declared field of heroes; the allowlist rejects it.
    $this->withExceptionHandling()
        ->get(resultsUrl(['collection' => 'heroes', 'q' => '*', 'queryBy' => 'password']))
        ->assertStatus(400);

    $this->withExceptionHandling()
        ->get(resultsUrl(['collection' => 'heroes', 'q' => '*', 'queryBy' => 'title', 'sort' => 'secret_column:desc']))
        ->assertStatus(400);
});

it('accepts declared field names on a searchable collection', function() {
    $this->get(resultsUrl(['collection' => 'heroes', 'q' => '*', 'queryBy' => 'title,slug', 'facetBy' => 'slug']))
        ->assertOk();
});
