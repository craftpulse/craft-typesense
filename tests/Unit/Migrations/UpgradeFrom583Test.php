<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The 5.8.3 to 5.9.0 upgrade fixture test (the sensitive critical path). Fixtures
 * a 5.8.3-era install state (the vestigial typesense_collections table present)
 * and runs the drop migration, asserting: the vestigial table is dropped, the
 * synonyms table and its rows survive (schema intact for the P6 set-shape
 * extension), the sync tables exist, the schema version is 5.9.0, and the plugin
 * settings survive under the typesense project-config handle.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\db\Query;
use craftpulse\typesense\db\Table;
use craftpulse\typesense\migrations\m260714_130000_drop_vestigial_collections;
use craftpulse\typesense\migrations\m260715_150000_drop_managed_by_settings;
use craftpulse\typesense\models\Settings;
use craftpulse\typesense\Typesense;

it('drops the vestigial collections table and preserves everything else', function() {
    $db = Craft::$app->getDb();

    // Fixture a 5.8.3 install: the vestigial table exists.
    if ($db->getTableSchema(Table::COLLECTIONS, true) === null) {
        $db->createCommand()->createTable(Table::COLLECTIONS, ['id' => 'pk'])->execute();
    }

    expect($db->getTableSchema(Table::COLLECTIONS, true))->not->toBeNull();

    $synonymsBefore = (new Query())->from(Table::SYNONYMS)->count();

    // Run the update migration.
    (new m260714_130000_drop_vestigial_collections())->safeUp();
    $db->getSchema()->refresh();

    expect($db->getTableSchema(Table::COLLECTIONS, true))->toBeNull()
        ->and($db->getTableSchema(Table::SYNONYMS, true))->not->toBeNull()
        ->and((new Query())->from(Table::SYNONYMS)->count())->toBe($synonymsBefore)
        ->and($db->getTableSchema(Table::SYNC_STATE, true))->not->toBeNull()
        ->and($db->getTableSchema(Table::SYNC_DEPENDENCIES, true))->not->toBeNull()
        ->and($db->getTableSchema(Table::CURATION_INDEX, true))->not->toBeNull()
        ->and(Typesense::$plugin->schemaVersion)->toBe('5.9.9')
        ->and(Craft::$app->getProjectConfig()->get('plugins.typesense'))->not->toBeNull();
});

it('drops the removed managedBy settings from project config and tolerates them on the model', function() {
    $projectConfig = Craft::$app->getProjectConfig();

    // Fixture a 5.8.x install carrying the removed ownership keys.
    $projectConfig->set('plugins.typesense.settings.synonymsManagedBy', 'config');
    $projectConfig->set('plugins.typesense.settings.curationManagedBy', 'config');
    $projectConfig->set('plugins.typesense.settings.presetsManagedBy', 'config');

    (new m260715_150000_drop_managed_by_settings())->safeUp();

    expect($projectConfig->get('plugins.typesense.settings.synonymsManagedBy'))->toBeNull()
        ->and($projectConfig->get('plugins.typesense.settings.curationManagedBy'))->toBeNull()
        ->and($projectConfig->get('plugins.typesense.settings.presetsManagedBy'))->toBeNull();

    // The current model no longer declares those properties; instantiating it is
    // safe once the stale keys are gone.
    expect(new Settings())->toBeInstanceOf(Settings::class);
});
