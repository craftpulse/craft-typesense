<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Regression pin for a class of bug that hides from ordinary tests: a
 * standalone Pest harness can re-invoke `Install::safeUp()` against a
 * database that already has the plugin's tables (a wiped `plugins` row, a
 * bootstrap that installs unconditionally, an `isPluginInstalled()` check
 * that misses) - see the craft-pest skill's shared-state.md, "Plugin Install
 * migrations must be idempotent". Asserts the schema (table count, index
 * count, foreign key count) is unchanged after a second `safeUp()`, not just
 * that it doesn't throw: a naive `createIndex()`/`addForeignKey()` call adds
 * a functionally-duplicate key under a new generated name every time,
 * silently accumulating toward MySQL's 64-key-per-table ceiling without ever
 * erroring until that ceiling is crossed.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\typesense\db\Table;
use craftpulse\typesense\migrations\Install;

it('is idempotent when safeUp runs twice against an already-installed schema', function() {
    $schema = Craft::$app->getDb()->getSchema();
    $tables = [Table::SYNONYMS, Table::SYNC_STATE, Table::SYNC_DEPENDENCIES, Table::CURATION_INDEX, Table::SYNC_SUSPEND];

    // The plugin is already installed by the time this suite's tests run
    // (see tests/Bootstrap.php), so every one of these tables already exists.
    foreach ($tables as $table) {
        expect($schema->getTableSchema($table, true))->not->toBeNull();
    }

    $before = [];

    foreach ($tables as $table) {
        $before[$table] = [
            'indexes' => count($schema->getTableIndexes($table, true)),
            'foreignKeys' => count($schema->getTableSchema($table, true)->foreignKeys),
        ];
    }

    (new Install())->safeUp();
    $schema->refresh();

    foreach ($tables as $table) {
        expect(count($schema->getTableIndexes($table, true)))->toBe($before[$table]['indexes'])
            ->and(count($schema->getTableSchema($table, true)->foreignKeys))->toBe($before[$table]['foreignKeys']);
    }
});
