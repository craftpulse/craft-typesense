<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Harness-proof coverage of the settings model: the required-attribute rules,
 * the single-server happy path, the server-type range check, and the
 * environment-placeholder parsing wired through EnvAttributeParserBehavior.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\helpers\App;
use percipiolondon\typesense\models\Settings;

it('requires an api key', function() {
    $settings = new Settings();
    $settings->apiKey = null;

    expect($settings->validate(['apiKey']))->toBeFalse()
        ->and($settings->hasErrors('apiKey'))->toBeTrue();
});

it('validates a single-server configuration', function() {
    $settings = new Settings();
    $settings->serverType = Settings::TYPESENSE_SERVER;
    $settings->apiKey = 'local-admin-key';
    $settings->server = 'typesense';
    $settings->port = '8108';

    expect($settings->validate())->toBeTrue();
});

it('rejects an unknown server type', function() {
    $settings = new Settings();
    $settings->apiKey = 'local-admin-key';
    $settings->serverType = 'satellite';

    expect($settings->validate(['serverType']))->toBeFalse()
        ->and($settings->hasErrors('serverType'))->toBeTrue();
});

it('declares the env parser behavior', function() {
    expect((new Settings())->getBehavior('parser'))->not->toBeNull();
});

it('parses an env placeholder in the api key', function() {
    putenv('TYPESENSE_TEST_KEY=super-secret');
    $_SERVER['TYPESENSE_TEST_KEY'] = 'super-secret';

    $settings = new Settings();
    $settings->apiKey = '$TYPESENSE_TEST_KEY';

    expect(App::parseEnv($settings->apiKey))->toBe('super-secret');

    putenv('TYPESENSE_TEST_KEY');
    unset($_SERVER['TYPESENSE_TEST_KEY']);
});
