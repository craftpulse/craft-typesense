<?php

namespace percipiolondon\typesense\models;

use craft\base\Model;
use craft\validators\DateTimeValidator;
use craft\validators\HandleValidator;
use craft\validators\UniqueValidator;

class CollectionModel extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var int|null ID
     */
    public $id;

    /**
     * @var int|null Section ID
     */
    public $sectionId;

    /**
     * @var \DateTime|null Collection's creation date
     */
    public $dateCreated;

    /**
     * @var \DateTime|null Collection's sync date
     */
    public $dateSynced;

    /**
     * @var string|null Section's handle
     */
    public $handle;

    /**
     * @var string|null Collection's UID
     */
    public $uid;

    // Public Methods
    // =========================================================================

    public function datetimeAttributes(): array
    {
        $attributes = parent::datetimeAttributes();
        $attributes[] = 'dateSynced';
        return $attributes;
    }

    /**
     * Returns the validation rules for attributes.
     *
     * Validation rules are used by [[validate()]] to check if attribute values are valid.
     * Child classes may override this method to declare different validation rules.
     *
     * More info: http://www.yiiframework.com/doc-2.0/guide-input-validation.html
     *
     * @return array
     */
    public function rules()
    {
        return [
            [['id', 'sectionId'], 'number', 'integerOnly' => true],
            [['uid'], 'string'],
            [['handle'], HandleValidator::class],
            [['handle'], UniqueValidator::class],
            [['handle'], 'string', 'max' => 255],
            [['dateCreated', 'dateSynced'], DateTimeValidator::class],
            [['id', 'uid', 'sectionId', 'dateCreated'], 'required'],
        ];
    }

    public function getConfig(): array
    {
        return [
            'handle' => $this->handle,
        ];
    }
}
