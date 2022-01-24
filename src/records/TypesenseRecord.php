<?php

namepsace percipiolondon\typesense\records;

use Craft;

use percipiolondon\typesense\db\Table;
use yii\db\ActiveRecord;
use yii\validators\Validator;

class Typesense extends ActiveRecord
{

    /**
     * @inheritdoc
     * @return string
     */
    public static function tableName(): string
    {
        return Table::TYPESENSE;
    }
}
