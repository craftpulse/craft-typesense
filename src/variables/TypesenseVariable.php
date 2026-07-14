<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
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
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class TypesenseVariable implements ViteVariableInterface
{
    use ViteVariableTrait;

    // Public Methods
    // =========================================================================

    /**
     * Runs a fail-soft multi-search (union) across several collections.
     *
     * @param array<int, array<string, mixed>> $searches
     * @param array<string, mixed> $common
     * @return array<string, mixed>
     * @author CraftPulse
     */
    public function multiSearch(array $searches, array $common = []): array
    {
        return Typesense::$plugin->getSearch()->multiSearch($searches, $common);
    }

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

    /**
     * Runs a fail-soft search against a collection through the search layer,
     * enriched with the collection's preset, synonym set, and stopwords.
     *
     * {{ craft.typesense.search('products', { q: 'coat', query_by: 'title' }) }}
     *
     * @param string $handle
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @author CraftPulse
     */
    public function search(string $handle, array $params = []): array
    {
        return Typesense::$plugin->getSearch()->search($handle, $params);
    }
}
