<?php

namespace percipiolondon\typesense\records;

use craft\db\ActiveRecord;
use craft\base\Element;

use percipiolondon\typesense\db\Table;

use yii\db\ActiveQueryInterface;

class DeletionRecord extends ActiveRecord
{

    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return Table::DELETIONS;
    }

    public function getElement(): ActiveQueryInterface
    {
        return $this->hasMany(Element::class, ['id' => 'id']);
    }
}
