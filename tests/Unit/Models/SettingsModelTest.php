<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Covers the settings model: required-attribute rules, the single-node and
 * cluster/cloud conditional requirements, the server-type and managedBy range
 * checks, the numeric-or-env validator for resilience settings, and the
 * environment-placeholder parsing wired through EnvAttributeParserBehavior.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\helpers\App;
use craftpulse\typesense\models\Settings;

it('requires an api key', function() {
    $settings = new Settings();
    $settings->apiKey = null;

    expect($settings->validate(['apiKey']))->toBeFalse()
        ->and($settings->hasErrors('apiKey'))->toBeTrue();
});

it('validates a single-node configuration', function() {
    $settings = new Settings();
    $settings->serverType = Settings::SERVER_TYPE_SINGLE;
    $settings->apiKey = 'local-admin-key';
    $settings->server = 'typesense';
    $settings->port = '8108';

    expect($settings->validate())->toBeTrue();
});

it('requires cluster nodes for a cluster configuration', function() {
    $settings = new Settings();
    $settings->serverType = Settings::SERVER_TYPE_CLUSTER;
    $settings->apiKey = 'local-admin-key';
    $settings->cluster = null;

    expect($settings->validate())->toBeFalse()
        ->and($settings->hasErrors('cluster'))->toBeTrue();
});

it('rejects an unknown server type', function() {
    $settings = new Settings();
    $settings->apiKey = 'local-admin-key';
    $settings->serverType = 'satellite';

    expect($settings->validate(['serverType']))->toBeFalse()
        ->and($settings->hasErrors('serverType'))->toBeTrue();
});

it('rejects an unknown managedBy value', function() {
    $settings = new Settings();
    $settings->synonymsManagedBy = 'whoever';

    expect($settings->validate(['synonymsManagedBy']))->toBeFalse()
        ->and($settings->hasErrors('synonymsManagedBy'))->toBeTrue();
});

it('accepts numeric and env values for resilience settings but rejects garbage', function() {
    $numeric = new Settings();
    $numeric->numRetries = '5';
    expect($numeric->validate(['numRetries']))->toBeTrue();

    $env = new Settings();
    $env->numRetries = '$TYPESENSE_NUM_RETRIES';
    expect($env->validate(['numRetries']))->toBeTrue();

    $garbage = new Settings();
    $garbage->connectionTimeoutSeconds = 'soon';
    expect($garbage->validate(['connectionTimeoutSeconds']))->toBeFalse()
        ->and($garbage->hasErrors('connectionTimeoutSeconds'))->toBeTrue();
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
