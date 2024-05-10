<?php

namespace percipiolondon\typesense\migrations;

use Craft;
use craft\db\Migration;
use craft\helpers\MigrationHelper;
use craft\records\Element;
use percipiolondon\typesense\records\DeletionRecord;

/**
 * m240510_090836_delete migration.
 */
class m240510_090836_delete extends Migration
{
    /**
     * @inheritdoc
     */


    public function safeUp(): bool
    {
        // Place migration code here...
        $this->_createTables();
        $this->_addForeignKeys();

        return true;
    }

    private function _createTables(): void {
        if (!$this->db->tableExists(DeletionRecord::tableName())) {
            $this->createTable(DeletionRecord::tableName(), [
                'id' => $this->primaryKey(),
                'elementId' => $this->integer(),
                'siteId' => $this->integer()->notNull(),
                'typesenseId' => $this->string()->notNull(),
            ]);
        }
    }

    private function _addForeignKeys(): void {
        $this->addForeignKey(null, DeletionRecord::tableName(), ['elementId'], Element::tableName(), ['id']);
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m240510_090836_delete cannot be reverted.\n";

        if ($this->db->tableExists(DeletionRecord::tableName())) {
            MigrationHelper::dropAllForeignKeysToTable(DeletionRecord::tableName(), $this);
            MigrationHelper::dropAllForeignKeysOnTable(DeletionRecord::tableName(), $this);
        }
        
        $this->dropTableIfExists(DeletionRecord::tableName());

        return false;
    }
}
