<?php

namespace craftpulse\typesense\db;

abstract class Table
{
    /**
     * @var string
     */
    public const COLLECTIONS = "{{%typesense_collections}}";
    public const SYNONYMS = '{{%typesense_synonyms}}';
}
