<?php

namespace percipiolondon\typesense\db;

abstract class Table
{
    /**
     * @var string
     */
    public const COLLECTIONS = "{{%typesense_collections}}";
    public const DELETIONS = "{{%typesense_deletions}}";
}
