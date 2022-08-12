<?php

namespace percipiolondon\typesense\records;

use craft\db\ActiveRecord;
use craft\records\FieldLayout;

use percipiolondon\typesense\db\Table;

use yii\db\ActiveQueryInterface;

class CollectionRecord extends ActiveRecord
{

    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return Table::COLLECTIONS;
    }

    public function getFieldLayout(): ActiveQueryInterface
    {
        return $this->hasOne(FieldLayout::class, ['id' => 'fieldLayoutId']);
    }
}
