<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\controllers;

use Craft;
use craft\web\assets\cp\CpAsset;
use craftpulse\typesense\assetbundles\playground\PlaygroundAsset;
use craftpulse\typesense\controllers\base\ProController;
use craftpulse\typesense\Typesense;
use Throwable;
use yii\web\Response;

/**
 * The Pro search playground: one GraphiQL-style screen per collection.
 *
 * The screen mirrors Craft's own GraphiQL explorer
 * (vendor/craftcms/cms/src/templates/graphql/graphiql.twig): a collection picker
 * and Run in the header toolbar, a search-params JSON editor on the left, the
 * ranked response on the right, and a docs-explorer side pane carrying the live
 * schema and a pageable, filterable document browser. A diff drawer runs the
 * query twice (current versus a pending overlay) and reports how the ranking
 * would change before anything is saved. Every query runs through the P6 search
 * layer, so the admin key never reaches the browser and the result payload is
 * bounded.
 *
 * Gated by the edition (via [[ProController]]) and the
 * `typesense:viewDiagnostics` permission.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class PlaygroundController extends ProController
{
    // Constants
    // =========================================================================

    /**
     * @var int The largest page size any playground query will honour.
     */
    public const MAX_PER_PAGE = 250;

    /**
     * @var string The permission that gates the search playground (a diagnostics
     * surface).
     */
    public const PERMISSION_VIEW_DIAGNOSTICS = 'typesense:viewDiagnostics';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @param \yii\base\Action $action
     * @return bool
     * @throws \yii\web\ForbiddenHttpException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\web\BadRequestHttpException
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(self::PERMISSION_VIEW_DIAGNOSTICS);

        return true;
    }

    /**
     * Redirects the retired document-browser URL to the unified playground; the
     * browser now lives in the playground's docs-explorer side pane.
     *
     * @param string $collection
     * @return Response
     * @author CraftPulse
     */
    public function actionBrowse(string $collection): Response
    {
        return $this->redirect('typesense/playground/' . $collection);
    }

    /**
     * Returns a diff between the current query result and a pending-overlay
     * result: hits that entered, dropped, or moved rank.
     *
     * @return Response
     * @throws \yii\web\BadRequestHttpException
     * @author CraftPulse
     */
    public function actionDiff(): Response
    {
        $this->requirePostRequest();

        $handle = (string)$this->request->getBodyParam('collection', '');
        $base = $this->_boundBody($this->request->getBodyParam('body', ''));
        $overlay = $this->_boundBody($this->request->getBodyParam('overlay', ''));
        $pending = array_merge($base, $overlay);

        $search = Typesense::$plugin->getSearch();
        $current = $search->search($handle, $base);
        $proposed = $search->search($handle, $pending);

        return $this->asJson([
            'current' => $this->_summarise($current),
            'proposed' => $this->_summarise($proposed),
            'diff' => $this->_diff($current, $proposed),
        ]);
    }

    /**
     * Returns a page of the collection's indexed documents for the docs-explorer
     * side pane.
     *
     * @return Response
     * @throws \yii\web\BadRequestHttpException
     * @author CraftPulse
     */
    public function actionDocuments(): Response
    {
        $this->requirePostRequest();

        $handle = (string)$this->request->getBodyParam('collection', '');
        $collection = Typesense::$plugin->getCollectionRegistry()->get($handle);
        $client = Typesense::$plugin->getClient()->client();

        if ($collection === null || $client === null) {
            return $this->asJson(['found' => 0, 'documents' => [], 'stats' => []]);
        }

        $registry = Typesense::$plugin->getCollectionRegistry();
        $target = $registry->resolveName($collection, Craft::$app->getSites()->getCurrentSite()->id);

        $params = [
            'q' => '*',
            'query_by' => $this->_wildcardQueryBy($target, $client),
            'page' => max(1, (int)$this->request->getBodyParam('page', 1)),
            'per_page' => max(1, min(self::MAX_PER_PAGE, (int)$this->request->getBodyParam('perPage', 25))),
        ];

        $filterBy = trim((string)$this->request->getBodyParam('filterBy', ''));

        if ($filterBy !== '') {
            $params['filter_by'] = $filterBy;
        }

        $sortBy = trim((string)$this->request->getBodyParam('sortBy', ''));

        if ($sortBy !== '') {
            $params['sort_by'] = $sortBy;
        }

        try {
            $result = $client->collections[$target]->documents->search($params);
            $stats = $client->collections[$target]->retrieve();
        } catch (Throwable $e) {
            Craft::error("Document browser query failed for '{$target}': {$e->getMessage()}", 'typesense');

            return $this->asJson(['found' => 0, 'documents' => [], 'stats' => [], 'error' => Craft::t('typesense', 'The query could not be run.')]);
        }

        return $this->asJson([
            'found' => $result['found'] ?? 0,
            'documents' => array_map(static fn(array $hit): mixed => $hit['document'] ?? [], $result['hits'] ?? []),
            'stats' => [
                'numDocuments' => $stats['num_documents'] ?? 0,
                'target' => $target,
            ],
        ]);
    }

    /**
     * The unified playground screen. With no collection, it opens on the first
     * enabled collection; with one, it preselects it (the relevance editor deep
     * links here in diff mode).
     *
     * @param string|null $collection
     * @return Response
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionIndex(?string $collection = null): Response
    {
        $handles = array_keys(Typesense::$plugin->getCollectionRegistry()->getAll());
        $selected = ($collection !== null && in_array($collection, $handles, true))
            ? $collection
            : ($handles[0] ?? null);

        $this->getView()->registerAssetBundle(CpAsset::class);
        $this->getView()->registerAssetBundle(PlaygroundAsset::class);

        return $this->renderTemplate('typesense/playground/_index', [
            'collections' => $handles,
            'selected' => $selected,
            'defaultBody' => $selected !== null ? $this->_defaultBody($selected) : '{}',
            'initialFilterBy' => (string)$this->request->getQueryParam('filterBy', ''),
        ]);
    }

    /**
     * Runs the search-params body against a collection and returns the ranked
     * hits with their text-match scores, timing, and facet counts.
     *
     * @return Response
     * @throws \yii\web\BadRequestHttpException
     * @author CraftPulse
     */
    public function actionRun(): Response
    {
        $this->requirePostRequest();

        $handle = (string)$this->request->getBodyParam('collection', '');
        $params = $this->_boundBody($this->request->getBodyParam('body', ''));
        $result = Typesense::$plugin->getSearch()->search($handle, $params);

        return $this->asJson($this->_summarise($result) + [
            'facetCounts' => $result['facet_counts'] ?? [],
        ]);
    }

    /**
     * Returns the live schema (field names and types) of a collection for the
     * docs-explorer side pane.
     *
     * @return Response
     * @throws \yii\web\BadRequestHttpException
     * @author CraftPulse
     */
    public function actionSchema(): Response
    {
        $this->requirePostRequest();

        $handle = (string)$this->request->getBodyParam('collection', '');
        $collection = Typesense::$plugin->getCollectionRegistry()->get($handle);
        $client = Typesense::$plugin->getClient()->client();

        if ($collection === null || $client === null) {
            return $this->asJson(['fields' => []]);
        }

        $registry = Typesense::$plugin->getCollectionRegistry();
        $target = $registry->resolveName($collection, Craft::$app->getSites()->getCurrentSite()->id);

        try {
            $fields = $client->collections[$target]->retrieve()['fields'] ?? [];
        } catch (Throwable) {
            return $this->asJson(['fields' => []]);
        }

        return $this->asJson([
            'fields' => array_map(static fn(array $field): array => [
                'name' => (string)($field['name'] ?? ''),
                'type' => (string)($field['type'] ?? ''),
            ], $fields),
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Json-decodes and bounds the editor's search-params body into a safe param
     * set: q defaults to a wildcard, per_page and page are clamped, and every
     * other Typesense search parameter passes through (the query runs read-only
     * through the fail-soft search layer).
     *
     * @param mixed $raw
     * @return array<string, mixed>
     * @author CraftPulse
     */
    private function _boundBody(mixed $raw): array
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        if (!is_array($decoded)) {
            return ['q' => '*'];
        }

        $params = $decoded;
        $params['q'] = mb_substr(trim((string)($decoded['q'] ?? '')) ?: '*', 0, 512);

        if (isset($decoded['per_page'])) {
            $params['per_page'] = max(1, min(self::MAX_PER_PAGE, (int)$decoded['per_page']));
        }

        if (isset($decoded['page'])) {
            $params['page'] = max(1, (int)$decoded['page']);
        }

        return $params;
    }

    /**
     * The default search-params body for a collection: a wildcard query against
     * the collection's first string field, pretty-printed for the editor.
     *
     * @param string $handle
     * @return string
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    private function _defaultBody(string $handle): string
    {
        $queryBy = 'id';
        $collection = Typesense::$plugin->getCollectionRegistry()->get($handle);
        $client = Typesense::$plugin->getClient()->client();

        if ($collection !== null && $client !== null) {
            $registry = Typesense::$plugin->getCollectionRegistry();
            $target = $registry->resolveName($collection, Craft::$app->getSites()->getCurrentSite()->id);
            $queryBy = $this->_wildcardQueryBy($target, $client);
        }

        return (string)json_encode([
            'q' => '*',
            'query_by' => $queryBy,
            'per_page' => 20,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Computes the ranked diff between two search results: which document ids
     * entered, dropped, or moved rank.
     *
     * @param array<string, mixed> $current
     * @param array<string, mixed> $proposed
     * @return array<string, mixed>
     * @author CraftPulse
     */
    private function _diff(array $current, array $proposed): array
    {
        $currentIds = $this->_rankedIds($current);
        $proposedIds = $this->_rankedIds($proposed);
        $currentRank = array_flip($currentIds);

        $entered = array_values(array_diff($proposedIds, $currentIds));
        $dropped = array_values(array_diff($currentIds, $proposedIds));
        $moved = [];

        foreach ($proposedIds as $newRank => $id) {
            if (isset($currentRank[$id]) && $currentRank[$id] !== $newRank) {
                $moved[] = ['id' => $id, 'from' => $currentRank[$id] + 1, 'to' => $newRank + 1];
            }
        }

        return ['entered' => $entered, 'dropped' => $dropped, 'moved' => $moved];
    }

    /**
     * The ordered document ids of a search result.
     *
     * @param array<string, mixed> $result
     * @return array<int, string>
     * @author CraftPulse
     */
    private function _rankedIds(array $result): array
    {
        $ids = [];

        foreach ($result['hits'] ?? [] as $hit) {
            if (isset($hit['document']['id'])) {
                $ids[] = (string)$hit['document']['id'];
            }
        }

        return $ids;
    }

    /**
     * Reduces a raw Typesense response to the playground's ranked-hit summary.
     *
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     * @author CraftPulse
     */
    private function _summarise(array $result): array
    {
        $hits = [];

        foreach ($result['hits'] ?? [] as $rank => $hit) {
            $hits[] = [
                'rank' => $rank + 1,
                'id' => $hit['document']['id'] ?? null,
                'textMatch' => $hit['text_match'] ?? null,
                'document' => $hit['document'] ?? [],
            ];
        }

        return [
            'found' => $result['found'] ?? 0,
            'searchTimeMs' => $result['search_time_ms'] ?? null,
            'hits' => $hits,
        ];
    }

    /**
     * Picks a field to satisfy the required `query_by` on a wildcard document
     * listing: the first string field in the live schema, else the first field.
     *
     * @param string $target
     * @param \Typesense\Client $client
     * @return string
     * @author CraftPulse
     */
    private function _wildcardQueryBy(string $target, \Typesense\Client $client): string
    {
        try {
            $fields = $client->collections[$target]->retrieve()['fields'] ?? [];
        } catch (Throwable) {
            return 'id';
        }

        $first = null;

        foreach ($fields as $field) {
            $first ??= (string)($field['name'] ?? '');

            if (in_array($field['type'] ?? '', ['string', 'string[]'], true)) {
                return (string)$field['name'];
            }
        }

        return $first ?? 'id';
    }
}
