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

use craft\helpers\UrlHelper;
use craftpulse\typesense\twig\tags\RegionTag;
use craftpulse\typesense\twig\tags\SearchFormTag;
use craftpulse\typesense\Typesense;

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
class TypesenseVariable
{
    // Public Methods
    // =========================================================================

    /**
     * Returns the facet-sidebar region builder, for composing a custom search
     * layout. Wrap it in your own `<form data-signals>` alongside a results
     * region.
     *
     * {{ craft.typesense.facetList({ collection: 'products', facetBy: 'brand' }).render() }}
     *
     * @param array<string, mixed> $config
     * @return RegionTag
     * @author CraftPulse
     */
    public function facetList(array $config = []): RegionTag
    {
        return (new RegionTag($config))->region('facets');
    }

    /**
     * Runs a fail-soft front-end search from loosely-typed options (query,
     * selected facets, facet field, sort, page, page size), bounding every
     * value server-side. Handy for a fully custom page.
     *
     * @param string $handle
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     * @author CraftPulse
     */
    public function frontendSearch(string $handle, array $options = []): array
    {
        return Typesense::$plugin->getSearch()->frontendSearch($handle, $options);
    }

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
     * Returns the results-and-pagination region builder, for composing a custom
     * search layout. Wrap it in your own `<form data-signals>`.
     *
     * {{ craft.typesense.results({ collection: 'products' }).render() }}
     *
     * @param array<string, mixed> $config
     * @return RegionTag
     * @author CraftPulse
     */
    public function results(array $config = []): RegionTag
    {
        return (new RegionTag($config))->region('results');
    }

    /**
     * Derives a scoped search key for the front end from the search-only key.
     * Pass `profile` to apply a control-panel scoped-key profile (its locked
     * filter and restrictions take precedence over ad-hoc parameters).
     *
     * {{ craft.typesense.scopedSearchKey({ filter_by: 'siteId:=1', expires_at: now.timestamp + 3600 }) }}
     * {{ craft.typesense.scopedSearchKey({ profile: 'tenant-a' }) }}
     *
     * @param array<string, mixed> $parameters May include a `profile` handle.
     * @return string
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function scopedSearchKey(array $parameters = []): string
    {
        $profile = $parameters['profile'] ?? null;
        unset($parameters['profile']);

        return Typesense::$plugin->getKeys()->generateScopedSearchKey(
            $parameters,
            null,
            $profile !== null ? (string)$profile : null,
        );
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

    /**
     * Returns the fragment endpoint URL for hand-rolled Datastar forms. Using
     * this instead of writing the action path as a literal keeps the example
     * templates safe to install under a renamed folder.
     *
     * @return string
     * @author CraftPulse
     */
    public function searchEndpoint(): string
    {
        return UrlHelper::actionUrl('typesense/search/results');
    }

    /**
     * Returns the complete, self-contained search widget builder: a Datastar
     * signalled form wrapping the input, optional facet sidebar, results, and
     * pagination, server-rendered on first load and morph-updated thereafter.
     * Degrades to a plain GET form with no JavaScript.
     *
     * {{ craft.typesense.searchForm({ collection: 'products', facetBy: 'brand' }).render() }}
     *
     * @param array<string, mixed> $config
     * @return SearchFormTag
     * @author CraftPulse
     */
    public function searchForm(array $config = []): SearchFormTag
    {
        return new SearchFormTag($config);
    }
}
