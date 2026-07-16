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

use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\db\ElementQueryInterface;
use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\enums\MultisiteStrategy;
use craftpulse\typesense\events\IndexDocumentEvent;
use craftpulse\typesense\models\DocumentBuildResult;
use craftpulse\typesense\models\FieldMapping;
use craftpulse\typesense\Typesense;
use Throwable;

/**
 * Document transformer.
 *
 * Builds the Typesense document(s) for an element in a collection and site via
 * one of two paths: the closure path (the collection's transform callable) or
 * the generated path (from field-mapping data plus attached computed fields).
 * Collects relation dependencies through a registerDependency callback, injects
 * the reserved elementId/siteId fields used for deletion and site filtering,
 * signals deletion by returning no documents when the element is not indexable,
 * and supports multiple documents per element.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class Documents extends Component
{
    // Constants
    // =========================================================================

    /**
     * @event IndexDocumentEvent Fired before a document is indexed (mutable).
     */
    public const EVENT_BEFORE_INDEX_DOCUMENT = 'beforeIndexDocument';

    /**
     * @event IndexDocumentEvent Fired after a document is indexed.
     */
    public const EVENT_AFTER_INDEX_DOCUMENT = 'afterIndexDocument';

    /**
     * @var string The reserved document key carrying the source element ID.
     */
    public const FIELD_ELEMENT_ID = 'elementId';

    /**
     * @var string The reserved document key carrying the site ID.
     */
    public const FIELD_SITE_ID = 'siteId';

    /**
     * @var string The reserved document key carrying a union member's handle (the
     * discriminator a union collection filters and facets by). Union only.
     */
    public const FIELD_ELEMENT_TYPE = '_elementType';

    /**
     * @var string The reserved document key carrying the source element's class
     * short name, an informational sibling to the member handle. Union only.
     */
    public const FIELD_ELEMENT_CLASS = '_elementClass';

    /**
     * @var array<int, string> The element statuses treated as indexable by default.
     */
    public const DEFAULT_ACTIVE_STATUSES = ['live', 'enabled'];

    // Public Methods
    // =========================================================================

    /**
     * The default Typesense document id for an element in a collection, when a
     * transform has not set one. A sharedWithSiteFilter collection whose scope
     * spans more than one site uses a composite `{elementId}-{siteId}` id so the
     * per-site documents do not collide; every other case (a single-site scope,
     * or collectionPerSite where each site has its own collection) keeps the
     * bare element id, since no collision is possible and existing document
     * identity must not churn.
     *
     * @param Collection $collection
     * @param int $elementId
     * @param int $siteId
     * @param array<int, int> $collectionSiteIds
     * @return string
     * @author CraftPulse
     */
    public function defaultDocumentId(Collection $collection, int $elementId, int $siteId, array $collectionSiteIds): string
    {
        if (
            $collection->getMultisiteStrategy() === MultisiteStrategy::SharedWithSiteFilter
            && count($collectionSiteIds) > 1
        ) {
            return $elementId . '-' . $siteId;
        }

        return (string)$elementId;
    }

    /**
     * Builds the documents for an element in a collection and site.
     *
     * @param Collection $collection
     * @param ElementInterface $element
     * @param int $siteId
     * @return DocumentBuildResult
     * @author CraftPulse
     */
    public function build(Collection $collection, ElementInterface $element, int $siteId): DocumentBuildResult
    {
        if (!$this->isActive($collection, $element)) {
            return new DocumentBuildResult();
        }

        $dependencies = [];
        $registerDependency = static function(mixed $dependency) use (&$dependencies): void {
            $id = is_object($dependency) && isset($dependency->id) ? (int)$dependency->id : (int)$dependency;

            if ($id > 0) {
                $dependencies[] = $id;
            }
        };

        $transform = $collection->getTransform();

        if ($transform !== null) {
            $raw = $transform($element, $registerDependency);
        } elseif ($collection->isUnion()) {
            // A union document is built from the matching member's mapping only
            // (another member's namespaced key would otherwise resolve from this
            // element), then stamped with the member handle and element class.
            $member = $this->_matchUnionMember($collection, $element, $siteId);

            if ($member === null) {
                return new DocumentBuildResult();
            }

            $raw = $this->buildFromMapping($collection, $element, $member['mapping']);
            $raw[self::FIELD_ELEMENT_TYPE] = $member['handle'];
            $raw[self::FIELD_ELEMENT_CLASS] = (new \ReflectionClass($element))->getShortName();
        } else {
            $raw = $this->buildFromMapping($collection, $element);
        }

        $documents = [];
        $collectionSiteIds = $this->_syncSiteIds($collection);

        foreach ($this->_normalizeDocuments($raw, $element) as $document) {
            // A transform may set its own document id; otherwise derive one that
            // stays collision-free across the collection's site scope.
            if (!isset($document['id']) || (string)$document['id'] === '') {
                $document['id'] = $this->defaultDocumentId($collection, (int)$element->id, $siteId, $collectionSiteIds);
            }

            $document = $this->_injectReservedFields($document, $element, $siteId);
            $documents[] = $this->_beforeIndexDocument($collection, $element, $siteId, $document);
        }

        return new DocumentBuildResult($documents, array_values(array_unique($dependencies)));
    }

    /**
     * Builds a single document from the collection's field mappings and attached
     * computed fields (the generated path).
     *
     * @param Collection $collection
     * @param ElementInterface $element
     * @param FieldMapping[]|null $mappingOverride the mapping set to use (a union
     * member's mappings); null uses the collection's own mappings.
     * @return array<string, mixed>
     * @author CraftPulse
     */
    public function buildFromMapping(Collection $collection, ElementInterface $element, ?array $mappingOverride = null): array
    {
        $document = [];

        foreach ($mappingOverride ?? $collection->getMapping() as $mapping) {
            if (!$mapping->indexed) {
                continue;
            }

            $value = $this->_resolveMappingValue($mapping, $element);

            // Omit null values: with optional schema fields, an absent value is
            // valid, whereas sending null trips Typesense's type check.
            if ($value !== null) {
                $document[$mapping->documentKey] = $value;
            }
        }

        $schema = Typesense::$plugin->getSchema();

        foreach ($collection->getComputedFieldNames() as $name) {
            $computed = $schema->getComputedField($name);

            if ($computed !== null) {
                $document[$computed->name] = $computed->resolve($element);
            }
        }

        // Bake the additive boost score at index time (the deterministic
        // alternative to the first-match-wins _eval bug).
        $boostRules = $collection->getBoostRules();

        if ($boostRules !== []) {
            $document[$collection->getBoostField()] = Typesense::$plugin->getRelevance()->boostScore($element, $boostRules);
        }

        // CLIP image embedding: fill the image source key with the asset's
        // base64-encoded file, read as a stream in bounded memory.
        $imageKey = $collection->getImageEmbedField();

        if ($imageKey !== null && $element instanceof Asset) {
            $base64 = $this->_assetBase64($element);

            if ($base64 !== null) {
                $document[$imageKey] = $base64;
            }
        }

        return $document;
    }

    /**
     * The union member an element belongs to: the sole member of the element's
     * type, or (when members share a type) the first whose query matches the
     * element. Returns null when no member matches.
     *
     * @param Collection $collection
     * @param ElementInterface $element
     * @param int $siteId
     * @return array{handle: string, elementType: class-string, query: callable, mapping: FieldMapping[]}|null
     * @author CraftPulse
     */
    private function _matchUnionMember(Collection $collection, ElementInterface $element, int $siteId): ?array
    {
        $candidates = array_values(array_filter(
            $collection->getMembers(),
            static fn(array $member): bool => $element instanceof $member['elementType'],
        ));

        if ($candidates === []) {
            return null;
        }

        if (count($candidates) === 1) {
            return $candidates[0];
        }

        // Members sharing an element type are disambiguated by their query.
        foreach ($candidates as $member) {
            $type = $member['elementType'];
            $query = ($member['query'])($type::find()->siteId($siteId)->status(null));

            if ($query instanceof ElementQueryInterface && $query->id($element->id)->exists()) {
                return $member;
            }
        }

        return null;
    }

    /**
     * Base64-encodes an asset's file, streamed in chunks (a multiple of 3 bytes,
     * so the encoded chunks concatenate correctly) to keep memory bounded on
     * large images. Fail-soft: an unreadable asset yields null.
     *
     * @param Asset $asset
     * @return string|null
     * @author CraftPulse
     */
    private function _assetBase64(Asset $asset): ?string
    {
        if (!in_array($asset->kind, [Asset::KIND_IMAGE], true)) {
            return null;
        }

        try {
            $stream = $asset->getStream();
        } catch (Throwable $e) {
            Craft::warning("Could not open asset {$asset->id} for embedding: {$e->getMessage()}", 'typesense');

            return null;
        }

        $encoded = '';
        $buffer = '';

        // 3 KB chunks (a multiple of 3) so each base64_encode call ends on a full
        // 3-byte group and the pieces concatenate without padding artefacts.
        while (!feof($stream)) {
            $buffer .= (string)fread($stream, 3072);
            $whole = intdiv(strlen($buffer), 3) * 3;

            if ($whole > 0) {
                $encoded .= base64_encode(substr($buffer, 0, $whole));
                $buffer = substr($buffer, $whole);
            }
        }

        fclose($stream);

        if ($buffer !== '') {
            $encoded .= base64_encode($buffer);
        }

        return $encoded;
    }

    /**
     * Whether an element is indexable for a collection (in an active status).
     *
     * @param Collection $collection
     * @param ElementInterface $element
     * @return bool
     * @author CraftPulse
     */
    public function isActive(Collection $collection, ElementInterface $element): bool
    {
        $statuses = $collection->getActiveStatuses() ?? self::DEFAULT_ACTIVE_STATUSES;

        return in_array((string)$element->getStatus(), $statuses, true);
    }

    /**
     * Fires the after-index event for a document.
     *
     * @param Collection $collection
     * @param ElementInterface $element
     * @param int $siteId
     * @param array<string, mixed> $document
     * @return void
     * @author CraftPulse
     */
    public function afterIndexDocument(Collection $collection, ElementInterface $element, int $siteId, array $document): void
    {
        if (!$this->hasEventHandlers(self::EVENT_AFTER_INDEX_DOCUMENT)) {
            return;
        }

        $this->trigger(self::EVENT_AFTER_INDEX_DOCUMENT, new IndexDocumentEvent([
            'collection' => $collection,
            'element' => $element,
            'siteId' => $siteId,
            'document' => $document,
        ]));
    }

    // Private Methods
    // =========================================================================

    /**
     * The site ids a collection's documents span, used to decide the default
     * document id shape. A sharedWithSiteFilter collection spans every site;
     * collectionPerSite keeps a collection per site, so its ids never collide
     * and the scope is irrelevant.
     *
     * @param Collection $collection
     * @return array<int, int>
     * @author CraftPulse
     */
    private function _syncSiteIds(Collection $collection): array
    {
        if ($collection->getMultisiteStrategy() === MultisiteStrategy::SharedWithSiteFilter) {
            return Craft::$app->getSites()->getAllSiteIds();
        }

        return [];
    }

    /**
     * Normalizes a transform result to a list of documents, each with an id.
     *
     * @param mixed $raw
     * @param ElementInterface $element
     * @return array<int, array<string, mixed>>
     * @author CraftPulse
     */
    private function _normalizeDocuments(mixed $raw, ElementInterface $element): array
    {
        if (!is_array($raw) || $raw === []) {
            return [];
        }

        $documents = array_is_list($raw) ? $raw : [$raw];
        $normalized = [];

        foreach ($documents as $document) {
            if (!is_array($document)) {
                continue;
            }

            // The default id is derived in build(), which knows the collection's
            // multisite scope; here we only normalise an explicitly-set id.
            if (isset($document['id'])) {
                $document['id'] = (string)$document['id'];
            }

            $normalized[] = $document;
        }

        return $normalized;
    }

    /**
     * Injects the reserved elementId and siteId fields.
     *
     * @param array<string, mixed> $document
     * @param ElementInterface $element
     * @param int $siteId
     * @return array<string, mixed>
     * @author CraftPulse
     */
    private function _injectReservedFields(array $document, ElementInterface $element, int $siteId): array
    {
        $document[self::FIELD_ELEMENT_ID] = (int)$element->id;
        $document[self::FIELD_SITE_ID] = $siteId;

        return $document;
    }

    /**
     * Fires the before-index event and returns the (possibly mutated) document.
     *
     * @param Collection $collection
     * @param ElementInterface $element
     * @param int $siteId
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     * @author CraftPulse
     */
    private function _beforeIndexDocument(Collection $collection, ElementInterface $element, int $siteId, array $document): array
    {
        if (!$this->hasEventHandlers(self::EVENT_BEFORE_INDEX_DOCUMENT)) {
            return $document;
        }

        $event = new IndexDocumentEvent([
            'collection' => $collection,
            'element' => $element,
            'siteId' => $siteId,
            'document' => $document,
        ]);
        $this->trigger(self::EVENT_BEFORE_INDEX_DOCUMENT, $event);

        return $event->document;
    }

    /**
     * Resolves a single field mapping's value from the element.
     *
     * @param FieldMapping $mapping
     * @param ElementInterface $element
     * @return mixed
     * @author CraftPulse
     */
    private function _resolveMappingValue(FieldMapping $mapping, ElementInterface $element): mixed
    {
        if ($mapping->computedFieldName !== null) {
            $computed = Typesense::$plugin->getSchema()->getComputedField($mapping->computedFieldName);

            return $computed?->resolve($element);
        }

        if ($mapping->attribute !== null) {
            return $this->_coerce($element->{$mapping->attribute} ?? null, $mapping->type);
        }

        if ($mapping->fieldHandle !== null) {
            return $this->_coerce($element->getFieldValue($mapping->fieldHandle), $mapping->type);
        }

        return null;
    }

    /**
     * Coerces a raw Craft value to a Typesense-friendly value for the given type.
     *
     * @param mixed $value
     * @param string $type
     * @return mixed
     * @author CraftPulse
     */
    private function _coerce(mixed $value, string $type): mixed
    {
        if ($type === 'geopoint') {
            return Typesense::$plugin->getSchema()->normalizeGeopoint($value);
        }

        if (str_ends_with($type, '[]')) {
            return $this->_toIdArray($value);
        }

        if ($value === null) {
            return null;
        }

        return match ($type) {
            'int32', 'int64' => (int)$value,
            'float' => (float)$value,
            'bool' => (bool)$value,
            default => is_scalar($value) ? (string)$value : $value,
        };
    }

    /**
     * Normalizes a relation or array value to a list of string IDs or values.
     *
     * @param mixed $value
     * @return array<int, mixed>
     * @author CraftPulse
     */
    private function _toIdArray(mixed $value): array
    {
        if ($value instanceof ElementQueryInterface) {
            return array_map(static fn(int $id): string => (string)$id, $value->ids());
        }

        if (is_iterable($value)) {
            $values = [];

            foreach ($value as $item) {
                $values[] = is_object($item) && isset($item->id) ? (string)$item->id : $item;
            }

            return $values;
        }

        return $value === null ? [] : [$value];
    }
}
