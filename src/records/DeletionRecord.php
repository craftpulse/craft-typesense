<?php

/** @noinspection RepetitiveMethodCallsInspection */

namespace percipiolondon\typesense\migrations;

use Craft;
use craft\db\Migration;
use craft\helpers\MigrationHelper;
use craft\records\Element;

use percipiolondon\typesense\db\Table;
use percipiolondon\typesense\records\DeletionRecord;

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
        $this->addForeignKeys();

        return true;
    }

    public function safeDown()
    {
        $this->dropTables();

        return true;
    }

    public function addForeignKeys(): void {
        $this->addForeignKey(null, DeletionRecord::tableName(), ['elementId'], Element::tableName(), ['id']);
    }

    /**
     * Creates the tables.
     */
    public function createTables()
    {
        if (!$this->db->tableExists(DeletionRecord::tableName())) {
            $this->createTable(DeletionRecord::tableName(), [
                'id' => $this->primaryKey(),
                'elementId' => $this->integer(),
                'siteId' => $this->integer()->notNull(),
                'typesenseId' => $this->string()->notNull(),
            ]);
        }
    }

    /**
     * Drop the tables
     */
    public function dropTables()
    {
        if ($this->db->tableExists(DeletionRecord::tableName())) {
            MigrationHelper::dropAllForeignKeysToTable(DeletionRecord::tableName(), $this);
            MigrationHelper::dropAllForeignKeysOnTable(DeletionRecord::tableName(), $this);
        }
        
        $this->dropTableIfExists(DeletionRecord::tableName());

    }
}
