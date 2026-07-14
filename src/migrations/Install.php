<?php

/** @noinspection RepetitiveMethodCallsInspection */

namespace craftpulse\typesense\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Table as CraftTable;
use craftpulse\typesense\db\Table;

/**
 * Installation Migration
 *
 * @author Percipio Global Ltd. <support@percipio.london>
 * @since 1.0.0
 */
class Install extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp()
    {
        $this->createTables();

        return true;
    }

    public function safeDown()
    {
        $this->dropTables();

        return true;
    }

    /**
     * Creates the tables.
     */
    public function createTables()
    {
        // The vestigial typesense_collections table is intentionally NOT created
        // for fresh 5.9.0 installs (it was never read or written). Existing
        // installs have it dropped by m260714_130000_drop_vestigial_collections.
        if (Craft::$app->db->schema->getTableSchema(Table::SYNONYMS) === null) {
            $this->createTable(Table::SYNONYMS, [
                'id' => $this->primaryKey(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),

                'index' => $this->string()->unique()->notNull(),
                'synonyms' => $this->json()->notNull(),
            ]);
        }

        self::createSyncTables($this);
    }

    /**
     * Creates the sync-state and sync-dependency tables. Idempotent and shared
     * with the update migration so fresh installs and upgrades stay in lockstep.
     *
     * @param Migration $migration
     * @return void
     * @author CraftPulse
     */
    public static function createSyncTables(Migration $migration): void
    {
        $db = $migration->db;

        if ($db->getTableSchema(Table::SYNC_STATE) === null) {
            $migration->createTable(Table::SYNC_STATE, [
                'id' => $migration->primaryKey(),
                'collectionHandle' => $migration->string()->notNull(),
                'siteId' => $migration->integer()->notNull(),
                'cursor' => $migration->integer(),
                'checksum' => $migration->string(),
                'documentCount' => $migration->integer()->notNull()->defaultValue(0),
                'lastSyncedAt' => $migration->dateTime(),
                'dateCreated' => $migration->dateTime()->notNull(),
                'dateUpdated' => $migration->dateTime()->notNull(),
                'uid' => $migration->uid(),
            ]);

            $migration->createIndex(null, Table::SYNC_STATE, ['collectionHandle', 'siteId'], true);
            $migration->addForeignKey(null, Table::SYNC_STATE, ['siteId'], CraftTable::SITES, ['id'], 'CASCADE', null);
        }

        if ($db->getTableSchema(Table::SYNC_DEPENDENCIES) === null) {
            $migration->createTable(Table::SYNC_DEPENDENCIES, [
                'id' => $migration->primaryKey(),
                'collectionHandle' => $migration->string()->notNull(),
                'siteId' => $migration->integer()->notNull(),
                'sourceElementId' => $migration->integer()->notNull(),
                'dependencyElementId' => $migration->integer()->notNull(),
                'dateCreated' => $migration->dateTime()->notNull(),
                'dateUpdated' => $migration->dateTime()->notNull(),
                'uid' => $migration->uid(),
            ]);

            $migration->createIndex(null, Table::SYNC_DEPENDENCIES, ['dependencyElementId']);
            $migration->createIndex(null, Table::SYNC_DEPENDENCIES, ['sourceElementId', 'collectionHandle', 'siteId']);
            $migration->addForeignKey(null, Table::SYNC_DEPENDENCIES, ['sourceElementId'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
            $migration->addForeignKey(null, Table::SYNC_DEPENDENCIES, ['dependencyElementId'], CraftTable::ELEMENTS, ['id'], 'CASCADE', null);
            $migration->addForeignKey(null, Table::SYNC_DEPENDENCIES, ['siteId'], CraftTable::SITES, ['id'], 'CASCADE', null);
        }
    }

    /**
     * Drop the tables
     */
    public function dropTables()
    {
        $this->dropTableIfExists(Table::SYNC_DEPENDENCIES);
        $this->dropTableIfExists(Table::SYNC_STATE);
        $this->dropTableIfExists(Table::COLLECTIONS);
    }

    /**
     * Deletes the project config entry.
     */
    public function dropProjectConfig()
    {
        Craft::$app->projectConfig->remove('typesense');
    }
}
