<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\fieldlayoutelements;

use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\fieldlayoutelements\BaseField;
use craftpulse\typesense\models\CollectionDefinition;

/**
 * A collection mapping-layout element for a synthetic field that has no backing
 * Craft field: an asset's file-derived pseudo field (filename, kind, size,
 * width, height) or a registered computed field. It is offered to the layout
 * designer through the native-fields palette and carries the same Typesense
 * mapping settings as a real field, via the shared MappingSettings trait.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class NativeMappingField extends BaseField implements MappingElementInterface
{
    use MappingSettings;

    // Public Properties
    // =========================================================================

    /**
     * @var string The server-derived Typesense type of the synthetic field.
     */
    public string $derivedType = 'string';

    /**
     * @var string The source handle (the document key and, for pseudo fields,
     * the element attribute).
     */
    public string $handle = '';

    /**
     * @var string The mapping kind: `pseudo` or `computed`.
     */
    public string $kind = 'pseudo';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function attribute(): string
    {
        return $this->handle;
    }

    /**
     * @inheritdoc
     */
    public function tsDerivedType(): string
    {
        return $this->derivedType;
    }

    /**
     * @inheritdoc
     */
    public function tsHandle(): string
    {
        return $this->handle;
    }

    /**
     * @inheritdoc
     */
    public function tsIsAsset(): bool
    {
        $provider = $this->getLayout()->provider ?? null;

        return $provider instanceof CollectionDefinition
            && is_a($provider->elementType, Asset::class, true);
    }

    /**
     * @inheritdoc
     */
    public function tsKind(): string
    {
        return $this->kind;
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defaultLabel(?ElementInterface $element = null, bool $static = false): ?string
    {
        return $this->handle;
    }

    /**
     * @inheritdoc
     */
    protected function inputHtml(?ElementInterface $element = null, bool $static = false): ?string
    {
        // The mapping layout is never used to edit an element, so the field has
        // no editable input; the slideout carries the mapping settings instead.
        return null;
    }
}
