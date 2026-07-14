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
use craftpulse\typesense\builders\Field;

/**
 * A named computed field: a code-supplied value closure surfaced as a mapping-UI
 * card. The closure supplies the value; content managers control indexing, facet,
 * and weight decisions in the CP. The closure never serializes to project config.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class ComputedField extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var string The computed field name (the Typesense document key).
     */
    public string $name = '';

    /**
     * @var string The Typesense field type.
     */
    public string $type = 'string';

    /**
     * @var callable|null The value closure: fn($element): mixed.
     */
    public $value = null;

    // Public Methods
    // =========================================================================

    /**
     * Returns a Field builder describing this computed field's schema.
     *
     * @return Field
     * @author CraftPulse
     */
    public function toField(): Field
    {
        return Field::make($this->name, $this->type);
    }

    /**
     * Resolves the computed value for an element.
     *
     * @param mixed $element
     * @return mixed
     * @author CraftPulse
     */
    public function resolve(mixed $element): mixed
    {
        if ($this->value === null) {
            return null;
        }

        return ($this->value)($element);
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['name', 'type'], 'required'];
        $rules[] = [['name', 'type'], 'string'];

        return $rules;
    }
}
