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
use craft\base\ElementInterface;
use craft\elements\db\ElementQueryInterface;
use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\events\IndexDocumentEvent;
use craftpulse\typesense\models\DocumentBuildResult;
use craftpulse\typesense\models\FieldMapping;
use craftpulse\typesense\Typesense;

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
     * @var array<int, string> The element statuses treated as indexable by default.
     */
    public const DEFAULT_ACTIVE_STATUSES = ['live', 'enabled'];

    // Public Methods
    // =========================================================================

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
        } else {
            $raw = $this->buildFromMapping($collection, $element);
        }

        $documents = [];

        foreach ($this->_normalizeDocuments($raw, $element) as $document) {
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
     * @return array<string, mixed>
     * @author CraftPulse
     */
    public function buildFromMapping(Collection $collection, ElementInterface $element): array
    {
        $document = [];

        foreach ($collection->getMapping() as $mapping) {
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

        return $document;
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

            if (!isset($document['id'])) {
                $document['id'] = (string)$element->id;
            } else {
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
