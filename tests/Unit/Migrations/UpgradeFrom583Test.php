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
        ->and(Typesense::$plugin->schemaVersion)->toBe('5.9.3')
        ->and(Craft::$app->getProjectConfig()->get('plugins.typesense'))->not->toBeNull();
});
