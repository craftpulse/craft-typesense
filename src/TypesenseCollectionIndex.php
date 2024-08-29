<?php

namespace percipiolondon\typesense;

use Craft;
use craft\base\Element;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use Exception;

class TypesenseCollectionIndex
{
    /** @var string */
    public $indexName;

    /** @var string */
    public $section;

    /** @var array */
    public $schema = [];

    /** @var string */
    public $elementType = Entry::class;

    /** @var ElementQuery */
    public $criteria;

    /** @var callable|string|array */
    public $resolver;

    public function __construct(array $schema)
    {
        $this->indexName = $schema['name'];
        $this->section = $schema['section'];
        $this->schema = $schema;
    }

    public static function create(array $schema): self
    {
        return new self($schema);
    }

    public function elementType(string $class): self
    {
        if (!is_subclass_of($class, Element::class)) {
            throw new Exception(sprintf('Invalid Element Type %s', $class));
        }

        $this->elementType = $class;

        return $this;
    }

    public function criteria(callable $criteria): self
    {
        $this->criteria = $criteria;

        return $this;
    }

    public function getCriteria(): ElementQuery|array
    {
        if (!isset($this->criteria)) {
            return $this->criteria = $this->elementType::find();
        }

        if (is_callable($this->criteria)) {
            $elementQuery = call_user_func(
                $this->criteria,
                $this->elementType::find()
            );

            if (!$elementQuery instanceof ElementQuery) {
                throw new Exception('You must return a valid ElementQuery from the criteria function.');
            }

            if (is_null($elementQuery->siteId)) {
                $elementQuery->siteId = "*";
            }

            $this->criteria = $elementQuery;
        }

        return $this;
    }

    /*
     * @param $resolver callable|string|array|resolverAbstract
     */
    public function resolver($resolver): self
    {
        $this->resolver = $resolver;

        return $this;
    }
}
