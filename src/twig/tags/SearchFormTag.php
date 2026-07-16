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
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\View;
use craftpulse\typesense\Typesense;

/**
 * SearchFormTag is the complete, self-contained front-end search widget,
 * rendered via `craft.typesense.searchForm({...}).render()`.
 *
 * It wraps a Datastar-signalled `<form>` around a search input, an optional
 * facet sidebar, the results list, and pagination, and paints all of them
 * server-side on first load. With Datastar present, typing (debounced),
 * toggling a facet, or paging fires a request to the fragment endpoint and
 * morphs the results, facets, and pagination regions in place. With no
 * JavaScript, the form submits a plain GET to the same URL and the page
 * re-renders the full results server-side, so the widget degrades cleanly.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class SearchFormTag extends BaseTag
{
    // Public Methods
    // =========================================================================

    /**
     * Sets the no-JS form action (the URL a plain GET submits to). Default: the
     * current URL, so a submit re-renders the same page with the query params.
     *
     * @param string $action
     * @return $this
     * @author CraftPulse
     */
    public function action(string $action): self
    {
        $this->config['action'] = $action;
        return $this;
    }

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
     * Sets the field to aggregate as the facet sidebar. Omit for no facets.
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
     * Sets the search input's placeholder.
     *
     * @param string $placeholder
     * @return $this
     * @author CraftPulse
     */
    public function placeholder(string $placeholder): self
    {
        $this->config['placeholder'] = $placeholder;
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
        $result = $collection !== '' ? Typesense::$plugin->getSearch()->frontendSearch($collection, $options) : [];

        // The fixed search config (collection, queryBy, facetBy, perPage) rides
        // the endpoint URL, not the client signals; Datastar appends q, page, and
        // facets under its own `datastar` param when it fetches.
        $endpoint = UrlHelper::actionUrl('typesense/search/results', array_filter([
            'collection' => $collection,
            'queryBy' => $options['queryBy'],
            'facetBy' => $options['facetBy'],
            'perPage' => (string)$options['perPage'],
        ], static fn(string $value): bool => $value !== ''));

        // tsFound and tsMs are patched by the SSE response so the status line
        // updates without morphing a region; tsSearching is toggled by the
        // input's data-indicator during the fetch.
        $signals = Json::encode([
            'q' => $options['q'],
            'page' => $options['page'],
            'facets' => $options['facets'],
            'tsFound' => (int)($result['found'] ?? 0),
            'tsMs' => 0,
            'tsSearching' => false,
        ]);

        $formAttrs = Html::renderTagAttributes([
            'method' => 'get',
            'action' => (string)($this->config['action'] ?? ''),
            'class' => 'ts-search',
            'role' => 'search',
            'data-signals' => $signals,
        ]);

        $input = $this->_inputHtml($collection, $endpoint, $options);
        $status = $this->_statusHtml($result);
        $regions = $this->_regionsHtml($collection, $endpoint, $options, $result);

        return "<form{$formAttrs}>{$input}{$status}{$regions}</form>";
    }

    // Private Methods
    // =========================================================================

    /**
     * Renders the labelled, signal-bound search input with the debounced
     * search-as-you-type binding and a hidden collection field.
     *
     * @param string $collection
     * @param string $endpoint
     * @param array<string, mixed> $options
     * @return string
     * @author CraftPulse
     */
    private function _inputHtml(string $collection, string $endpoint, array $options): string
    {
        $placeholder = (string)($this->config['placeholder'] ?? Craft::t('typesense', 'Search'));
        $label = Html::tag('label', Craft::t('typesense', 'Search'), [
            'for' => 'ts-search-input',
            'class' => 'ts-search__label sr-only',
        ]);

        $get = "\$page = 1; @get('" . $endpoint . "')";
        $inputAttrs = Html::renderTagAttributes([
            'type' => 'search',
            'id' => 'ts-search-input',
            'name' => 'q',
            'value' => $options['q'],
            'placeholder' => $placeholder,
            'class' => 'ts-search__input w-full rounded border border-gray-300 px-3 py-2',
            'autocomplete' => 'off',
            'data-bind:q' => true,
            'data-on:input__debounce.300ms' => $get,
            'data-indicator:tsSearching' => true,
        ]);

        return Html::tag('div', $label . "<input{$inputAttrs}>" . Html::hiddenInput('collection', $collection), [
            'class' => 'ts-search__field mb-4',
        ]);
    }

    /**
     * Renders the signal-driven status line: the found count and elapsed
     * milliseconds (patched by the SSE patch-signals event, so they update
     * without morphing a region) and a searching indicator. The numbers are
     * server-rendered too, so the no-JS path shows the real count and Datastar
     * only swaps the live signal in once it boots.
     *
     * @param array<string, mixed> $result
     * @return string
     * @author CraftPulse
     */
    private function _statusHtml(array $result): string
    {
        $found = (int)($result['found'] ?? 0);
        $countSpan = Html::tag('span', (string)$found, ['data-text' => '$tsFound']);
        $msSpan = Html::tag('span', '0', ['data-text' => '$tsMs']);
        $searching = Html::tag('span', Craft::t('typesense', '(searching...)'), [
            'class' => 'ts-search__searching text-gray-400',
            'data-show' => '$tsSearching',
        ]);

        $text = Craft::t('typesense', 'Found') . ' ' . $countSpan . ' ' . Craft::t('typesense', 'results')
            . ' (' . $msSpan . ' ' . Craft::t('typesense', 'ms') . ') ' . $searching;

        return Html::tag('p', $text, ['class' => 'ts-search__status text-sm text-gray-500 mb-4']);
    }

    /**
     * Renders the facet, results, and pagination fragments for the initial
     * server-side paint, wrapped in a two-column layout.
     *
     * @param string $collection
     * @param string $endpoint
     * @param array<string, mixed> $options
     * @param array<string, mixed> $result
     * @return string
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     * @throws \yii\base\Exception
     * @author CraftPulse
     */
    private function _regionsHtml(string $collection, string $endpoint, array $options, array $result): string
    {
        $view = Craft::$app->getView();
        $variables = compact('collection', 'endpoint', 'options', 'result');

        $facets = '';

        if (($options['facetBy'] ?? '') !== '') {
            $facets = Html::tag('aside', $view->renderTemplate('_typesense/facets', $variables, View::TEMPLATE_MODE_SITE), [
                'class' => 'ts-search__facets',
            ]);
        }

        $main = Html::tag('div', $view->renderTemplate('_typesense/results', $variables, View::TEMPLATE_MODE_SITE)
            . $view->renderTemplate('_typesense/pagination', $variables, View::TEMPLATE_MODE_SITE), [
                'class' => 'ts-search__main flex-1',
            ]);

        return Html::tag('div', $facets . $main, ['class' => 'ts-search__body flex gap-6']);
    }
}
