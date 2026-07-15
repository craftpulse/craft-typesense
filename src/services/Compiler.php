<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\services;

use craft\base\Component;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\Tag;
use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\builders\Field;
use craftpulse\typesense\enums\MultisiteStrategy;
use craftpulse\typesense\models\CollectionDefinition;
use craftpulse\typesense\models\FieldMapping;
use craftpulse\typesense\Typesense;

/**
 * Compiles a control-panel-managed collection definition (authored in the
 * mapping UI, persisted to project config keyed by field UID) into a runtime
 * collection builder: the schema (derived types plus overrides, facet, sort,
 * infix, stem, locale) and the generated document path (a field mapping per
 * indexed field, plus geopoint and computed-field handling, which the Documents
 * service already performs). Search weights compile into the collection's
 * preset (query_by_weights) and natural-language descriptions into its metadata.
 *
 * The compiled collection is an ordinary runtime collection, so a CP-managed
 * collection flows through the sync engine, drift comparator, utility, and
 * search layer with no special-casing.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class Compiler extends Component
{
    // Constants
    // =========================================================================

    /**
     * @var array<int, string> The Typesense types a field override may use.
     */
    public const VALID_TYPES = ['string', 'string[]', 'int32', 'int64', 'float', 'bool', 'object', 'object[]', 'geopoint'];

    // Public Methods
    // =========================================================================

    /**
     * Compiles a definition into a runtime collection.
     *
     * @param CollectionDefinition $definition
     * @return Collection
     * @author CraftPulse
     */
    public function compile(CollectionDefinition $definition): Collection
    {
        $collection = Collection::make($definition->name)
            ->elementType($definition->elementType)
            ->multisite(MultisiteStrategy::tryFrom($definition->multisite) ?? MultisiteStrategy::SharedWithSiteFilter);

        $query = $this->_elementQuery($definition);

        if ($query !== null) {
            $collection->elementQuery($query);
        }

        $fields = [];
        $mappings = [];
        $queryBy = [];
        $weights = [];
        $descriptions = [];

        foreach (Typesense::$plugin->getMappingSources()->descriptorsFor($definition) as $descriptor) {
            $mapping = $definition->mappings[$descriptor['uid']] ?? null;

            if (!is_array($mapping) || empty($mapping['indexable'])) {
                continue;
            }

            $key = (string)$descriptor['handle'];
            $type = $this->_resolveType($mapping, (string)$descriptor['derivedType']);

            $fields[] = $this->_field($key, $type, $mapping);
            $mappings[] = $this->_mapping($key, $type, (string)$descriptor['kind']);

            if (in_array($type, ['string', 'string[]'], true) && (int)($mapping['weight'] ?? 0) > 0) {
                $queryBy[] = $key;
                $weights[] = (int)$mapping['weight'];
            }

            if (!empty($mapping['description'])) {
                $descriptions[$key] = (string)$mapping['description'];
            }
        }

        $relevance = $definition->relevance;
        $boostRules = is_array($relevance['boostRules'] ?? null) ? array_values(array_filter(
            $relevance['boostRules'],
            static fn($rule): bool => is_array($rule) && !empty($rule['conditions']),
        )) : [];

        if ($boostRules !== []) {
            $fields[] = Field::make('boost_score', 'float')->optional()->sort();
            $collection->boost($boostRules);
        }

        // Auto-embedding: one vector field embedding the chosen source fields
        // (or the asset image field for CLIP), generated server-side at index.
        if (!empty($definition->embedding['enabled'])) {
            $embeddings = Typesense::$plugin->getEmbeddings();
            $embedField = $embeddings->embedField('embedding', $definition->embedding);

            if ($embedField !== null) {
                $model = (string)($definition->embedding['model'] ?? '');

                // A CLIP model embeds an image field: add the image-type source
                // field and mark it so the document path fills it with base64.
                if ($embeddings->isImageModel($model)) {
                    $imageKey = (string)(($definition->embedding['from'][0]) ?? 'image');
                    $fields[] = Field::image($imageKey)->optional()->store(false);
                    $collection->imageEmbed($imageKey);
                }

                $fields[] = $embedField;
            }
        }

        $collection->fields(...$fields)->mapping(...$mappings);

        $preset = [];

        if ($queryBy !== []) {
            $preset['query_by'] = implode(',', $queryBy);
            $preset['query_by_weights'] = implode(',', $weights);
        }

        $preset += Typesense::$plugin->getRelevance()->presetFragment(
            $relevance,
            Typesense::$plugin->getClient()->getServerCapabilities(),
            $boostRules !== [],
        );

        if ($preset !== []) {
            $collection->preset($preset);
        }

        if ($descriptions !== []) {
            $collection->metadata(['field_descriptions' => $descriptions]);
        }

        return $collection;
    }

    // Private Methods
    // =========================================================================

    /**
     * Builds the element query callable that scopes a definition to its source.
     *
     * @param CollectionDefinition $definition
     * @return callable|null
     * @author CraftPulse
     */
    private function _elementQuery(CollectionDefinition $definition): ?callable
    {
        $source = $definition->source;

        if ($source === null || $source === '') {
            return null;
        }

        $type = $definition->elementType;

        if (is_a($type, Entry::class, true)) {
            return static fn($query) => $query->section($source);
        }

        if (is_a($type, Category::class, true) || is_a($type, Tag::class, true)) {
            return static fn($query) => $query->group($source);
        }

        if (is_a($type, Asset::class, true)) {
            return static fn($query) => $query->volume($source);
        }

        return null;
    }

    /**
     * Builds a schema field from a mapping.
     *
     * @param string $key
     * @param string $type
     * @param array<string, mixed> $mapping
     * @return Field
     * @author CraftPulse
     */
    private function _field(string $key, string $type, array $mapping): Field
    {
        // Craft content is frequently empty, so mapped fields are optional; the
        // generated path omits null values and Typesense accepts the absence.
        $field = Field::make($key, $type)->optional();

        if (!empty($mapping['facet'])) {
            $field->facet();
        }

        if (!empty($mapping['sortable'])) {
            $field->sort();
        }

        if (!empty($mapping['infix'])) {
            $field->infix();
        }

        if (!empty($mapping['stem'])) {
            $field->stem();
        }

        if (!empty($mapping['locale'])) {
            $field->locale((string)$mapping['locale']);
        }

        return $field;
    }

    /**
     * Builds the generated-path field mapping from a descriptor kind.
     *
     * @param string $key
     * @param string $type
     * @param string $kind
     * @return FieldMapping
     * @author CraftPulse
     */
    private function _mapping(string $key, string $type, string $kind): FieldMapping
    {
        $mapping = new FieldMapping();
        $mapping->documentKey = $key;
        $mapping->type = $type;
        $mapping->indexed = true;

        if ($kind === 'pseudo') {
            $mapping->attribute = $key;
        } elseif ($kind === 'computed') {
            $mapping->computedFieldName = $key;
        } else {
            $mapping->fieldHandle = $key;
        }

        return $mapping;
    }

    /**
     * Resolves the field type: the bounded override when valid, else the
     * server-derived type.
     *
     * @param array<string, mixed> $mapping
     * @param string $derivedType
     * @return string
     * @author CraftPulse
     */
    private function _resolveType(array $mapping, string $derivedType): string
    {
        $override = isset($mapping['type']) ? (string)$mapping['type'] : '';

        return in_array($override, self::VALID_TYPES, true) ? $override : $derivedType;
    }
}
