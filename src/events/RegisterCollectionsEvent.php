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

use craftpulse\typesense\builders\Collection;
use yii\base\Event;

/**
 * Event for registering collection definitions from other plugins or project code.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class RegisterCollectionsEvent extends Event
{
    // Public Properties
    // =========================================================================

    /**
     * @var Collection[] The registered collection builders.
     */
    public array $collections = [];
}
