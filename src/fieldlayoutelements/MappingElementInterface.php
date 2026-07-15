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

/**
 * The contract a field-layout element implements to be read by the collection
 * compiler: it names the document key, its mapping kind, and the Typesense type
 * (derived and resolved), and exposes its per-field mapping settings. Both the
 * custom-field element (MappingField) and the synthetic native element
 * (NativeMappingField) implement it via the shared MappingSettings trait.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
interface MappingElementInterface
{
    // Public Methods
    // =========================================================================

    /**
     * The server-derived Typesense type, before any override.
     *
     * @return string
     * @author CraftPulse
     */
    public function tsDerivedType(): string;

    /**
     * The per-field mapping settings: `facet`, `sortable`, `infix`, `stem`,
     * `locale`, `weight`, `description`, `embed`, and `imageEmbed`.
     *
     * @return array<string, mixed>
     * @author CraftPulse
     */
    public function tsFieldSettings(): array;

    /**
     * The source handle the document key is produced from.
     *
     * @return string
     * @author CraftPulse
     */
    public function tsHandle(): string;

    /**
     * The mapping kind: `field`, `variant`, `pseudo`, or `computed`.
     *
     * @return string
     * @author CraftPulse
     */
    public function tsKind(): string;

    /**
     * The resolved Typesense type: the bounded override when set, else the
     * derived type.
     *
     * @return string
     * @author CraftPulse
     */
    public function tsResolvedType(): string;
}
