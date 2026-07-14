<?php

namespace craftpulse\typesense\models;

use craft\base\Model;

/**
 * @property string $id
 * @property array $synonyms;
 */
class SynonymModel extends Model
{
    public array $synonyms = [];

    public function rules(): array
    {
        return [
            ['index', 'string'],
            ['synonyms', 'array']
        ];
    }
}
