<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://percipio.london
 * @copyright Copyright (c) 2021 craftpulse
 */

namespace craftpulse\typesense\variables;

use craftpulse\typesense\Typesense;
use nystudio107\pluginvite\variables\ViteVariableInterface;
use nystudio107\pluginvite\variables\ViteVariableTrait;

/**
 * Typesense Variable
 *
 * Craft allows plugins to provide their own template variables, accessible from
 * the {{ craft }} global variable (e.g. {{ craft.typesense }}).
 *
 * https://craftcms.com/docs/plugins/variables
 *
 * @author    craftpulse
 * @package   Typesense
 * @since     1.0.0
 */
class TypesenseVariable implements ViteVariableInterface
{
    use ViteVariableTrait;

    // Public Methods
    // =========================================================================

    /**
     * Derives a scoped search key for the front end from the search-only key.
     *
     * {{ craft.typesense.scopedSearchKey({ filter_by: 'siteId:=1', expires_at: now.timestamp + 3600 }) }}
     *
     * @param array<string, mixed> $parameters
     * @return string
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function scopedSearchKey(array $parameters = []): string
    {
        return Typesense::$plugin->getKeys()->generateScopedSearchKey($parameters);
    }
}
