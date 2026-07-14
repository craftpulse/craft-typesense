<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The CP-managed collection definition model: validation of the authored fields
 * and the project-config shape the cockpit persists. Live save/get/delete
 * against project config is exercised in the P8 smoke walk (writing project
 * config from a test would touch the playground's YAML).
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\Entry;
use craftpulse\typesense\models\CollectionDefinition;

it('requires a name, element type, and multisite strategy', function() {
    $definition = new CollectionDefinition();
    $definition->name = '';
    $definition->multisite = '';

    expect($definition->validate())->toBeFalse()
        ->and($definition->getErrors('name'))->not->toBeEmpty()
        ->and($definition->getErrors('multisite'))->not->toBeEmpty();
});

it('rejects a name with illegal characters and a bad multisite strategy', function() {
    $definition = new CollectionDefinition();
    $definition->name = 'has spaces';
    $definition->multisite = 'nonsense';

    expect($definition->validate())->toBeFalse()
        ->and($definition->getErrors('name'))->not->toBeEmpty()
        ->and($definition->getErrors('multisite'))->not->toBeEmpty();
});

it('validates and produces the project-config shape for a well-formed definition', function() {
    $definition = new CollectionDefinition();
    $definition->name = 'products';
    $definition->elementType = Entry::class;
    $definition->source = 'blog';
    $definition->multisite = 'sharedWithSiteFilter';
    $definition->enabled = true;
    $definition->mappings = ['abc-uid' => ['indexable' => true]];

    expect($definition->validate())->toBeTrue();

    $config = $definition->getConfig();

    expect($config)->toHaveKeys(['name', 'elementType', 'source', 'multisite', 'enabled', 'mappings', 'metadata'])
        ->and($config)->not->toHaveKey('uid')
        ->and($config['name'])->toBe('products')
        ->and($config['elementType'])->toBe(Entry::class)
        ->and($config['mappings'])->toBe(['abc-uid' => ['indexable' => true]]);
});
