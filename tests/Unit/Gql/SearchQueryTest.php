<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The GraphQL search query (Free). The resolver maps its camelCase arguments to
 * Typesense's snake_case params, delegates to the fail-soft search layer, and
 * returns the response as a JSON string. An unknown collection still yields a
 * well-formed empty payload, never an error.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\helpers\Json;
use craftpulse\typesense\gql\queries\SearchQuery;

it('registers a typesenseSearch query with a string return type', function() {
    $queries = SearchQuery::getQueries();

    expect($queries)->toHaveKey('typesenseSearch')
        ->and($queries['typesenseSearch']['args'])->toHaveKeys(['collection', 'q', 'queryBy']);
});

it('resolves to a well-formed JSON payload, failing soft for an unknown collection', function() {
    $queries = SearchQuery::getQueries();
    $resolve = $queries['typesenseSearch']['resolve'];

    $json = $resolve(null, [
        'collection' => 'does_not_exist_at_all',
        'q' => 'coat',
        'queryBy' => 'title',
    ]);

    $payload = Json::decode($json);

    expect($payload)->toHaveKey('found')
        ->and($payload['found'])->toBe(0)
        ->and($payload['hits'])->toBe([])
        ->and($payload['request_params'])->toHaveKey('query_by');
});
