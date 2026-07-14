<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\models;

/**
 * The result of building documents for one element in one collection and site:
 * the documents to upsert (empty when the element is not indexable, signalling a
 * delete) and the dependency element IDs collected during the transform.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class DocumentBuildResult
{
    // Public Properties
    // =========================================================================

    /**
     * @var array<int, array<string, mixed>> The documents to upsert.
     */
    public array $documents = [];

    /**
     * @var array<int, int> The dependency element IDs.
     */
    public array $dependencies = [];

    // Public Methods
    // =========================================================================

    /**
     * @param array<int, array<string, mixed>> $documents
     * @param array<int, int> $dependencies
     * @author CraftPulse
     */
    public function __construct(array $documents = [], array $dependencies = [])
    {
        $this->documents = $documents;
        $this->dependencies = $dependencies;
    }

    /**
     * Whether the element produced no documents (a delete signal).
     *
     * @return bool
     * @author CraftPulse
     */
    public function isEmpty(): bool
    {
        return $this->documents === [];
    }
}
