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

use craft\base\ElementInterface;
use craft\helpers\Template;
use craft\helpers\UrlHelper;
use craftpulse\typesense\helpers\FrontendTemplates;
use craftpulse\typesense\helpers\ThemeConfig;
use craftpulse\typesense\twig\tags\RegionTag;
use craftpulse\typesense\twig\tags\SearchFormTag;
use craftpulse\typesense\Typesense;
use Twig\Markup;

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
     * Renders the theme-config HTML attributes for a search component: the
     * template's structural default classes merged with the operator's cosmetic
     * class and attribute overrides for that component key (or reset when
     * configured). Used inside the atomic search templates.
     *
     * {{ craft.typesense.attr(theme, 'facetItem', 'ts-facets__item') }}
     *
     * @param array<string, mixed> $theme the merged theme config
     * @param string $key the component key
     * @param string $defaultClasses the structural default classes
     * @return Markup
     * @author CraftPulse
     */
    public function attr(array $theme, string $key, string $defaultClasses = ''): Markup
    {
        return Template::raw(ThemeConfig::attributes($theme, $key, $defaultClasses));
    }

    /**
     * Renders an atomic search component through the override resolver: the file
     * from the configured override directory when present, otherwise the plugin
     * default. Used inside the region templates to compose the per-row atoms
     * (result card, facet item) so a project can override just those.
     *
     * {{ craft.typesense.component('_result-card', { hit: hit, theme: theme }) }}
     *
     * @param string $name the component name
     * @param array<string, mixed> $variables
     * @return Markup
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     * @throws \yii\base\Exception
     * @author CraftPulse
     */
    public function component(string $name, array $variables = []): Markup
    {
        return Template::raw(FrontendTemplates::render($name, $variables));
    }

    /**
     * Returns the front-end analytics event endpoint URL. Post a click or
     * conversion event to it (the admin key stays server-side); wire it into a
     * Datastar action or a plain fetch.
     *
     * @return string
     * @author CraftPulse
     */
    public function eventEndpoint(): string
    {
        return UrlHelper::actionUrl('typesense/search/track-event');
    }

    /**
     * Runs a conversational (RAG) search and returns the result, including the
     * generated answer at `result.conversation.answer`. Experimental: each call
     * makes a per-search LLM request (cost). Requires a configured conversation
     * model.
     *
     * {% set answer = craft.typesense.ask('products', 'red running shoes under 100', 'shop-advisor', { query_by: 'title,embedding' }) %}
     *
     * @param string $handle
     * @param string $question
     * @param string $modelId
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @author CraftPulse
     */
    public function ask(string $handle, string $question, string $modelId, array $params = []): array
    {
        $params['q'] = $question;

        return Typesense::$plugin->getSearch()->conversationalSearch($handle, $modelId, $params);
    }

    /**
     * Resolves an A/B experiment into a chosen variant and its derived scoped
     * search key (embedding the variant's analytics tag and applying its
     * scoped-key profile), for splitting front-end search traffic. Returns null
     * for an unknown or disabled experiment.
     *
     * {% set ab = craft.typesense.experiment('hero-ranking') %}
     * {# ab.variant, ab.key, ab.tag, ab.preset #}
     *
     * @param string $handle
     * @return array{variant: string, key: string, tag: string, preset: string}|null
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function experiment(string $handle): ?array
    {
        return Typesense::$plugin->getExperiments()->keyFor($handle);
    }

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
     * Finds items similar to an element (or a document id) in a collection, using
     * vector similarity. Requires the collection to have an auto-embedding field.
     *
     * {% set related = craft.typesense.similar(entry, 'products', 6) %}
     *
     * @param ElementInterface|int|string $elementOrId
     * @param string $handle
     * @param int $limit
     * @return array<string, mixed>
     * @author CraftPulse
     */
    public function similar(ElementInterface|int|string $elementOrId, string $handle, int $limit = 10): array
    {
        $id = $elementOrId instanceof ElementInterface ? (string)$elementOrId->id : (string)$elementOrId;

        return Typesense::$plugin->getSearch()->similar($handle, $id, $limit);
    }

    /**
     * Returns the fragment endpoint URL for hand-rolled Datastar forms. Pass the
     * fixed search config (collection, queryBy, facetBy, perPage) so it rides the
     * URL as query params; Datastar appends the client signals (q, page, facets)
     * under its own `datastar` param when it fetches. Using this instead of
     * writing the action path as a literal keeps the example templates safe to
     * install under a renamed folder.
     *
     * {{ craft.typesense.searchEndpoint({ collection: 'products', queryBy: 'title', perPage: 6 }) }}
     *
     * @param array<string, mixed> $config
     * @return string
     * @author CraftPulse
     */
    public function searchEndpoint(array $config = []): string
    {
        $params = array_filter([
            'collection' => (string)($config['collection'] ?? ''),
            'queryBy' => (string)($config['queryBy'] ?? ''),
            'facetBy' => (string)($config['facetBy'] ?? ''),
            'perPage' => isset($config['perPage']) ? (string)$config['perPage'] : '',
        ], static fn(string $value): bool => $value !== '');

        return UrlHelper::actionUrl('typesense/search/results', $params);
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
