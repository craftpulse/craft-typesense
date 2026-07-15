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
use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\Tag;
use craft\elements\User;
use craft\models\FieldLayout;
use craftpulse\typesense\models\CollectionDefinition;
use craftpulse\typesense\Typesense;

/**
 * Builds the authoritative field descriptors the mapping UI renders as cards.
 *
 * For a CP-managed collection's element source this enumerates the field-layout
 * fields, the file-derived pseudo fields (assets), the Commerce variant fields
 * (products, when installed), and the registered computed fields. Each
 * descriptor carries the server-derived Typesense type (from the Schema service)
 * and the set of controls the mapping HUD is allowed to offer for that type, so
 * the UI can only propose what the server would accept. The server, not the
 * browser, decides the derivation and the allowed controls.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class MappingSources extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Returns the ordered field descriptors for a collection definition's
     * element source: layout fields, then any file-derived pseudo fields,
     * then registered computed fields.
     *
     * @param CollectionDefinition $definition
     * @return array<int, array<string, mixed>>
     * @author CraftPulse
     */
    public function descriptorsFor(CollectionDefinition $definition): array
    {
        $isAsset = is_a($definition->elementType, Asset::class, true);
        $descriptors = [];

        foreach ($this->_layoutFields($definition) as $field) {
            $descriptors[] = $this->_fieldDescriptor($field, 'field', $isAsset);
        }

        if ($isAsset) {
            foreach ($this->_assetPseudoFields() as $pseudo) {
                $descriptors[] = $pseudo + ['controls' => $this->_controlsFor($pseudo['derivedType'], true)];
            }
        }

        foreach ($this->_variantFields($definition) as $field) {
            $descriptor = $this->_fieldDescriptor($field, 'variant', false);
            $descriptor['label'] = Craft::t('typesense', 'Variant') . ': ' . $descriptor['label'];
            $descriptors[] = $descriptor;
        }

        foreach (Typesense::$plugin->getSchema()->getComputedFields() as $computed) {
            $descriptors[] = [
                'uid' => 'computed:' . $computed->name,
                'label' => $computed->name,
                'handle' => $computed->name,
                'kind' => 'computed',
                'derivedType' => $computed->type,
                'controls' => $this->_controlsFor($computed->type, false),
            ];
        }

        return $descriptors;
    }

    // Private Methods
    // =========================================================================

    /**
     * The file-derived pseudo fields every asset carries, with their fixed
     * derived types.
     *
     * @return array<int, array<string, mixed>>
     * @author CraftPulse
     */
    private function _assetPseudoFields(): array
    {
        return [
            ['uid' => 'pseudo:filename', 'label' => Craft::t('typesense', 'Filename'), 'handle' => 'filename', 'kind' => 'pseudo', 'derivedType' => 'string'],
            ['uid' => 'pseudo:kind', 'label' => Craft::t('typesense', 'Kind'), 'handle' => 'kind', 'kind' => 'pseudo', 'derivedType' => 'string'],
            ['uid' => 'pseudo:size', 'label' => Craft::t('typesense', 'Size'), 'handle' => 'size', 'kind' => 'pseudo', 'derivedType' => 'int64'],
            ['uid' => 'pseudo:width', 'label' => Craft::t('typesense', 'Width'), 'handle' => 'width', 'kind' => 'pseudo', 'derivedType' => 'int32'],
            ['uid' => 'pseudo:height', 'label' => Craft::t('typesense', 'Height'), 'handle' => 'height', 'kind' => 'pseudo', 'derivedType' => 'int32'],
        ];
    }

    /**
     * Builds the allowed control set for a derived Typesense type. This is the
     * server's contract: the HUD renders only these controls.
     *
     * @param string $derivedType
     * @param bool $isAsset
     * @return array<int, array<string, mixed>>
     * @author CraftPulse
     */
    private function _controlsFor(string $derivedType, bool $isAsset): array
    {
        $isText = in_array($derivedType, ['string', 'string[]'], true);
        $isNumeric = in_array($derivedType, ['int32', 'int64', 'float'], true);
        $isFacetable = in_array($derivedType, ['string', 'string[]', 'int32', 'int64', 'bool'], true);
        $isObject = in_array($derivedType, ['object', 'object[]'], true);

        $controls = [
            ['key' => 'indexable', 'type' => 'checkbox', 'label' => Craft::t('typesense', 'Indexed')],
        ];

        if ($isFacetable) {
            $controls[] = ['key' => 'facet', 'type' => 'checkbox', 'label' => Craft::t('typesense', 'Facet')];
        }

        if ($isText || $isNumeric || $derivedType === 'bool') {
            $controls[] = ['key' => 'sortable', 'type' => 'checkbox', 'label' => Craft::t('typesense', 'Sortable')];
        }

        if ($isText) {
            $controls[] = ['key' => 'weight', 'type' => 'number', 'label' => Craft::t('typesense', 'Search weight')];
            $controls[] = ['key' => 'infix', 'type' => 'checkbox', 'label' => Craft::t('typesense', 'Infix search')];
            $controls[] = ['key' => 'stem', 'type' => 'checkbox', 'label' => Craft::t('typesense', 'Stemming')];
            $controls[] = ['key' => 'locale', 'type' => 'text', 'label' => Craft::t('typesense', 'Locale')];
        }

        if ($isObject) {
            $controls[] = ['key' => 'embed', 'type' => 'checkbox', 'label' => Craft::t('typesense', 'Index nested fields')];
        }

        $controls[] = ['key' => 'type', 'type' => 'select', 'label' => Craft::t('typesense', 'Type override'), 'options' => $this->_typeOverrideOptions($derivedType)];

        if ($isAsset) {
            // Auto image embedding is experimental; the pipeline lands later.
            $controls[] = ['key' => 'imageEmbed', 'type' => 'checkbox', 'label' => Craft::t('typesense', 'Embed image (experimental)')];
        }

        $controls[] = ['key' => 'description', 'type' => 'text', 'label' => Craft::t('typesense', 'Description (for natural-language search)')];

        return $controls;
    }

    /**
     * Builds a descriptor for a real field, deriving its type from the Schema
     * service.
     *
     * @param FieldInterface $field
     * @param string $kind
     * @param bool $isAsset
     * @return array<string, mixed>
     * @author CraftPulse
     */
    private function _fieldDescriptor(FieldInterface $field, string $kind, bool $isAsset): array
    {
        $derivedType = Typesense::$plugin->getSchema()->typesenseTypeForField($field);

        return [
            'uid' => (string)$field->uid,
            'label' => $field->name !== '' ? $field->name : $field->handle,
            'handle' => (string)$field->handle,
            'kind' => $kind,
            'derivedType' => $derivedType,
            'controls' => $this->_controlsFor($derivedType, $isAsset),
        ];
    }

    /**
     * The custom fields of a definition's element source, de-duplicated by UID.
     *
     * @param CollectionDefinition $definition
     * @return array<int, FieldInterface>
     * @author CraftPulse
     */
    private function _layoutFields(CollectionDefinition $definition): array
    {
        $layouts = [];
        $source = $definition->source;
        $type = $definition->elementType;

        if (is_a($type, Entry::class, true) && $source !== null) {
            $section = Craft::$app->getEntries()->getSectionByHandle($source);

            foreach ($section?->getEntryTypes() ?? [] as $entryType) {
                $layouts[] = $entryType->getFieldLayout();
            }
        } elseif (is_a($type, Category::class, true) && $source !== null) {
            $layouts[] = Craft::$app->getCategories()->getGroupByHandle($source)?->getFieldLayout();
        } elseif (is_a($type, Asset::class, true) && $source !== null) {
            $layouts[] = Craft::$app->getVolumes()->getVolumeByHandle($source)?->getFieldLayout();
        } elseif (is_a($type, Tag::class, true) && $source !== null) {
            $layouts[] = Craft::$app->getTags()->getTagGroupByHandle($source)?->getFieldLayout();
        } elseif (is_a($type, GlobalSet::class, true) && $source !== null) {
            $layouts[] = Craft::$app->getGlobals()->getSetByHandle($source)?->getFieldLayout();
        } elseif (is_a($type, User::class, true)) {
            $layouts[] = Craft::$app->getFields()->getLayoutByType(User::class);
        }

        return $this->_uniqueCustomFields($layouts);
    }

    /**
     * De-duplicates the custom fields across one or more field layouts.
     *
     * @param array<int, FieldLayout|null> $layouts
     * @return array<int, FieldInterface>
     * @author CraftPulse
     */
    private function _uniqueCustomFields(array $layouts): array
    {
        $fields = [];

        foreach ($layouts as $layout) {
            if ($layout === null) {
                continue;
            }

            foreach ($layout->getCustomFields() as $field) {
                $fields[$field->uid] = $field;
            }
        }

        return array_values($fields);
    }

    /**
     * The bounded type-override options for a derived type: the derived type
     * plus the safe alternates the server would accept.
     *
     * @param string $derivedType
     * @return array<int, string>
     * @author CraftPulse
     */
    private function _typeOverrideOptions(string $derivedType): array
    {
        $map = [
            'string' => ['string', 'string[]'],
            'string[]' => ['string[]', 'string'],
            'int32' => ['int32', 'int64', 'float', 'string'],
            'int64' => ['int64', 'int32', 'float', 'string'],
            'float' => ['float', 'string'],
            'bool' => ['bool', 'string'],
            'object' => ['object', 'object[]'],
            'object[]' => ['object[]', 'object'],
            'geopoint' => ['geopoint'],
        ];

        return $map[$derivedType] ?? [$derivedType];
    }

    /**
     * The Commerce variant fields for a product-type source, when Commerce is
     * installed. Surfaced on the product card.
     *
     * @param CollectionDefinition $definition
     * @return array<int, FieldInterface>
     * @author CraftPulse
     */
    private function _variantFields(CollectionDefinition $definition): array
    {
        // Commerce is an optional dependency, so its symbols are referenced by
        // string and the instance is treated as mixed.
        $productClass = 'craft\\commerce\\elements\\Product';

        if ($definition->source === null || !is_a($definition->elementType, $productClass, true)) {
            return [];
        }

        $commerce = Craft::$app->getPlugins()->getPlugin('commerce');

        if ($commerce === null) {
            return [];
        }

        /** @var mixed $commerce */
        $productType = $commerce->getProductTypes()->getProductTypeByHandle($definition->source);
        $layout = $productType?->getVariantFieldLayout();

        return $this->_uniqueCustomFields([$layout]);
    }
}
