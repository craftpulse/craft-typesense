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

use craft\helpers\Cp;
use craftpulse\typesense\Typesense;

/**
 * The per-field Typesense mapping settings carried by a field-layout element in
 * a collection's mapping layout. Being placed in the layout means the field is
 * indexed; the settings below tune how: facet, sort, search weight, infix and
 * stemming, locale, a bounded type override, and a natural-language description.
 *
 * The control set the slideout renders is server-authoritative: it is derived
 * from the field's Typesense type through the MappingSources service, so the UI
 * can only offer what the server would accept. The settings serialise with the
 * element into the layout's project-config, and the compiler reads them back.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
trait MappingSettings
{
    // Public Properties
    // =========================================================================

    /**
     * @var string|null A natural-language description of the field, used for
     * natural-language (conversational) search.
     */
    public ?string $description = null;

    /**
     * @var bool Whether nested object fields are indexed (object types only).
     */
    public bool $embed = false;

    /**
     * @var bool Whether the field is facetable.
     */
    public bool $facet = false;

    /**
     * @var bool Whether an asset's image is embedded for CLIP search.
     */
    public bool $imageEmbed = false;

    /**
     * @var bool Whether infix (mid-string) search is enabled (text types only).
     */
    public bool $infix = false;

    /**
     * @var string|null The stemming/search locale (text types only).
     */
    public ?string $locale = null;

    /**
     * @var bool Whether the field is sortable.
     */
    public bool $sortable = false;

    /**
     * @var bool Whether stemming is enabled (text types only).
     */
    public bool $stem = false;

    /**
     * @var string|null A bounded Typesense type override; empty uses the
     * server-derived type.
     */
    public ?string $typeOverride = null;

    /**
     * @var string|null The search weight (text types only), kept as a string so
     * an empty control round-trips cleanly; the compiler casts it.
     */
    public ?string $weight = null;

    // Public Methods
    // =========================================================================

    /**
     * The Typesense type the field would resolve to before any override.
     *
     * @return string
     * @author CraftPulse
     */
    abstract public function tsDerivedType(): string;

    /**
     * The source handle the document key is produced from.
     *
     * @return string
     * @author CraftPulse
     */
    abstract public function tsHandle(): string;

    /**
     * Whether this element belongs to an asset collection (enables the image
     * embed control).
     *
     * @return bool
     * @author CraftPulse
     */
    abstract public function tsIsAsset(): bool;

    /**
     * The mapping kind: `field`, `variant`, `pseudo`, or `computed`.
     *
     * @return string
     * @author CraftPulse
     */
    abstract public function tsKind(): string;

    /**
     * @inheritdoc
     */
    public function hasSettings(): bool
    {
        return true;
    }

    /**
     * @inheritdoc
     */
    public function tsFieldSettings(): array
    {
        return [
            'facet' => $this->facet,
            'sortable' => $this->sortable,
            'infix' => $this->infix,
            'stem' => $this->stem,
            'locale' => $this->locale,
            'weight' => $this->weight,
            'description' => $this->description,
            'embed' => $this->embed,
            'imageEmbed' => $this->imageEmbed,
        ];
    }

    /**
     * The resolved Typesense type: the bounded override when set, else the
     * server-derived type.
     *
     * @return string
     * @author CraftPulse
     */
    public function tsResolvedType(): string
    {
        $sources = Typesense::$plugin->getMappingSources();
        $options = $sources->typeOverrideOptions($this->tsDerivedType());

        if ($this->typeOverride !== null && $this->typeOverride !== '' && in_array($this->typeOverride, $options, true)) {
            return $this->typeOverride;
        }

        return $this->tsDerivedType();
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): ?string
    {
        $derivedType = $this->tsDerivedType();
        $html = '';

        foreach (Typesense::$plugin->getMappingSources()->controlsFor($derivedType, $this->tsIsAsset()) as $control) {
            $key = (string)$control['key'];

            // Placement in the layout is the "indexed" signal, so the old
            // indexable checkbox has no control here.
            if ($key === 'indexable') {
                continue;
            }

            $html .= $this->_controlHtml($key, $control);
        }

        return $html !== '' ? $html : null;
    }

    // Private Methods
    // =========================================================================

    /**
     * Renders one server-authoritative control as a Craft form field, named to
     * match the element property it round-trips into.
     *
     * @param string $key
     * @param array<string, mixed> $control
     * @return string
     * @author CraftPulse
     */
    private function _controlHtml(string $key, array $control): string
    {
        $label = (string)($control['label'] ?? $key);
        $prop = $this->_prop($key);

        return match ((string)$control['type']) {
            'checkbox' => Cp::lightswitchFieldHtml([
                'label' => $label,
                'id' => $prop,
                'name' => $prop,
                'on' => (bool)$this->{$prop},
            ]),
            'number' => Cp::textFieldHtml([
                'label' => $label,
                'type' => 'number',
                'id' => $prop,
                'name' => $prop,
                'value' => $this->{$prop},
            ]),
            'select' => Cp::selectFieldHtml([
                'label' => $label,
                'id' => $prop,
                'name' => $prop,
                'options' => array_map(
                    static fn(string $option): array => ['label' => $option, 'value' => $option],
                    (array)($control['options'] ?? []),
                ),
                'value' => ($this->typeOverride !== null && $this->typeOverride !== '') ? $this->typeOverride : $this->tsDerivedType(),
            ]),
            default => Cp::textFieldHtml([
                'label' => $label,
                'id' => $prop,
                'name' => $prop,
                'value' => $this->{$prop},
            ]),
        };
    }

    /**
     * Maps a control key to its backing element property name.
     *
     * @param string $key
     * @return string
     * @author CraftPulse
     */
    private function _prop(string $key): string
    {
        return $key === 'type' ? 'typeOverride' : $key;
    }
}
