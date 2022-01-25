<?php

namepsace percipiolondon\typesense\records;

use Craft;

use craft\db\ActiveRecord;
use craft\records\FieldLayout;

use percipiolondon\typesense\db\Table;
u
use yii\db\ActiveQueryInterface;

class CollectionRecord extends ActiveRecord
{

    /**
     * @inheritdoc
     * @return string
     */
    public static function tableName(): string
    {
        return Table::COLLECTIONS;
    }

    /**
     * @return ActiveQueryInterface
     */
    public function getFieldLayout(): ActiveQueryInterface
    {
        return $this->hasOne(FieldLayout::class, ['id' => 'fieldLayoutId']);
    }
}
