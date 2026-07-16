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
use craftpulse\typesense\fieldlayoutelements\MappingElementInterface;
use craftpulse\typesense\helpers\Locale;
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
            ->searchable($definition->searchable)
            ->multisite(MultisiteStrategy::tryFrom($definition->multisite) ?? MultisiteStrategy::SharedWithSiteFilter);

        $fields = [];
        $mappings = [];
        $queryBy = [];
        $weights = [];
        $descriptions = [];

        if ($definition->isUnion()) {
            // A union compiles each member's field set, resolves collisions across
            // members (merge same handle+type, namespace on a type mismatch), and
            // attaches per-member queries and mappings. The document path selects
            // the matching member's mapping and stamps the discriminator.
            [$fields, $queryBy, $weights, $descriptions] = $this->_compileUnion($definition, $collection);
        } else {
            $query = $this->_elementQuery($definition);

            if ($query !== null) {
                $collection->elementQuery($query);
            }

            // Every element placed in the mapping layout is an indexed field; the
            // element carries its own resolved type and per-field settings.
            foreach ($this->_layoutItems($definition->getFieldLayout()) as $item) {
                $fields[] = $this->_field($item['key'], $item['type'], $item['settings']);
                $mappings[] = $this->_mapping($item['key'], $item['type'], $item['kind']);

                if (in_array($item['type'], ['string', 'string[]'], true) && (int)($item['settings']['weight'] ?? 0) > 0) {
                    $queryBy[] = $item['key'];
                    $weights[] = (int)$item['settings']['weight'];
                }

                if (!empty($item['settings']['description'])) {
                    $descriptions[$item['key']] = (string)$item['settings']['description'];
                }
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

        // Conversational ask: opt the collection into the ask endpoint with the
        // chosen conversation model instance (its handle is the server model id).
        if (!empty($definition->conversation['enabled'])) {
            $modelHandle = trim((string)($definition->conversation['modelHandle'] ?? ''));

            if ($modelHandle !== '') {
                $collection->ask($modelHandle);
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
     * Compiles a union collection's members onto the collection (per-member query
     * and mapping) and returns the resolved union schema field set plus the shared
     * query-by, weights, and descriptions. Field collisions across members merge
     * when the document key and type match, and namespace (memberHandle_key) on a
     * type mismatch; the explicit output handle already comes from each mapping
     * element's tsHandle. Adds the reserved discriminator fields.
     *
     * @param CollectionDefinition $definition
     * @param Collection $collection
     * @return array{0: Field[], 1: array<int, string>, 2: array<int, int>, 3: array<string, string>}
     * @author CraftPulse
     */
    private function _compileUnion(CollectionDefinition $definition, Collection $collection): array
    {
        $fields = [];
        $queryBy = [];
        $weights = [];
        $descriptions = [];
        $unionTypes = [];

        foreach ($definition->getMemberModels() as $member) {
            $memberMappings = [];

            foreach ($this->_layoutItems($member->getFieldLayout()) as $item) {
                $key = $item['key'];
                $type = $item['type'];

                // Type mismatch on an already-claimed key: namespace this member's
                // field. Same key and type merges into one shared field.
                if (isset($unionTypes[$key]) && $unionTypes[$key] !== $type) {
                    $key = $member->handle . '_' . $item['key'];
                }

                if (!isset($unionTypes[$key])) {
                    $unionTypes[$key] = $type;
                    $fields[] = $this->_field($key, $type, $item['settings']);

                    if (in_array($type, ['string', 'string[]'], true) && (int)($item['settings']['weight'] ?? 0) > 0) {
                        $queryBy[] = $key;
                        $weights[] = (int)$item['settings']['weight'];
                    }

                    if (!empty($item['settings']['description'])) {
                        $descriptions[$key] = (string)$item['settings']['description'];
                    }
                }

                $memberMappings[] = $this->_mapping($key, $type, $item['kind']);
            }

            $query = $this->_memberQuery($member->elementType, $member->source, $member->sourceType)
                ?? static fn($q) => $q;
            $collection->addMember($member->elementType, $query, $member->handle, $memberMappings);
        }

        // The reserved discriminator fields a union filters and facets by.
        $fields[] = Field::make(Documents::FIELD_ELEMENT_TYPE, 'string')->facet()->optional();
        $fields[] = Field::make(Documents::FIELD_ELEMENT_CLASS, 'string')->facet()->optional();

        return [$fields, $queryBy, $weights, $descriptions];
    }

    /**
     * The indexed mapping items of a field layout: the document key, resolved
     * Typesense type, per-field settings, and mapping kind for each placed
     * mapping element.
     *
     * @param \craft\models\FieldLayout $layout
     * @return array<int, array{key: string, type: string, settings: array<string, mixed>, kind: string}>
     * @author CraftPulse
     */
    private function _layoutItems(\craft\models\FieldLayout $layout): array
    {
        $items = [];

        foreach ($layout->getTabs() as $tab) {
            foreach ($tab->getElements() as $element) {
                if (!$element instanceof MappingElementInterface) {
                    continue;
                }

                $key = $element->tsHandle();

                if ($key === '') {
                    continue;
                }

                $items[] = [
                    'key' => $key,
                    'type' => $element->tsResolvedType(),
                    'settings' => $element->tsFieldSettings(),
                    'kind' => $element->tsKind(),
                ];
            }
        }

        return $items;
    }

    /**
     * Builds the element query callable that scopes a definition to its source.
     *
     * @param CollectionDefinition $definition
     * @return callable|null
     * @author CraftPulse
     */
    private function _elementQuery(CollectionDefinition $definition): ?callable
    {
        return $this->_memberQuery($definition->elementType, $definition->source, $definition->sourceType);
    }

    /**
     * Builds the element query callable that scopes an element type and source to
     * its members. Shared by regular collections and union members.
     *
     * @param class-string $type
     * @param string|null $source
     * @param string $sourceType
     * @return callable|null
     * @author CraftPulse
     */
    private function _memberQuery(string $type, ?string $source, string $sourceType): ?callable
    {
        if ($source === null || $source === '') {
            return null;
        }

        // An entry-type source indexes a single entry type directly, including a
        // sectionless (nested Matrix / CKEditor) entry type. A plain type() query
        // returns nested entries without any owner or field scoping (verified
        // against the playground: ->type(nestedHandle) returns the nested rows).
        if (is_a($type, Entry::class, true) && $sourceType === CollectionDefinition::SOURCE_TYPE_ENTRY_TYPE) {
            return static fn($query) => $query->type($source);
        }

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
     * Builds a schema field from a mapping element's settings.
     *
     * @param string $key
     * @param string $type
     * @param array<string, mixed> $settings
     * @return Field
     * @author CraftPulse
     */
    private function _field(string $key, string $type, array $settings): Field
    {
        // Craft content is frequently empty, so mapped fields are optional; the
        // generated path omits null values and Typesense accepts the absence.
        $field = Field::make($key, $type)->optional();

        if (!empty($settings['facet'])) {
            $field->facet();
        }

        if (!empty($settings['sortable'])) {
            $field->sort();
        }

        if (!empty($settings['infix'])) {
            $field->infix();
        }

        if (!empty($settings['stem'])) {
            $field->stem();
        }

        // A stemming dictionary implies stemming (Field::stemDictionary sets stem).
        if (!empty($settings['stemDictionary'])) {
            $field->stemDictionary((string)$settings['stemDictionary']);
        }

        $locale = Locale::toTypesense((string)($settings['locale'] ?? ''));

        if ($locale !== null) {
            $field->locale($locale);
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
}
