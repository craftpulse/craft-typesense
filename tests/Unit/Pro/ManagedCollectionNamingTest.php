<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The CP-managed collection Name/Handle idiom (Fix 21): a friendly display name
 * separate from the immutable index name, and the config-tab leak fix. Pins that
 * a control-panel-managed collection never appears in the config-file or event
 * (code) sources that feed the "Code managed" tab, that the display name
 * round-trips, and that it backfills from the index name for older configs that
 * predate the field.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use Craft;
use craft\elements\Entry;
use craftpulse\typesense\models\CollectionDefinition;
use craftpulse\typesense\services\ManagedCollections;
use craftpulse\typesense\Typesense;

function aManagedDefinition(string $name, string $displayName = ''): CollectionDefinition
{
    $definition = new CollectionDefinition();
    $definition->name = $name;
    $definition->displayName = $displayName;
    $definition->elementType = Entry::class;
    $definition->multisite = 'sharedWithSiteFilter';

    return $definition;
}

it('keeps a CP-managed collection out of the config-file and event (code) sources', function() {
    $managed = Typesense::$plugin->getManagedCollections();
    $registry = Typesense::$plugin->getCollectionRegistry();
    $definition = aManagedDefinition('ts_managed_leak', 'Managed Leak Test');

    expect($managed->save($definition))->toBeTrue();

    try {
        // The "Code managed" tab is built from these two sources; a managed
        // collection must appear in neither (the Fix 21A leak).
        expect($registry->getConfigCollections())->not->toHaveKey('ts_managed_leak')
            ->and($registry->getEventCollections())->not->toHaveKey('ts_managed_leak')
            // It is still in the merged runtime map (the CP tab lists it from the
            // managed store directly).
            ->and($registry->getAll())->toHaveKey('ts_managed_leak');
    } finally {
        $managed->delete($definition);
    }
});

it('round-trips the display name', function() {
    $managed = Typesense::$plugin->getManagedCollections();
    $definition = aManagedDefinition('ts_named', 'Friendly Label');

    expect($managed->save($definition))->toBeTrue();

    try {
        $reloaded = $managed->getByUid((string)$definition->uid);
        expect($reloaded)->not->toBeNull()
            ->and($reloaded->displayName)->toBe('Friendly Label')
            ->and($reloaded->getDisplayName())->toBe('Friendly Label')
            ->and($reloaded->name)->toBe('ts_named');
    } finally {
        $managed->delete($definition);
    }
});

it('backfills the display name from the index name for a config that predates the field', function() {
    // Write a raw project-config entry with no displayName, as an older install
    // would have, then read it back through the service.
    $uid = 'ts-backfill-' . bin2hex(random_bytes(4));
    $key = ManagedCollections::CONFIG_KEY . '.' . $uid;
    Craft::$app->getProjectConfig()->set($key, [
        'name' => 'ts_backfill',
        'elementType' => Entry::class,
        'multisite' => 'sharedWithSiteFilter',
        'enabled' => true,
    ]);

    try {
        $reloaded = Typesense::$plugin->getManagedCollections()->getByUid($uid);
        expect($reloaded)->not->toBeNull()
            ->and($reloaded->displayName)->toBe('ts_backfill')
            ->and($reloaded->getConfig()['displayName'])->toBe('ts_backfill');
    } finally {
        Craft::$app->getProjectConfig()->remove($key);
    }
});

it('emits displayName in the project-config representation, defaulting to the index name', function() {
    $definition = aManagedDefinition('ts_cfg');

    expect($definition->getConfig()['displayName'])->toBe('ts_cfg')
        ->and($definition->getDisplayName())->toBe('ts_cfg');
});
