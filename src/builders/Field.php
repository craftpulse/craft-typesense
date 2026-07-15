<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\builders;

use Craft;
use craftpulse\typesense\models\ServerCapabilities;

/**
 * Fluent builder for a single Typesense collection field.
 *
 * Covers the entire Typesense field schema: every type, the facet/sort/optional/
 * index/store toggles, infix, stemming and locale, range_index, field-level token
 * separators and symbols, geopoint, object and object arrays, cross-collection
 * references (JOINs), wildcard/auto, vector fields (num_dim, vec_dist, hnsw_params,
 * auto-embedding), and a raw() escape hatch for schema keys the builder does not
 * model yet. Emits a plain array via toArray(); no closures are ever held here.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class Field
{
    // Constants
    // =========================================================================

    /**
     * @var string The wildcard field name that enables auto-schema detection.
     */
    public const WILDCARD = '.*';

    // Private Properties
    // =========================================================================

    /**
     * @var array<string, mixed> The emitted field schema, keyed by Typesense property.
     */
    private array $_schema;

    // Public Methods
    // =========================================================================

    /**
     * @param string $name
     * @param string $type
     * @author CraftPulse
     */
    public function __construct(string $name, string $type)
    {
        $this->_schema = ['name' => $name, 'type' => $type];
    }

    /**
     * Creates a field of an explicit type.
     *
     * @param string $name
     * @param string $type
     * @return self
     * @author CraftPulse
     */
    public static function make(string $name, string $type): self
    {
        return new self($name, $type);
    }

    // Type factories
    // -------------------------------------------------------------------------

    /**
     * @param string $name
     * @return self
     * @author CraftPulse
     */
    public static function string(string $name): self
    {
        return new self($name, 'string');
    }

    /**
     * @param string $name
     * @return self
     * @author CraftPulse
     */
    public static function stringArray(string $name): self
    {
        return new self($name, 'string[]');
    }

    /**
     * @param string $name
     * @return self
     * @author CraftPulse
     */
    public static function int32(string $name): self
    {
        return new self($name, 'int32');
    }

    /**
     * @param string $name
     * @return self
     * @author CraftPulse
     */
    public static function int64(string $name): self
    {
        return new self($name, 'int64');
    }

    /**
     * @param string $name
     * @return self
     * @author CraftPulse
     */
    public static function float(string $name): self
    {
        return new self($name, 'float');
    }

    /**
     * @param string $name
     * @return self
     * @author CraftPulse
     */
    public static function bool(string $name): self
    {
        return new self($name, 'bool');
    }

    /**
     * @param string $name
     * @return self
     * @author CraftPulse
     */
    public static function geopoint(string $name): self
    {
        return new self($name, 'geopoint');
    }

    /**
     * @param string $name
     * @return self
     * @author CraftPulse
     */
    public static function object(string $name): self
    {
        return new self($name, 'object');
    }

    /**
     * @param string $name
     * @return self
     * @author CraftPulse
     */
    public static function objectArray(string $name): self
    {
        return new self($name, 'object[]');
    }

    /**
     * @param string $name
     * @return self
     * @author CraftPulse
     */
    public static function image(string $name): self
    {
        return new self($name, 'image');
    }

    /**
     * An auto-typed field. Pass a RegEx name (or use wildcard()) for auto-schema.
     *
     * @param string $name
     * @return self
     * @author CraftPulse
     */
    public static function auto(string $name): self
    {
        return new self($name, 'auto');
    }

    /**
     * The wildcard auto-schema field (name ".*", type auto).
     *
     * @return self
     * @author CraftPulse
     */
    public static function wildcard(): self
    {
        return new self(self::WILDCARD, 'auto');
    }

    /**
     * A vector field (float[] with num_dim) for KNN/semantic search.
     *
     * @param string $name
     * @param int $numDim
     * @return self
     * @author CraftPulse
     */
    public static function vector(string $name, int $numDim): self
    {
        $field = new self($name, 'float[]');
        $field->_schema['num_dim'] = $numDim;

        return $field;
    }

    // Property setters
    // -------------------------------------------------------------------------

    /**
     * @param bool $value
     * @return self
     * @author CraftPulse
     */
    public function facet(bool $value = true): self
    {
        $this->_schema['facet'] = $value;

        return $this;
    }

    /**
     * @param bool $value
     * @return self
     * @author CraftPulse
     */
    public function optional(bool $value = true): self
    {
        $this->_schema['optional'] = $value;

        return $this;
    }

    /**
     * @param bool $value
     * @return self
     * @author CraftPulse
     */
    public function index(bool $value = true): self
    {
        $this->_schema['index'] = $value;

        return $this;
    }

    /**
     * @param bool $value
     * @return self
     * @author CraftPulse
     */
    public function store(bool $value = true): self
    {
        $this->_schema['store'] = $value;

        return $this;
    }

    /**
     * @param bool $value
     * @return self
     * @author CraftPulse
     */
    public function sort(bool $value = true): self
    {
        $this->_schema['sort'] = $value;

        return $this;
    }

    /**
     * @param bool $value
     * @return self
     * @author CraftPulse
     */
    public function infix(bool $value = true): self
    {
        $this->_schema['infix'] = $value;

        return $this;
    }

    /**
     * @param bool $value
     * @return self
     * @author CraftPulse
     */
    public function stem(bool $value = true): self
    {
        $this->_schema['stem'] = $value;

        return $this;
    }

    /**
     * Sets a custom stemming dictionary (implies stemming).
     *
     * @param string $dictionary
     * @return self
     * @author CraftPulse
     */
    public function stemDictionary(string $dictionary): self
    {
        $this->_schema['stem_dictionary'] = $dictionary;
        // A stem dictionary implies stemming (Typesense sets stem: true
        // automatically); reflect that in the schema we send and store.
        // https://typesense.org/docs/30.2/api/stemming.html
        $this->_schema['stem'] = true;

        return $this;
    }

    /**
     * @param string $locale
     * @return self
     * @author CraftPulse
     */
    public function locale(string $locale): self
    {
        $this->_schema['locale'] = $locale;

        return $this;
    }

    /**
     * @param bool $value
     * @return self
     * @author CraftPulse
     */
    public function rangeIndex(bool $value = true): self
    {
        $this->_schema['range_index'] = $value;

        return $this;
    }

    /**
     * @param array<int, string> $separators
     * @return self
     * @author CraftPulse
     */
    public function tokenSeparators(array $separators): self
    {
        $this->_schema['token_separators'] = $separators;

        return $this;
    }

    /**
     * @param array<int, string> $symbols
     * @return self
     * @author CraftPulse
     */
    public function symbolsToIndex(array $symbols): self
    {
        $this->_schema['symbols_to_index'] = $symbols;

        return $this;
    }

    /**
     * Declares a cross-collection reference (JOIN), for example "authors.id".
     * Fluent-config only; the Pro mapping UI deliberately never surfaces this.
     *
     * @param string $reference
     * @return self
     * @author CraftPulse
     */
    public function reference(string $reference): self
    {
        $this->_schema['reference'] = $reference;

        return $this;
    }

    /**
     * @param bool $value
     * @return self
     * @author CraftPulse
     */
    public function asyncReference(bool $value = true): self
    {
        $this->_schema['async_reference'] = $value;

        return $this;
    }

    /**
     * @param bool $value
     * @return self
     * @author CraftPulse
     */
    public function cascadeDelete(bool $value = true): self
    {
        $this->_schema['cascade_delete'] = $value;

        return $this;
    }

    /**
     * @param string $metric
     * @return self
     * @author CraftPulse
     */
    public function vecDist(string $metric): self
    {
        $this->_schema['vec_dist'] = $metric;

        return $this;
    }

    /**
     * @param array<string, mixed> $params
     * @return self
     * @author CraftPulse
     */
    public function hnswParams(array $params): self
    {
        $this->_schema['hnsw_params'] = $params;

        return $this;
    }

    /**
     * @param int $numDim
     * @return self
     * @author CraftPulse
     */
    public function numDim(int $numDim): self
    {
        $this->_schema['num_dim'] = $numDim;

        return $this;
    }

    /**
     * Sets the auto-embedding source fields.
     *
     * @param array<int, string> $fields
     * @return self
     * @author CraftPulse
     */
    public function embedFrom(array $fields): self
    {
        $embed = $this->_schema['embed'] ?? [];
        $embed['from'] = $fields;
        $this->_schema['embed'] = $embed;

        return $this;
    }

    /**
     * Sets the auto-embedding model and any provider-specific model config.
     *
     * @param string $modelName
     * @param array<string, mixed> $config
     * @return self
     * @author CraftPulse
     */
    public function model(string $modelName, array $config = []): self
    {
        $embed = $this->_schema['embed'] ?? [];
        $embed['model_config'] = array_merge(['model_name' => $modelName], $config);
        $this->_schema['embed'] = $embed;

        return $this;
    }

    /**
     * Sets an arbitrary schema key, for Typesense features the builder does not model.
     *
     * @param string $key
     * @param mixed $value
     * @return self
     * @author CraftPulse
     */
    public function raw(string $key, mixed $value): self
    {
        $this->_schema[$key] = $value;

        return $this;
    }

    // Accessors
    // -------------------------------------------------------------------------

    /**
     * @return string
     * @author CraftPulse
     */
    public function getName(): string
    {
        return $this->_schema['name'];
    }

    /**
     * @return string
     * @author CraftPulse
     */
    public function getType(): string
    {
        return $this->_schema['type'];
    }

    /**
     * Whether this is a nested (object) field that requires enable_nested_fields.
     *
     * @return bool
     * @author CraftPulse
     */
    public function isNested(): bool
    {
        return in_array($this->getType(), ['object', 'object[]'], true);
    }

    /**
     * Emits the Typesense field schema array.
     *
     * @return array<string, mixed>
     * @author CraftPulse
     */
    public function toArray(): array
    {
        return $this->_schema;
    }

    /**
     * Returns version-support errors against the given server capabilities.
     *
     * @param ServerCapabilities $capabilities
     * @return array<int, string>
     * @author CraftPulse
     */
    public function validate(ServerCapabilities $capabilities): array
    {
        $errors = [];

        if (array_key_exists('cascade_delete', $this->_schema) && !$capabilities->atLeast(ServerCapabilities::VERSION_CASCADE_DELETE)) {
            $errors[] = Craft::t('typesense', 'Field "{name}" uses cascade_delete, which requires Typesense v{version} or later.', [
                'name' => $this->getName(),
                'version' => ServerCapabilities::VERSION_CASCADE_DELETE,
            ]);
        }

        return $errors;
    }
}
