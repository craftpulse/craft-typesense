<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Table as CraftTable;
use craftpulse\typesense\db\Table;

/**
 * The install migration: creates the plugin's tables (synonyms, sync state, sync
 * dependencies, curation lookup index, and the runtime sync-suspend table). The
 * per-table creators are static and shared with the update migrations so fresh
 * installs and upgrades stay in lockstep.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class Install extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->createTables();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTables();

        return true;
    }

    /**
     * Creates the plugin's tables.
     *
     * @return void
     * @author CraftPulse
     */
    public function createTables(): void
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
        self::createCurationIndexTable($this);
        self::createSuspendTable($this);
    }

    /**
     * Creates the runtime sync-suspend table (one row per suspended collection,
     * plus a sentinel row for the global suspend). Idempotent and shared with the
     * update migration so fresh installs and upgrades stay in lockstep.
     *
     * @param Migration $migration
     * @return void
     * @author CraftPulse
     */
    public static function createSuspendTable(Migration $migration): void
    {
        $db = $migration->db;

        if ($db->getTableSchema(Table::SYNC_SUSPEND) !== null) {
            return;
        }

        $migration->createTable(Table::SYNC_SUSPEND, [
            'id' => $migration->primaryKey(),
            'collection' => $migration->string()->notNull(),
            'dateCreated' => $migration->dateTime()->notNull(),
            'dateUpdated' => $migration->dateTime()->notNull(),
            'uid' => $migration->uid(),
        ]);

        $migration->createIndex(null, Table::SYNC_SUSPEND, ['collection'], true);
    }

    /**
     * Creates the curation lookup table (element-to-rule). Idempotent and shared
     * with the update migration so fresh installs and upgrades stay in lockstep.
     *
     * The elementId column deliberately carries no foreign key to the elements
     * table (unlike the sync-dependency tables, whose element ids are captured
     * from real synced elements). Here elementId is a best-effort hint parsed from
     * the leading segment of a pinned document id, which a curation rule may set
     * to any value, including a document whose element has been deleted or which
     * maps to no Craft element at all. A hard key would make rebuildForCollection
     * throw on such legitimate rules. The table is fully rebuilt per collection on
     * every curation write (clear then reinsert), so stale rows are harmless and
     * self-heal on the next rebuild of that collection.
     *
     * @param Migration $migration
     * @return void
     * @author CraftPulse
     */
    public static function createCurationIndexTable(Migration $migration): void
    {
        $db = $migration->db;

        if ($db->getTableSchema(Table::CURATION_INDEX) !== null) {
            return;
        }

        $migration->createTable(Table::CURATION_INDEX, [
            'id' => $migration->primaryKey(),
            'collectionHandle' => $migration->string()->notNull(),
            'ruleId' => $migration->string()->notNull(),
            'documentId' => $migration->string()->notNull(),
            'elementId' => $migration->integer()->notNull(),
            'position' => $migration->integer(),
            'dateCreated' => $migration->dateTime()->notNull(),
            'dateUpdated' => $migration->dateTime()->notNull(),
            'uid' => $migration->uid(),
        ]);

        $migration->createIndex(null, Table::CURATION_INDEX, ['elementId']);
        $migration->createIndex(null, Table::CURATION_INDEX, ['collectionHandle', 'ruleId']);
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
     * Drops the plugin's tables.
     *
     * @return void
     * @author CraftPulse
     */
    public function dropTables(): void
    {
        $this->dropTableIfExists(Table::CURATION_INDEX);
        $this->dropTableIfExists(Table::SYNC_DEPENDENCIES);
        $this->dropTableIfExists(Table::SYNC_STATE);
        $this->dropTableIfExists(Table::COLLECTIONS);
    }

    /**
     * Deletes the project config entry.
     *
     * @return void
     * @author CraftPulse
     */
    public function dropProjectConfig(): void
    {
        Craft::$app->projectConfig->remove('typesense');
    }
}
