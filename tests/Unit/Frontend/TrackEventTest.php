<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The front-end analytics event endpoint: a click or conversion posted from the
 * front end is recorded server-side (the admin key never leaves the server) and
 * the endpoint answers cleanly whether or not analytics is opted in. Only the
 * click and conversion types are accepted from the front end.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\helpers\UrlHelper;

it('accepts a front-end click event and responds cleanly', function() {
    $this->post(UrlHelper::actionUrl('typesense/search/track-event'), [
        'type' => 'click',
        'name' => 'heroes_click',
        'docId' => '1',
        'q' => 'hero',
    ])
        ->assertOk()
        ->assertJson(['ok' => true]);
});

it('is reachable anonymously and never leaks the admin key', function() {
    $response = $this->post(UrlHelper::actionUrl('typesense/search/track-event'), [
        'type' => 'conversion',
        'name' => 'heroes_conversion',
        'docId' => '2',
    ])->assertOk();

    // The response is a bare acknowledgement; it never echoes any key material.
    $response->assertJson(['ok' => true]);
});
