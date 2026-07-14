<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense;

use Craft;
use craft\base\Element;
use craft\elements\db\ElementQuery;
use craft\elements\Entry;
use Exception;

/**
 * Legacy collection-index definition, retained as the bridge that reads a
 * pre-rebuild config array into a shape the migration tooling understands.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class TypesenseCollectionIndex
{
    // Public Properties
    // =========================================================================

    /**
     * @var string
     */
    public string $indexName;

    /**
     * @var string
     */
    public string $section;

    /**
     * @var array<string, mixed>
     */
    public array $schema = [];

    /**
     * @var class-string<Element>
     */
    public string $elementType = Entry::class;

    /**
     * @var ElementQuery<int, Element>
     */
    public ElementQuery $criteria;

    /**
     * @var callable|string|array<int|string, mixed>|null
     */
    public $resolver = null;

    // Public Methods
    // =========================================================================

    /**
     * @param array<string, mixed> $schema
     * @author CraftPulse
     */
    public function __construct(array $schema)
    {
        $this->indexName = $schema['name'];
        $this->section = $schema['section'];
        $this->schema = $schema;
        /** @var ElementQuery<int, Element> $query */
        $query = $this->elementType::find();
        $this->criteria = $query;
    }

    /**
     * @param array<string, mixed> $schema
     * @return self
     * @author CraftPulse
     */
    public static function create(array $schema): self
    {
        return new self($schema);
    }

    /**
     * @param class-string<Element> $class
     * @return self
     * @throws Exception
     * @author CraftPulse
     */
    public function elementType(string $class): self
    {
        if (!is_subclass_of($class, Element::class)) {
            throw new Exception(sprintf('Invalid Element Type %s', $class));
        }

        $this->elementType = $class;

        return $this;
    }

    /**
     * @param callable $criteria
     * @return self
     * @throws Exception
     * @author CraftPulse
     */
    public function criteria(callable $criteria): self
    {
        $elementQuery = $criteria($this->elementType::find());

        if (!$elementQuery instanceof ElementQuery) {
            throw new Exception('You must return a valid ElementQuery from the criteria function.');
        }

        if (is_null($elementQuery->siteId)) {
            $elementQuery->siteId = Craft::$app->getSites()->getPrimarySite()->id;
        }

        $this->criteria = $elementQuery;

        return $this;
    }

    /**
     * @param callable|string|array<int|string, mixed> $resolver
     * @return self
     * @author CraftPulse
     */
    public function resolver(callable|string|array $resolver): self
    {
        $this->resolver = $resolver;

        return $this;
    }
}
