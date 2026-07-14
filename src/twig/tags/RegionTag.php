<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\twig\tags;

use Craft;
use craft\helpers\UrlHelper;
use craft\web\View;
use craftpulse\typesense\Typesense;

/**
 * RegionTag renders a single front-end search region (the results list or the
 * facet sidebar) standalone, for building a custom search layout by hand.
 * `craft.typesense.results({...})` and
 * `craft.typesense.facetList({...})` are the two named entry points.
 *
 * Unlike [[SearchFormTag]], a region does not emit the wrapping form or the
 * Datastar signals; the caller supplies their own `<form data-signals>` around
 * the regions they compose. Each region keeps the stable element id the
 * fragment endpoint morphs into.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class RegionTag extends BaseTag
{
    // Public Methods
    // =========================================================================

    /**
     * Sets the collection handle to search.
     *
     * @param string $collection
     * @return $this
     * @author CraftPulse
     */
    public function collection(string $collection): self
    {
        $this->config['collection'] = $collection;
        return $this;
    }

    /**
     * Sets the facet field, for the facet region.
     *
     * @param string $facetBy
     * @return $this
     * @author CraftPulse
     */
    public function facetBy(string $facetBy): self
    {
        $this->config['facetBy'] = $facetBy;
        return $this;
    }

    /**
     * Sets the page size.
     *
     * @param int $perPage
     * @return $this
     * @author CraftPulse
     */
    public function perPage(int $perPage): self
    {
        $this->config['perPage'] = $perPage;
        return $this;
    }

    /**
     * Sets the `query_by` fields the search matches against.
     *
     * @param string $queryBy
     * @return $this
     * @author CraftPulse
     */
    public function queryBy(string $queryBy): self
    {
        $this->config['queryBy'] = $queryBy;
        return $this;
    }

    /**
     * Sets which partial the region renders: `results` or `facets`. Set by the
     * variable's entry-point methods.
     *
     * @param string $region
     * @return $this
     * @author CraftPulse
     */
    public function region(string $region): self
    {
        $this->config['region'] = $region;
        return $this;
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     * @throws \yii\base\Exception
     */
    protected function _renderHtml(): string
    {
        /** @var \craft\web\Request $request */
        $request = Craft::$app->getRequest();
        $facetsParam = $request->getParam('facets', []);

        $options = [
            'q' => (string)$request->getParam('q', ''),
            'facetBy' => (string)($this->config['facetBy'] ?? ''),
            'facets' => is_array($facetsParam) ? array_values(array_filter($facetsParam, 'is_scalar')) : [],
            'queryBy' => (string)($this->config['queryBy'] ?? ''),
            'page' => max(1, (int)$request->getParam('page', 1)),
            'perPage' => (int)($this->config['perPage'] ?? 20),
        ];

        $collection = (string)($this->config['collection'] ?? '');
        $endpoint = UrlHelper::actionUrl('typesense/search/results');
        $result = $collection !== '' ? Typesense::$plugin->getSearch()->frontendSearch($collection, $options) : [];
        $variables = compact('collection', 'endpoint', 'options', 'result');

        $partial = ($this->config['region'] ?? 'results') === 'facets'
            ? '_typesense/facets'
            : '_typesense/results';

        return Craft::$app->getView()->renderTemplate($partial, $variables, View::TEMPLATE_MODE_SITE);
    }
}
