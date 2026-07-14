<?php

namespace craftpulse\typesense\migrations;

use Craft;
use craft\db\Migration;
use craftpulse\typesense\db\Table;
use yii\base\Exception;

/**
 * m241210_132929_add_synonyms migration.
 */
class m241210_132929_synonyms extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
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
     *
     * @return bool
     * @throws Exception
     */
    protected function createTables(): bool
    {
        $tablesCreated = false;

        $tableSchema = Craft::$app->db->schema->getTableSchema(Table::SYNONYMS);
        if ($tableSchema === null) {
            $tablesCreated = true;
            $this->createTable(Table::SYNONYMS, [
                'id' => $this->primaryKey(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),

                'index' => $this->string()->unique()->notNull(),
                'synonyms' => $this->json()->notNull(),
            ]);
        }

        return $tablesCreated;
    }

    /**
     * @inheritdoc
     */
    public function dropTables(): void
    {
        $this->dropTableIfExists(Table::SYNONYMS);
    }
}
