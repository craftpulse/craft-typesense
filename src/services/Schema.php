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
use craft\base\FieldInterface;
use craft\elements\Address;
use craft\fields\Assets;
use craft\fields\Categories;
use craft\fields\Checkboxes;
use craft\fields\Color;
use craft\fields\Country;
use craft\fields\Date;
use craft\fields\Dropdown;
use craft\fields\Email;
use craft\fields\Entries;
use craft\fields\Lightswitch;
use craft\fields\Matrix;
use craft\fields\Money;
use craft\fields\MultiSelect;
use craft\fields\Number;
use craft\fields\PlainText;
use craft\fields\RadioButtons;
use craft\fields\Table;
use craft\fields\Tags;
use craft\fields\Url;
use craft\fields\Users;
use craftpulse\typesense\events\DefineTypeMapEvent;
use craftpulse\typesense\events\RegisterComputedFieldsEvent;
use craftpulse\typesense\models\ComputedField;

/**
 * Schema derivation service.
 *
 * Derives Typesense field types from Craft field types (extensible via
 * EVENT_DEFINE_TYPE_MAP), normalizes geopoint values from the common location
 * shapes ([lat, lng] order, zero-coordinate skip), and holds the registry of
 * code-registered computed fields (EVENT_REGISTER_COMPUTED_FIELDS).
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class Schema extends Component
{
    // Constants
    // =========================================================================

    /**
     * @event DefineTypeMapEvent The event for extending the field-type derivation map.
     */
    public const EVENT_DEFINE_TYPE_MAP = 'defineTypeMap';

    /**
     * @event RegisterComputedFieldsEvent The event for registering computed fields.
     */
    public const EVENT_REGISTER_COMPUTED_FIELDS = 'registerComputedFields';

    /**
     * @var string The Typesense type used when a Craft field has no mapping.
     */
    public const DEFAULT_TYPE = 'string';

    // Public Methods
    // =========================================================================

    /**
     * Returns the Craft-field-class to Typesense-type derivation map, extended
     * by EVENT_DEFINE_TYPE_MAP.
     *
     * @return array<class-string, string>
     * @author CraftPulse
     */
    public function getTypeMap(): array
    {
        $event = new DefineTypeMapEvent(['types' => $this->_defaultTypeMap()]);

        if ($this->hasEventHandlers(self::EVENT_DEFINE_TYPE_MAP)) {
            $this->trigger(self::EVENT_DEFINE_TYPE_MAP, $event);
        }

        return $event->types;
    }

    /**
     * Derives the Typesense field type for a Craft field. Falls back to an exact
     * class match, then an instanceof match, then the default type.
     *
     * @param FieldInterface $field
     * @return string
     * @author CraftPulse
     */
    public function typesenseTypeForField(FieldInterface $field): string
    {
        $map = $this->getTypeMap();
        $class = $field::class;

        if (isset($map[$class])) {
            return $map[$class];
        }

        foreach ($map as $fieldClass => $type) {
            if ($field instanceof $fieldClass) {
                return $type;
            }
        }

        return self::DEFAULT_TYPE;
    }

    /**
     * Normalizes a location value to a Typesense geopoint [lat, lng], or null
     * when the value is missing or a zero coordinate (which must be skipped).
     *
     * @param mixed $value
     * @return array{0: float, 1: float}|null
     * @author CraftPulse
     */
    public function normalizeGeopoint(mixed $value): ?array
    {
        $pair = $this->_extractLatLng($value);

        if ($pair === null) {
            return null;
        }

        [$lat, $lng] = $pair;

        if ($lat === null || $lng === null || !is_numeric($lat) || !is_numeric($lng)) {
            return null;
        }

        $lat = (float)$lat;
        $lng = (float)$lng;

        if ($lat === 0.0 && $lng === 0.0) {
            return null;
        }

        return [$lat, $lng];
    }

    /**
     * Returns the registered computed fields, keyed by name.
     *
     * @return array<string, ComputedField>
     * @author CraftPulse
     */
    public function getComputedFields(): array
    {
        $event = new RegisterComputedFieldsEvent();

        if ($this->hasEventHandlers(self::EVENT_REGISTER_COMPUTED_FIELDS)) {
            $this->trigger(self::EVENT_REGISTER_COMPUTED_FIELDS, $event);
        }

        $computedFields = [];

        foreach ($event->computedFields as $computedField) {
            $computedFields[$computedField->name] = $computedField;
        }

        return $computedFields;
    }

    /**
     * Returns a registered computed field by name, or null.
     *
     * @param string $name
     * @return ComputedField|null
     * @author CraftPulse
     */
    public function getComputedField(string $name): ?ComputedField
    {
        return $this->getComputedFields()[$name] ?? null;
    }

    // Private Methods
    // =========================================================================

    /**
     * The built-in Craft-field-class to Typesense-type derivation map.
     *
     * @return array<class-string, string>
     * @author CraftPulse
     */
    private function _defaultTypeMap(): array
    {
        return [
            PlainText::class => 'string',
            Email::class => 'string',
            Url::class => 'string',
            Color::class => 'string',
            Country::class => 'string',
            Dropdown::class => 'string',
            RadioButtons::class => 'string',
            Number::class => 'float',
            Money::class => 'float',
            Lightswitch::class => 'bool',
            Date::class => 'int64',
            Checkboxes::class => 'string[]',
            MultiSelect::class => 'string[]',
            Categories::class => 'string[]',
            Entries::class => 'string[]',
            Tags::class => 'string[]',
            Users::class => 'string[]',
            Assets::class => 'string[]',
            Table::class => 'object[]',
            Matrix::class => 'object[]',
        ];
    }

    /**
     * Extracts a raw [lat, lng] pair from the common location value shapes.
     *
     * @param mixed $value
     * @return array{0: mixed, 1: mixed}|null
     * @author CraftPulse
     */
    private function _extractLatLng(mixed $value): ?array
    {
        if ($value instanceof Address) {
            return [$value->latitude, $value->longitude];
        }

        if (is_object($value)) {
            $value = get_object_vars($value);
        }

        if (!is_array($value)) {
            return null;
        }

        foreach ([['lat', 'lng'], ['lat', 'lon'], ['latitude', 'longitude']] as [$latKey, $lngKey]) {
            if (array_key_exists($latKey, $value) && array_key_exists($lngKey, $value)) {
                return [$value[$latKey], $value[$lngKey]];
            }
        }

        if (array_keys($value) === [0, 1]) {
            return [$value[0], $value[1]];
        }

        return null;
    }
}
