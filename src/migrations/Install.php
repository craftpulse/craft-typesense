<?php

/** @noinspection RepetitiveMethodCallsInspection */

namespace craftpulse\typesense\migrations;

use Craft;
use craft\db\Migration;

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
        $tableSchema = Craft::$app->db->schema->getTableSchema(Table::COLLECTIONS);
        if ($tableSchema === null) {
            $this->createTable(Table::COLLECTIONS, [
                'id' => $this->primaryKey(),
                'fieldLayoutId' => $this->integer(),
                'name' => $this->string()->notNull(),
                'handle' => $this->string()->notNull(),
                'sectionId' => $this->integer()->notNull(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateSynced' => $this->dateTime(),
                'uid' => $this->uid(),
            ]);

            $this->createTable(Table::SYNONYMS, [
                'id' => $this->primaryKey(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),

                'index' => $this->string()->unique()->notNull(),
                'synonyms' => $this->json()->notNull(),
            ]);
        }
    }

    /**
     * Drop the tables
     */
    public function dropTables()
    {
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
