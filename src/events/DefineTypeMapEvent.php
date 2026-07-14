<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\events;

use yii\base\Event;

/**
 * Event for extending the Craft-field-class to Typesense-type derivation map.
 *
 * Third-party field types register their derivation here (for example a maps
 * plugin mapping its field class to `geopoint`).
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class DefineTypeMapEvent extends Event
{
    // Public Properties
    // =========================================================================

    /**
     * @var array<class-string, string> Craft field class => Typesense field type.
     */
    public array $types = [];
}
