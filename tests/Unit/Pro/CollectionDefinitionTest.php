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

    expect($definition->validate())->toBeTrue();

    $config = $definition->getConfig();

    // The mapping lives in a real field layout now, not a mappings array.
    expect($config)->toHaveKeys(['name', 'elementType', 'source', 'sourceType', 'multisite', 'enabled', 'metadata'])
        ->and($config)->not->toHaveKey('uid')
        ->and($config)->not->toHaveKey('mappings')
        ->and($config['name'])->toBe('products')
        ->and($config['elementType'])->toBe(Entry::class)
        // A section-shaped source by default (Gap 2).
        ->and($config['sourceType'])->toBe(CollectionDefinition::SOURCE_TYPE_SECTION);
});

it('carries an entry-type source through the config shape and the member list', function() {
    $definition = new CollectionDefinition();
    $definition->name = 'blocks';
    $definition->elementType = Entry::class;
    $definition->source = 'parleyTestOuterBlock';
    $definition->sourceType = CollectionDefinition::SOURCE_TYPE_ENTRY_TYPE;
    $definition->multisite = 'sharedWithSiteFilter';

    expect($definition->validate())->toBeTrue()
        ->and($definition->getConfig()['sourceType'])->toBe(CollectionDefinition::SOURCE_TYPE_ENTRY_TYPE);

    // The list-ready member seam (Gap 1 extends this to N members).
    $members = $definition->getMembers();
    expect($members)->toHaveCount(1)
        ->and($members[0]['elementType'])->toBe(Entry::class)
        ->and($members[0]['sourceType'])->toBe(CollectionDefinition::SOURCE_TYPE_ENTRY_TYPE)
        ->and($members[0]['source'])->toBe('parleyTestOuterBlock');
});

it('rejects an unknown source type', function() {
    $definition = new CollectionDefinition();
    $definition->name = 'blocks';
    $definition->elementType = Entry::class;
    $definition->multisite = 'sharedWithSiteFilter';
    $definition->sourceType = 'nonsense';

    expect($definition->validate())->toBeFalse()
        ->and($definition->getErrors('sourceType'))->not->toBeEmpty();
});
