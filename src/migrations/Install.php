<?php /** @noinspection RepetitiveMethodCallsInspection */

namespace percipiolondon\typesense\migrations;

use Craft;
use craft\db\Migration;
use craft\helpers\MigrationHelper;
use percipiolondon\typesense\db\Table;


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
    }

    public function safeDown()
    {
        return false;
    }

    /**
     * Creates the tables.
     */
    public function createTables()
    {
        $this->createTable(Table::TYPESENSE, [
            'id' => $this->primaryKey(),
            'sectionId' => $this->integer()->notNull(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateSynced' => $this->dateTime(),
            'handle' => $this->string()->notNull(),
            'uid' => $this->uid()->notNull(),
        ]);
    }

}
