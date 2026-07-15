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

use craft\base\FieldInterface;
use craft\elements\Asset;
use craft\fieldlayoutelements\CustomField;
use craftpulse\typesense\Typesense;

/**
 * A collection mapping-layout element that wraps a real Craft custom field (or a
 * Commerce variant field) and carries its Typesense mapping settings. It is the
 * field-layout counterpart of the descriptor the old mapping UI rendered as a
 * card: the same server-derived type and control set, now living in the native
 * field-layout designer's slideout via the shared MappingSettings trait.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class MappingField extends CustomField implements MappingElementInterface
{
    use MappingSettings;

    // Public Properties
    // =========================================================================

    /**
     * @var string The mapping kind: `field` (a source custom field) or
     * `variant` (a Commerce variant field).
     */
    public string $kind = 'field';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function tsDerivedType(): string
    {
        return Typesense::$plugin->getSchema()->typesenseTypeForField($this->getField());
    }

    /**
     * @inheritdoc
     */
    public function tsHandle(): string
    {
        return (string)$this->getField()->handle;
    }

    /**
     * @inheritdoc
     */
    public function tsIsAsset(): bool
    {
        $provider = $this->getLayout()->provider ?? null;

        return $provider instanceof \craftpulse\typesense\models\CollectionDefinition
            && is_a($provider->elementType, Asset::class, true);
    }

    /**
     * @inheritdoc
     */
    public function tsKind(): string
    {
        return $this->kind;
    }

    /**
     * Convenience constructor used by the palette event, tagging the wrapped
     * field with its mapping kind.
     *
     * @param FieldInterface $field
     * @param string $kind
     * @return self
     * @author CraftPulse
     */
    public static function forField(FieldInterface $field, string $kind = 'field'): self
    {
        $element = new self($field);
        $element->kind = $kind;

        return $element;
    }
}
