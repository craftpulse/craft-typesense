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
use craft\models\FieldLayoutTab;
use craftpulse\typesense\fieldlayoutelements\MappingField;
use craftpulse\typesense\fieldlayoutelements\NativeMappingField;
use craftpulse\typesense\helpers\Locale;
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

    /**
     * The file-derived pseudo fields every asset carries, with their fixed
     * derived types.
     *
     * @return array<int, array<string, mixed>>
     * @author CraftPulse
     */
    public function assetPseudoFields(): array
    {
        return $this->_assetPseudoFields();
    }

    /**
     * Builds the allowed control set for a derived Typesense type. This is the
     * server's contract: the field-layout slideout renders only these controls.
     *
     * @param string $derivedType
     * @param bool $isAsset
     * @return array<int, array<string, mixed>>
     * @author CraftPulse
     */
    public function controlsFor(string $derivedType, bool $isAsset): array
    {
        return $this->_controlsFor($derivedType, $isAsset);
    }

    /**
     * The custom fields of a definition's element source, de-duplicated by UID.
     *
     * @param CollectionDefinition $definition
     * @return array<int, FieldInterface>
     * @author CraftPulse
     */
    public function customFieldsFor(CollectionDefinition $definition): array
    {
        return $this->_layoutFields($definition);
    }

    /**
     * Builds a mapping field layout from a definition's legacy `mappings` array
     * (keyed by field UID): every indexed descriptor becomes a layout element
     * carrying the legacy settings. Used by the migration to the real-layout
     * architecture.
     *
     * @param CollectionDefinition $definition
     * @return FieldLayout
     * @author CraftPulse
     */
    public function layoutFromLegacyMappings(CollectionDefinition $definition): FieldLayout
    {
        $elements = [];

        foreach ($this->descriptorsFor($definition) as $descriptor) {
            $legacy = $definition->mappings[$descriptor['uid']] ?? null;

            if (!is_array($legacy) || empty($legacy['indexable'])) {
                continue;
            }

            $element = $this->_legacyElement($descriptor, $legacy);

            if ($element !== null) {
                $elements[] = $element;
            }
        }

        $layout = $definition->getFieldLayout();
        $tab = new FieldLayoutTab(['layout' => $layout, 'name' => Craft::t('typesense', 'Mapping')]);
        $tab->setElements($elements);
        $layout->setTabs([$tab]);

        return $layout;
    }

    /**
     * The Commerce variant fields for a product-type source, when installed.
     *
     * @param CollectionDefinition $definition
     * @return array<int, FieldInterface>
     * @author CraftPulse
     */
    public function variantFieldsFor(CollectionDefinition $definition): array
    {
        return $this->_variantFields($definition);
    }

    /**
     * The bounded type-override options for a derived type.
     *
     * @param string $derivedType
     * @return array<int, string>
     * @author CraftPulse
     */
    public function typeOverrideOptions(string $derivedType): array
    {
        return $this->_typeOverrideOptions($derivedType);
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

        $info = static fn(string $text): string => '<span class="info">' . $text . '</span>';

        $controls = [
            ['key' => 'indexable', 'type' => 'checkbox', 'label' => Craft::t('typesense', 'Indexed')],
        ];

        if ($isFacetable) {
            $controls[] = [
                'key' => 'facet',
                'type' => 'checkbox',
                'label' => Craft::t('typesense', 'Facet'),
                'instructions' => Craft::t('typesense', 'Allow filtering and counting by this field.') . $info(Craft::t('typesense', 'Makes the field available to <code>facet_by</code> for facet counts and filter UIs. Facet fields carry extra memory and index cost.')),
            ];
        }

        if ($isText || $isNumeric || $derivedType === 'bool') {
            $controls[] = [
                'key' => 'sortable',
                'type' => 'checkbox',
                'label' => Craft::t('typesense', 'Sortable'),
                'instructions' => Craft::t('typesense', 'Allow ordering results by this field.') . $info(Craft::t('typesense', 'Makes the field usable in <code>sort_by</code>. Numeric fields are always sortable; marking a string field sortable builds an extra sort index.')),
            ];
        }

        if ($isText) {
            $controls[] = [
                'key' => 'weight',
                'type' => 'number',
                'label' => Craft::t('typesense', 'Search weight'),
                'instructions' => Craft::t('typesense', 'How strongly a match in this field counts toward relevance.') . $info(Craft::t('typesense', 'Higher weights rank matches in this field above matches in lower-weighted fields. Compiled into the collection’s <code>query_by_weights</code>.')),
            ];
            $controls[] = [
                'key' => 'infix',
                'type' => 'checkbox',
                'label' => Craft::t('typesense', 'Infix search'),
                'instructions' => Craft::t('typesense', 'Match substrings in the middle of words.') . $info(Craft::t('typesense', 'Enables mid-string matching (for example matching "apple" in "pineapple"). Typesense builds an extra in-memory infix index for the field, so it raises memory use; enable it only where you need it.')),
            ];
            $controls[] = [
                'key' => 'stem',
                'type' => 'checkbox',
                'label' => Craft::t('typesense', 'Stemming'),
                'instructions' => Craft::t('typesense', 'Match different forms of the same word.') . $info(Craft::t('typesense', 'Applies the language’s stemmer so "running" also matches "run". Stemming is language-dependent, so set the Locale below to the field’s language.')),
            ];
            $controls[] = [
                'key' => 'locale',
                'type' => 'language',
                'label' => Craft::t('typesense', 'Locale'),
                'options' => $this->_localeOptions(),
                'instructions' => Craft::t('typesense', 'The language used to tokenize and stem this field.') . $info(Craft::t('typesense', 'Typesense scopes tokenization and stemming to an ISO language code (for example en, nl, ja) via the field’s <code>locale</code>. The region is dropped on save, so en-GB is stored as en.')),
            ];
        }

        if ($isObject) {
            $controls[] = [
                'key' => 'embed',
                'type' => 'checkbox',
                'label' => Craft::t('typesense', 'Index nested fields'),
                'instructions' => Craft::t('typesense', 'Index the fields inside this object.') . $info(Craft::t('typesense', 'Enables <code>enable_nested_fields</code> so each key of the object becomes independently searchable and filterable.')),
            ];
        }

        $controls[] = [
            'key' => 'type',
            'type' => 'select',
            'label' => Craft::t('typesense', 'Type override'),
            'options' => $this->_typeOverrideOptions($derivedType),
            'instructions' => Craft::t('typesense', 'Store this field as a different Typesense type.') . $info(Craft::t('typesense', 'Overrides the server-derived type with a compatible one (for example indexing a number as a string). Leave as the derived type unless you have a reason to change it.')),
        ];

        if ($isAsset) {
            // Auto image embedding is experimental; the pipeline lands later.
            $controls[] = [
                'key' => 'imageEmbed',
                'type' => 'checkbox',
                'label' => Craft::t('typesense', 'Embed image (experimental)'),
                'instructions' => Craft::t('typesense', 'Generate a CLIP image embedding from this asset.') . $info(Craft::t('typesense', 'Sends the asset image to the server’s CLIP model at index time for image and cross-modal search. Experimental, and adds index-time cost per asset.')),
            ];
        }

        $controls[] = [
            'key' => 'description',
            'type' => 'text',
            'label' => Craft::t('typesense', 'Description'),
            'instructions' => Craft::t('typesense', 'A plain-language description of what this field holds.') . $info(Craft::t('typesense', 'Used by natural-language (conversational) search to reason about the field. Has no effect on ordinary keyword search.')),
        ];

        return $controls;
    }

    /**
     * Builds a mapping-layout element from a legacy descriptor and its legacy
     * settings array.
     *
     * @param array<string, mixed> $descriptor
     * @param array<string, mixed> $legacy
     * @return MappingField|NativeMappingField|null
     * @author CraftPulse
     */
    private function _legacyElement(array $descriptor, array $legacy): MappingField|NativeMappingField|null
    {
        $kind = (string)$descriptor['kind'];

        if ($kind === 'field' || $kind === 'variant') {
            $field = Craft::$app->getFields()->getFieldByUid((string)$descriptor['uid']);

            if ($field === null) {
                return null;
            }

            $element = MappingField::forField($field, $kind);
        } else {
            $element = new NativeMappingField([
                'handle' => (string)$descriptor['handle'],
                'label' => (string)$descriptor['label'],
                'derivedType' => (string)$descriptor['derivedType'],
                'kind' => $kind,
            ]);
        }

        $element->facet = (bool)($legacy['facet'] ?? false);
        $element->sortable = (bool)($legacy['sortable'] ?? false);
        $element->infix = (bool)($legacy['infix'] ?? false);
        $element->stem = (bool)($legacy['stem'] ?? false);
        $element->embed = (bool)($legacy['embed'] ?? false);
        $element->imageEmbed = (bool)($legacy['imageEmbed'] ?? false);
        $element->weight = isset($legacy['weight']) ? (string)$legacy['weight'] : null;
        $element->locale = isset($legacy['locale']) ? (string)$legacy['locale'] : null;
        $element->typeOverride = isset($legacy['type']) ? (string)$legacy['type'] : null;
        $element->description = isset($legacy['description']) ? (string)$legacy['description'] : null;

        return $element;
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
     * The locale options for the mapping slideout: a blank "server default" plus
     * the site's languages, normalised to the ISO language subtag Typesense
     * expects (deduped, so en-GB and en collapse to en). The mapping slideout is
     * rendered as PHP field HTML (not a Twig template), so it uses a select of
     * languages rather than the forms.languageMenuField macro the Twig editors use.
     *
     * @return array<int, array{label: string, value: string}>
     * @author CraftPulse
     */
    private function _localeOptions(): array
    {
        $options = [['label' => Craft::t('typesense', 'Default (server)'), 'value' => '']];
        $seen = [];

        foreach (Craft::$app->getI18n()->getSiteLocaleIds() as $id) {
            $language = Locale::toTypesense($id);

            if ($language === null || isset($seen[$language])) {
                continue;
            }

            $seen[$language] = true;
            $options[] = ['label' => $language, 'value' => $language];
        }

        return $options;
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
