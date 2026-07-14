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

use craftpulse\typesense\models\ComputedField;
use yii\base\Event;

/**
 * Event for registering named computed fields (code-supplied value closures that
 * surface as mapping-UI cards).
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class RegisterComputedFieldsEvent extends Event
{
    // Public Properties
    // =========================================================================

    /**
     * @var ComputedField[] The registered computed fields.
     */
    public array $computedFields = [];
}
