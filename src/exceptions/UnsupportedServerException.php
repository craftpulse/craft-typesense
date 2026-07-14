<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\exceptions;

use yii\base\Exception;

/**
 * Thrown when the connected Typesense server version is below the supported
 * floor or is explicitly refused (v30.0 / v30.1).
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class UnsupportedServerException extends Exception
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function getName(): string
    {
        return 'Unsupported Typesense server version';
    }
}
