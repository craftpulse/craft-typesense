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
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The Pro search playground and document browser.
 *
 * The playground is a live query console per collection: tunable query params
 * run against the real server through the P6 search layer (the admin key never
 * leaves the server, every param is bounded), and the ranked hits come back
 * with their text-match scores and timing. A diff mode runs the query twice,
 * current versus a pending overlay of edits, and reports how the ranking would
 * change before anything is saved. The document browser is a read-only, paged,
 * filterable view of the actual indexed documents.
 *
 * Gated by the edition (via [[ProController]]) and, because both surfaces tune
 * and inspect a collection, the `typesense:manageCollections` permission.
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
     * @var int The largest page size any playground or browser query will honour.
     */
    public const MAX_PER_PAGE = 250;

    /**
     * @var string The permission that gates the search playground and document
     * browser (diagnostics surfaces).
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
     * The document browser screen for a collection.
     *
     * @param string $collection
     * @return Response
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionBrowse(string $collection): Response
    {
        $this->_requireCollection($collection);
        $this->getView()->registerAssetBundle(CpAsset::class);
        $this->getView()->registerAssetBundle(PlaygroundAsset::class);

        return $this->renderTemplate('typesense/playground/_browse', [
            'handle' => $collection,
        ]);
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
        $base = $this->_searchParams($this->request->getBodyParam('params', []));
        $overlay = $this->request->getBodyParam('overlay', []);
        $pending = is_array($overlay) ? array_merge($base, $this->_searchParams($overlay)) : $base;

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
     * Returns a page of the collection's indexed documents.
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
     * The collection picker for the playground and browser.
     *
     * @return Response
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionIndex(): Response
    {
        return $this->renderTemplate('typesense/playground/index', [
            'collections' => array_keys(Typesense::$plugin->getCollectionRegistry()->getAll()),
        ]);
    }

    /**
     * The search playground screen for a collection.
     *
     * @param string $collection
     * @return Response
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionQuery(string $collection): Response
    {
        $this->_requireCollection($collection);
        $this->getView()->registerAssetBundle(CpAsset::class);
        $this->getView()->registerAssetBundle(PlaygroundAsset::class);

        return $this->renderTemplate('typesense/playground/_query', [
            'handle' => $collection,
            'initialFilterBy' => (string)$this->request->getQueryParam('filterBy', ''),
            'initialQuery' => (string)$this->request->getQueryParam('q', ''),
        ]);
    }

    /**
     * Runs a tuned query against a collection and returns the ranked hits with
     * their text-match scores, timing, and facet counts.
     *
     * @return Response
     * @throws \yii\web\BadRequestHttpException
     * @author CraftPulse
     */
    public function actionRun(): Response
    {
        $this->requirePostRequest();

        $handle = (string)$this->request->getBodyParam('collection', '');
        $params = $this->_searchParams($this->request->getBodyParam('params', []));
        $result = Typesense::$plugin->getSearch()->search($handle, $params);

        return $this->asJson($this->_summarise($result) + [
            'facetCounts' => $result['facet_counts'] ?? [],
        ]);
    }

    // Private Methods
    // =========================================================================

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
        $proposedRank = array_flip($proposedIds);

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
     * Requires a known collection or throws.
     *
     * @param string $handle
     * @return void
     * @throws NotFoundHttpException
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    private function _requireCollection(string $handle): void
    {
        if (Typesense::$plugin->getCollectionRegistry()->get($handle) === null) {
            throw new NotFoundHttpException('Collection not found.');
        }
    }

    /**
     * Bounds and normalises the tunable search params from the request into a
     * safe Typesense param set.
     *
     * @param mixed $raw
     * @return array<string, mixed>
     * @author CraftPulse
     */
    private function _searchParams(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $params = [
            'q' => mb_substr(trim((string)($raw['q'] ?? '')) ?: '*', 0, 512),
            'page' => max(1, (int)($raw['page'] ?? 1)),
            'per_page' => max(1, min(self::MAX_PER_PAGE, (int)($raw['perPage'] ?? 20))),
        ];

        $strings = [
            'queryBy' => 'query_by',
            'queryByWeights' => 'query_by_weights',
            'filterBy' => 'filter_by',
            'sortBy' => 'sort_by',
            'facetBy' => 'facet_by',
            'preset' => 'preset',
        ];

        foreach ($strings as $from => $to) {
            $value = trim((string)($raw[$from] ?? ''));

            if ($value !== '') {
                $params[$to] = mb_substr($value, 0, 512);
            }
        }

        if (isset($raw['numTypos'])) {
            $params['num_typos'] = max(0, min(4, (int)$raw['numTypos']));
        }

        if (isset($raw['dropTokensThreshold'])) {
            $params['drop_tokens_threshold'] = max(0, min(100, (int)$raw['dropTokensThreshold']));
        }

        if (isset($raw['prefix'])) {
            $params['prefix'] = (bool)$raw['prefix'] ? 'true' : 'false';
        }

        return $params;
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
