<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Harness-proof coverage: confirms the plugin instantiates in the playground,
 * exposes its settings model, and wires its collection and Typesense client
 * services through the component container.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\typesense\models\Settings;
use craftpulse\typesense\services\Client;
use craftpulse\typesense\services\CollectionService;
use craftpulse\typesense\Typesense;

it('registers the plugin instance', function() {
    expect(Craft::$app->getPlugins()->getPlugin('typesense'))->toBeInstanceOf(Typesense::class);
});

it('exposes its settings model', function() {
    $plugin = Craft::$app->getPlugins()->getPlugin('typesense');

    expect($plugin->getSettings())->toBeInstanceOf(Settings::class);
});

it('wires the collection and client services', function() {
    /** @var Typesense $plugin */
    $plugin = Craft::$app->getPlugins()->getPlugin('typesense');

    expect($plugin->getCollections())->toBeInstanceOf(CollectionService::class)
        ->and($plugin->getClient())->toBeInstanceOf(Client::class);
});
