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

use craft\base\Model;

/**
 * A single field mapping for the generated document path.
 *
 * Describes how one document key is produced: from a Craft field handle, from a
 * registered computed field, or from an element attribute. This is the runtime
 * shape of the mapping data that the Pro mapping UI (P8) persists to project
 * config, keyed by field UID; defining it here lets the generated path execute
 * from it before that UI exists.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class FieldMapping extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var string The Typesense document key.
     */
    public string $documentKey = '';

    /**
     * @var string The Typesense field type.
     */
    public string $type = 'string';

    /**
     * @var bool Whether the field is indexed (included in the document).
     */
    public bool $indexed = true;

    /**
     * @var string|null The Craft field handle to read the value from.
     */
    public ?string $fieldHandle = null;

    /**
     * @var string|null The element attribute to read the value from (for example "title").
     */
    public ?string $attribute = null;

    /**
     * @var string|null The registered computed-field name to resolve the value from.
     */
    public ?string $computedFieldName = null;

    // Public Methods
    // =========================================================================

    /**
     * Creates a mapping from a plain array (the project-config shape).
     *
     * @param array<string, mixed> $config
     * @return self
     * @author CraftPulse
     */
    public static function fromArray(array $config): self
    {
        $mapping = new self();
        $mapping->setAttributes($config, false);

        return $mapping;
    }
}
