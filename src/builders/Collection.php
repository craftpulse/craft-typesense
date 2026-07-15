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

use craft\elements\Entry;
use craftpulse\typesense\enums\MultisiteStrategy;
use craftpulse\typesense\models\FieldMapping;
use craftpulse\typesense\models\ServerCapabilities;

/**
 * Fluent builder for a Typesense collection.
 *
 * Authors the collection schema (fields plus top-level parameters) as a plain
 * array via toSchema(), and holds the runtime concerns that never serialize:
 * the element type, one or more element-query callables, the document transform
 * closure, active statuses, batch page size, auto-sync flag, multisite strategy,
 * per-feature managedBy defaults, a search preset, and attached computed-field
 * names. The collection name is the logical name; the environment prefix is
 * applied by the registry's resolver, never here.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class Collection
{
    // Private Properties
    // =========================================================================

    /**
     * @var string The logical collection name (unprefixed).
     */
    private string $_name;

    /**
     * @var Field[] The field builders.
     */
    private array $_fields = [];

    /**
     * @var class-string The element class the collection indexes.
     */
    private string $_elementType = Entry::class;

    /**
     * @var array<int, callable> The element-query callables.
     */
    private array $_elementQueries = [];

    /**
     * @var callable|null The document transform callable.
     */
    private $_transform = null;

    /**
     * @var array<int, string>|null The element statuses treated as live.
     */
    private ?array $_activeStatuses = null;

    /**
     * @var int The batch page size.
     */
    private int $_pageSize = 100;

    /**
     * @var bool Whether element events trigger sync.
     */
    private bool $_autoSync = true;

    /**
     * @var MultisiteStrategy The multisite strategy.
     */
    private MultisiteStrategy $_multisite = MultisiteStrategy::CollectionPerSite;

    /**
     * @var string|null The default sorting field.
     */
    private ?string $_defaultSortingField = null;

    /**
     * @var string|null The synonyms managedBy override (config|cp), or null to inherit.
     */
    private ?string $_synonymsManagedBy = null;

    /**
     * @var string|null The curation managedBy override (config|cp), or null to inherit.
     */
    private ?string $_curationManagedBy = null;

    /**
     * @var array<string, mixed>|null The per-collection search preset.
     */
    private ?array $_preset = null;

    /**
     * @var bool Whether the anonymous front-end search endpoint may query this
     * collection. Off by default: the server-keyed proxy must never expose a
     * collection unless it is explicitly opted in.
     */
    private bool $_searchable = false;

    /**
     * @var array<int, array<string, mixed>> Additive boost rules baked into an
     * indexed boost_score field at index time.
     */
    private array $_boostRules = [];

    /**
     * @var string The document key the boost score is baked into.
     */
    private string $_boostField = 'boost_score';

    /**
     * @var string|null The document key that carries an asset's base64 image data
     * for CLIP auto-embedding, or null when there is no image embedding.
     */
    private ?string $_imageEmbedField = null;

    /**
     * @var array<int, string> The attached computed-field names.
     */
    private array $_computedFields = [];

    /**
     * @var FieldMapping[] The field mappings for the generated document path.
     */
    private array $_mapping = [];

    /**
     * @var array<int, string>|null Collection-level token separators.
     */
    private ?array $_tokenSeparators = null;

    /**
     * @var array<int, string>|null Collection-level symbols to index.
     */
    private ?array $_symbolsToIndex = null;

    /**
     * @var array<string, mixed>|null Arbitrary collection metadata.
     */
    private ?array $_metadata = null;

    /**
     * @var array<string, mixed> Raw top-level schema overrides.
     */
    private array $_raw = [];

    /**
     * @var array<int, array<string, mixed>> Config-seeded synonym definitions.
     */
    private array $_synonymDefinitions = [];

    /**
     * @var array<int, array<string, mixed>> Config-seeded curation rules.
     */
    private array $_curationRules = [];

    /**
     * @var array<int, string> Config-seeded stopwords.
     */
    private array $_stopwords = [];

    // Public Methods
    // =========================================================================

    /**
     * @param string $name
     * @author CraftPulse
     */
    public function __construct(string $name)
    {
        $this->_name = $name;
    }

    /**
     * @param string $name
     * @return self
     * @author CraftPulse
     */
    public static function make(string $name): self
    {
        return new self($name);
    }

    // Schema builders
    // -------------------------------------------------------------------------

    /**
     * Sets the collection fields.
     *
     * @param Field ...$fields
     * @return self
     * @author CraftPulse
     */
    public function fields(Field ...$fields): self
    {
        $this->_fields = array_values($fields);

        return $this;
    }

    /**
     * Adds a single field.
     *
     * @param Field $field
     * @return self
     * @author CraftPulse
     */
    public function addField(Field $field): self
    {
        $this->_fields[] = $field;

        return $this;
    }

    /**
     * @param string $field
     * @return self
     * @author CraftPulse
     */
    public function defaultSortingField(string $field): self
    {
        $this->_defaultSortingField = $field;

        return $this;
    }

    /**
     * @param array<int, string> $separators
     * @return self
     * @author CraftPulse
     */
    public function tokenSeparators(array $separators): self
    {
        $this->_tokenSeparators = $separators;

        return $this;
    }

    /**
     * @param array<int, string> $symbols
     * @return self
     * @author CraftPulse
     */
    public function symbolsToIndex(array $symbols): self
    {
        $this->_symbolsToIndex = $symbols;

        return $this;
    }

    /**
     * @param array<string, mixed> $metadata
     * @return self
     * @author CraftPulse
     */
    public function metadata(array $metadata): self
    {
        $this->_metadata = $metadata;

        return $this;
    }

    /**
     * @param string $key
     * @param mixed $value
     * @return self
     * @author CraftPulse
     */
    public function raw(string $key, mixed $value): self
    {
        $this->_raw[$key] = $value;

        return $this;
    }

    // Runtime builders
    // -------------------------------------------------------------------------

    /**
     * @param class-string $class
     * @return self
     * @author CraftPulse
     */
    public function elementType(string $class): self
    {
        $this->_elementType = $class;

        return $this;
    }

    /**
     * Sets a single element-query callable.
     *
     * @param callable $query
     * @return self
     * @author CraftPulse
     */
    public function elementQuery(callable $query): self
    {
        $this->_elementQueries = [$query];

        return $this;
    }

    /**
     * Sets multiple element-query callables (multi-source / multi-document).
     *
     * @param array<int, callable> $queries
     * @return self
     * @author CraftPulse
     */
    public function elementQueries(array $queries): self
    {
        $this->_elementQueries = array_values($queries);

        return $this;
    }

    /**
     * Sets the document transform. The callable receives the element and a
     * registerDependency callback: fn($element, callable $registerDependency): array.
     *
     * @param callable $transform
     * @return self
     * @author CraftPulse
     */
    public function transform(callable $transform): self
    {
        $this->_transform = $transform;

        return $this;
    }

    /**
     * @param array<int, string> $statuses
     * @return self
     * @author CraftPulse
     */
    public function activeStatuses(array $statuses): self
    {
        $this->_activeStatuses = $statuses;

        return $this;
    }

    /**
     * @param int $pageSize
     * @return self
     * @author CraftPulse
     */
    public function pageSize(int $pageSize): self
    {
        $this->_pageSize = $pageSize;

        return $this;
    }

    /**
     * @param bool $autoSync
     * @return self
     * @author CraftPulse
     */
    public function autoSync(bool $autoSync = true): self
    {
        $this->_autoSync = $autoSync;

        return $this;
    }

    /**
     * @param MultisiteStrategy $strategy
     * @return self
     * @author CraftPulse
     */
    public function multisite(MultisiteStrategy $strategy): self
    {
        $this->_multisite = $strategy;

        return $this;
    }

    /**
     * @param string $managedBy
     * @return self
     * @author CraftPulse
     */
    public function synonyms(string $managedBy): self
    {
        $this->_synonymsManagedBy = $managedBy;

        return $this;
    }

    /**
     * @param string $managedBy
     * @return self
     * @author CraftPulse
     */
    public function curation(string $managedBy): self
    {
        $this->_curationManagedBy = $managedBy;

        return $this;
    }

    /**
     * @param array<string, mixed> $preset
     * @return self
     * @author CraftPulse
     */
    public function preset(array $preset): self
    {
        $this->_preset = $preset;

        return $this;
    }

    /**
     * Opts this collection into the anonymous front-end search endpoint. Off by
     * default, so the server-keyed proxy never exposes a collection unless the
     * author explicitly allows it.
     *
     * @param bool $value
     * @return self
     * @author CraftPulse
     */
    public function searchable(bool $value = true): self
    {
        $this->_searchable = $value;

        return $this;
    }

    /**
     * Additive boost rules, baked into the indexed boost_score field at index
     * time (the deterministic alternative to the first-match-wins `_eval` bug).
     * Each rule: `weight`, `match` (`all`|`any`), and `conditions`
     * (`field`, `operator`, `value`).
     *
     * @param array<int, array<string, mixed>> $rules
     * @param string $field
     * @return self
     * @author CraftPulse
     */
    public function boost(array $rules, string $field = 'boost_score'): self
    {
        $this->_boostRules = array_values($rules);
        $this->_boostField = $field;

        return $this;
    }

    /**
     * Marks a document key as the CLIP image-embedding source: the generated
     * document path fills it with an asset's base64-encoded file at index time.
     *
     * @param string $field
     * @return self
     * @author CraftPulse
     */
    public function imageEmbed(string $field): self
    {
        $this->_imageEmbedField = $field;

        return $this;
    }

    /**
     * Config-seeded synonym definitions. Each entry: id, synonyms[], optional
     * root (one-way), locale.
     *
     * @param array<int, array<string, mixed>> $definitions
     * @return self
     * @author CraftPulse
     */
    public function synonymDefinitions(array $definitions): self
    {
        $this->_synonymDefinitions = array_values($definitions);

        return $this;
    }

    /**
     * Config-seeded curation rules. Each entry: id, rule (query/match/filter_by),
     * includes, excludes, filter_by, sort_by, effective_from_ts, effective_to_ts.
     *
     * @param array<int, array<string, mixed>> $rules
     * @return self
     * @author CraftPulse
     */
    public function curationRules(array $rules): self
    {
        $this->_curationRules = array_values($rules);

        return $this;
    }

    /**
     * Config-seeded stopwords for the collection's search path.
     *
     * @param array<int, string> $stopwords
     * @return self
     * @author CraftPulse
     */
    public function stopwords(array $stopwords): self
    {
        $this->_stopwords = array_values($stopwords);

        return $this;
    }

    /**
     * Attaches registered computed fields by name.
     *
     * @param string ...$names
     * @return self
     * @author CraftPulse
     */
    public function computedFields(string ...$names): self
    {
        $this->_computedFields = array_values($names);

        return $this;
    }

    /**
     * Sets the field mappings for the generated document path (used when no
     * transform is set).
     *
     * @param FieldMapping ...$mappings
     * @return self
     * @author CraftPulse
     */
    public function mapping(FieldMapping ...$mappings): self
    {
        $this->_mapping = array_values($mappings);

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
        return $this->_name;
    }

    /**
     * @return Field[]
     * @author CraftPulse
     */
    public function getFields(): array
    {
        return $this->_fields;
    }

    /**
     * @return class-string
     * @author CraftPulse
     */
    public function getElementType(): string
    {
        return $this->_elementType;
    }

    /**
     * @return array<int, callable>
     * @author CraftPulse
     */
    public function getElementQueries(): array
    {
        return $this->_elementQueries;
    }

    /**
     * @return callable|null
     * @author CraftPulse
     */
    public function getTransform(): ?callable
    {
        return $this->_transform;
    }

    /**
     * @return array<int, string>|null
     * @author CraftPulse
     */
    public function getActiveStatuses(): ?array
    {
        return $this->_activeStatuses;
    }

    /**
     * @return int
     * @author CraftPulse
     */
    public function getPageSize(): int
    {
        return $this->_pageSize;
    }

    /**
     * @return bool
     * @author CraftPulse
     */
    public function getAutoSync(): bool
    {
        return $this->_autoSync;
    }

    /**
     * @return MultisiteStrategy
     * @author CraftPulse
     */
    public function getMultisiteStrategy(): MultisiteStrategy
    {
        return $this->_multisite;
    }

    /**
     * @return string|null
     * @author CraftPulse
     */
    public function getSynonymsManagedBy(): ?string
    {
        return $this->_synonymsManagedBy;
    }

    /**
     * @return string|null
     * @author CraftPulse
     */
    public function getCurationManagedBy(): ?string
    {
        return $this->_curationManagedBy;
    }

    /**
     * @return array<string, mixed>|null
     * @author CraftPulse
     */
    public function getPreset(): ?array
    {
        return $this->_preset;
    }

    /**
     * @return array<int, array<string, mixed>>
     * @author CraftPulse
     */
    public function getBoostRules(): array
    {
        return $this->_boostRules;
    }

    /**
     * @return string
     * @author CraftPulse
     */
    public function getBoostField(): string
    {
        return $this->_boostField;
    }

    /**
     * Whether the anonymous front-end search endpoint may query this collection.
     *
     * @return bool
     * @author CraftPulse
     */
    public function isSearchable(): bool
    {
        return $this->_searchable;
    }

    /**
     * @return string|null
     * @author CraftPulse
     */
    public function getImageEmbedField(): ?string
    {
        return $this->_imageEmbedField;
    }

    /**
     * @return array<int, string>
     * @author CraftPulse
     */
    public function getComputedFieldNames(): array
    {
        return $this->_computedFields;
    }

    /**
     * @return FieldMapping[]
     * @author CraftPulse
     */
    public function getMapping(): array
    {
        return $this->_mapping;
    }

    /**
     * @return array<int, array<string, mixed>>
     * @author CraftPulse
     */
    public function getSynonymDefinitions(): array
    {
        return $this->_synonymDefinitions;
    }

    /**
     * @return array<int, array<string, mixed>>
     * @author CraftPulse
     */
    public function getCurationRules(): array
    {
        return $this->_curationRules;
    }

    /**
     * @return array<int, string>
     * @author CraftPulse
     */
    public function getStopwords(): array
    {
        return $this->_stopwords;
    }

    // Emission + validation
    // -------------------------------------------------------------------------

    /**
     * Emits the Typesense collection schema (logical name; prefix applied by the
     * registry resolver). enable_nested_fields is set automatically when any
     * object field is present.
     *
     * @return array<string, mixed>
     * @author CraftPulse
     */
    public function toSchema(): array
    {
        $schema = [
            'name' => $this->_name,
            'fields' => array_map(static fn(Field $field): array => $field->toArray(), $this->_fields),
        ];

        if ($this->_hasNestedField()) {
            $schema['enable_nested_fields'] = true;
        }

        if ($this->_defaultSortingField !== null) {
            $schema['default_sorting_field'] = $this->_defaultSortingField;
        }

        if ($this->_tokenSeparators !== null) {
            $schema['token_separators'] = $this->_tokenSeparators;
        }

        if ($this->_symbolsToIndex !== null) {
            $schema['symbols_to_index'] = $this->_symbolsToIndex;
        }

        if ($this->_metadata !== null) {
            $schema['metadata'] = $this->_metadata;
        }

        return array_merge($schema, $this->_raw);
    }

    /**
     * Returns version-support errors against the given server capabilities,
     * aggregating every field's validation.
     *
     * @param ServerCapabilities $capabilities
     * @return array<int, string>
     * @author CraftPulse
     */
    public function validate(ServerCapabilities $capabilities): array
    {
        $errors = [];

        foreach ($this->_fields as $field) {
            $errors = array_merge($errors, $field->validate($capabilities));
        }

        return $errors;
    }

    // Private Methods
    // =========================================================================

    /**
     * @return bool
     * @author CraftPulse
     */
    private function _hasNestedField(): bool
    {
        foreach ($this->_fields as $field) {
            if ($field->isNested()) {
                return true;
            }
        }

        return false;
    }
}
