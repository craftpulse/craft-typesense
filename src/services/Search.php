<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\services;

use Craft;
use craft\base\Component;
use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\Typesense;
use Throwable;

/**
 * Front-end search layer.
 *
 * Wraps the client's search with the plugin's conventions: it always passes the
 * collection's preset (when declared), attaches the synonym-set name on v30.2+,
 * and forwards stopwords and voice queries. Single and multi-search (union) are
 * supported, and every path is fail-soft: an unreachable server yields a
 * well-formed empty result rather than an error.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class Search extends Component
{
    // Constants
    // =========================================================================

    /**
     * @var int The largest page size the front-end search endpoint will honour.
     */
    public const MAX_PER_PAGE = 100;

    // Public Methods
    // =========================================================================

    /**
     * Runs a front-end search from the loosely-typed options a request (or a
     * template) supplies: a query string, selected facet values, a facet field
     * to aggregate, a sort clause, a page, and a page size. Every value is
     * validated and bounded here, so callers never build Typesense params by
     * hand and untrusted input cannot widen the query. Fail-soft.
     *
     * @param string $handle
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     * @author CraftPulse
     */
    public function frontendSearch(string $handle, array $options): array
    {
        $q = trim((string)($options['q'] ?? ''));
        $params = [
            'q' => $q === '' ? '*' : mb_substr($q, 0, 256),
            'page' => max(1, (int)($options['page'] ?? 1)),
            'per_page' => max(1, min(self::MAX_PER_PAGE, (int)($options['perPage'] ?? 20))),
        ];

        if (!empty($options['queryBy'])) {
            $params['query_by'] = (string)$options['queryBy'];
        }

        if (!empty($options['sort'])) {
            $params['sort_by'] = (string)$options['sort'];
        }

        $facetField = isset($options['facetBy']) ? (string)$options['facetBy'] : '';

        if ($facetField !== '') {
            $params['facet_by'] = $facetField;
        }

        $filters = $this->_facetFilters($facetField, $options['facets'] ?? []);

        if ($filters !== '') {
            $params['filter_by'] = $filters;
        }

        return $this->search($handle, $params);
    }

    /**
     * Runs a search against a collection, enriched with the collection's preset,
     * synonym set, and any stopwords, then delegated to the fail-soft client.
     *
     * @param string $handle
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @author CraftPulse
     */
    public function search(string $handle, array $params): array
    {
        $registry = Typesense::$plugin->getCollectionRegistry();
        $collection = $registry->get($handle);

        if ($collection === null) {
            return Typesense::$plugin->getClient()->search($handle, $params);
        }

        $target = $registry->resolveName($collection, $this->_siteId());

        return Typesense::$plugin->getClient()->search($target, $this->_enrich($collection, $params));
    }

    /**
     * Runs a multi-search (union) across several collections, fail-soft.
     *
     * @param array<int, array<string, mixed>> $searches
     * @param array<string, mixed> $common
     * @return array<string, mixed>
     * @author CraftPulse
     */
    public function multiSearch(array $searches, array $common = []): array
    {
        $client = Typesense::$plugin->getClient()->client();
        $registry = Typesense::$plugin->getCollectionRegistry();

        if ($client !== null) {
            try {
                $enriched = [];

                foreach ($searches as $search) {
                    $handle = $search['collection'] ?? '';
                    $collection = $registry->get($handle);

                    if ($collection !== null) {
                        $search = $this->_enrich($collection, $search);
                        $search['collection'] = $registry->resolveName($collection, $this->_siteId());
                    }

                    $enriched[] = $search;
                }

                return $client->multiSearch->perform(['searches' => $enriched], $common);
            } catch (Throwable $e) {
                Craft::error("Multi-search failed: {$e->getMessage()}", 'typesense');
            }
        }

        return ['results' => []];
    }

    // Private Methods
    // =========================================================================

    /**
     * Enriches search params with the collection's preset, synonym set, and
     * stopwords. Explicitly-passed params always win.
     *
     * @param Collection $collection
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @author CraftPulse
     */
    private function _enrich(Collection $collection, array $params): array
    {
        $synonyms = Typesense::$plugin->getSynonyms();

        if ($collection->getPreset() !== null && !isset($params['preset'])) {
            $params['preset'] = Typesense::$plugin->getPresets()->presetName($collection);
        }

        if (
            $synonyms->usesSets()
            && $collection->getSynonymDefinitions() !== []
            && !isset($params['synonym_sets'])
        ) {
            $params['synonym_sets'] = $synonyms->setName($collection);
        }

        if ($collection->getStopwords() !== [] && !isset($params['stopwords'])) {
            $params['stopwords'] = Typesense::$plugin->getDictionaries()->stopwordsName($collection);
        }

        return $params;
    }

    /**
     * The site id to resolve collection names against: the current site on a site
     * request, falling back to the primary site (console, queue, tests).
     *
     * @return int
     * @author CraftPulse
     */
    private function _siteId(): int
    {
        return (int)Craft::$app->getSites()->getCurrentSite()->id;
    }

    /**
     * Builds a Typesense `filter_by` clause from a facet field and the selected
     * values, as `field:=[v1,v2]`. Non-scalar values are dropped and each value
     * is quoted, so selections coming straight from the request are safe.
     *
     * @param string $field
     * @param mixed $values
     * @return string
     * @author CraftPulse
     */
    private function _facetFilters(string $field, mixed $values): string
    {
        if ($field === '' || !is_array($values)) {
            return '';
        }

        $clean = [];

        foreach ($values as $value) {
            if (is_scalar($value) && (string)$value !== '') {
                $clean[] = '`' . str_replace('`', '', (string)$value) . '`';
            }
        }

        if ($clean === []) {
            return '';
        }

        return $field . ':=[' . implode(',', $clean) . ']';
    }
}
