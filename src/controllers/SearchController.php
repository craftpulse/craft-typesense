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
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\View;
use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\Typesense;
use starfederation\datastar\Consts;
use starfederation\datastar\enums\ElementPatchMode;
use starfederation\datastar\events\PatchElements;
use starfederation\datastar\events\PatchSignals;
use starfederation\datastar\ServerSentEventGenerator;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The front-end search fragment endpoint.
 *
 * Runs a search entirely server-side through the P6 search layer (the admin key
 * never approaches the browser) and returns the results, facet sidebar, and
 * pagination as HTML fragments. Each fragment is a top-level element with a
 * stable id, so Datastar morphs it into the page in place. The endpoint is
 * anonymous because it serves the public front end; every parameter is bounded
 * in the search service, and the endpoint only ever reads.
 *
 * Fragments render the `_typesense/*` site templates, which a project overrides
 * by dropping a same-named template under `templates/_typesense/`.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class SearchController extends Controller
{
    // Constants
    // =========================================================================

    /**
     * @var int The per-IP event cap within the throttle window.
     */
    public const MAX_EVENTS_PER_WINDOW = 60;

    /**
     * @var int The throttle window, in seconds.
     */
    public const THROTTLE_WINDOW = 60;

    // Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = ['results', 'track-event'];

    // Public Methods
    // =========================================================================

    /**
     * Records a front-end analytics event (a click or a conversion) server-side,
     * so the admin key never reaches the browser. Fail-soft: an unconfigured or
     * opted-out install records nothing and still returns cleanly. Hardened
     * against abuse: only click/conversion types, the event name must be a
     * registered analytics rule, the user id is derived from the session (never
     * the posted value) and only when user-id association is opted in, q/docId
     * are length-bounded, and a cheap per-IP cache throttle caps the rate.
     *
     * @return Response
     * @throws \yii\web\BadRequestHttpException
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionTrackEvent(): Response
    {
        $this->requirePostRequest();

        if ($this->_throttled()) {
            return $this->asJson(['ok' => false]);
        }

        $request = $this->request;
        $type = in_array($request->getParam('type'), ['click', 'conversion'], true)
            ? (string)$request->getParam('type')
            : 'click';

        $analytics = Typesense::$plugin->getAnalytics();

        // The event name must be a registered analytics rule, else the event is
        // meaningless (and untrusted). Fall back to the type when it is not.
        $name = (string)$request->getParam('name', '');
        $ruleNames = array_column($analytics->rules(), 'name');

        if (!in_array($name, $ruleNames, true)) {
            $name = $type;
        }

        $data = array_filter([
            'doc_id' => mb_substr((string)$request->getParam('docId', ''), 0, 128),
            'q' => mb_substr((string)$request->getParam('q', ''), 0, 256),
        ], static fn(string $value): bool => $value !== '');

        // User id is derived server-side from the session, never trusted from the
        // request, and only attached when the operator opts in.
        $userId = Craft::$app->getUser()->getId();

        if ($userId !== null) {
            $data['user_id'] = (string)$userId;
        }

        $analytics->sendEvent($type, $name, $data);

        return $this->asJson(['ok' => true]);
    }

    /**
     * Renders the results, facets, and pagination fragments for a search.
     *
     * Two transports share this action. A Datastar request (its fetch sends the
     * `Datastar-Request` header and nests the client signals under a `datastar`
     * query param) gets an SSE response: one `datastar-patch-elements` frame per
     * region morphed in place, plus a `datastar-patch-signals` frame carrying the
     * found count, timing, and the searching flag, so counts update without a DOM
     * morph. Any other request (a no-JS form GET, a crawler, a direct hit) gets
     * the plain `text/html` concatenation it always did, reading top-level query
     * params. The two paths return identical result data; only the wrapper
     * differs.
     *
     * @return Response
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     * @throws \yii\base\Exception
     * @throws NotFoundHttpException
     * @throws BadRequestHttpException
     * @author CraftPulse
     */
    public function actionResults(): Response
    {
        $request = $this->request;
        $handle = (string)$request->getParam('collection', '');

        // Trust boundary: the anonymous, server-keyed proxy may only reach a
        // collection that is explicitly opted into front-end search. Unknown or
        // unflagged handles 404 here (no raw-client fallback on this path).
        $collection = Typesense::$plugin->getCollectionRegistry()->get($handle);

        if ($collection === null || !$collection->isSearchable()) {
            throw new NotFoundHttpException('Collection not found.');
        }

        // Datastar nests the client signals (q, page, facets) under a single
        // `datastar` param; readSignals() decodes it (query param on GET, JSON
        // body otherwise). It is only read when a Datastar payload is actually
        // present (readSignals() reads $_GET['datastar'] unguarded, which would
        // trip Craft's error handler on a plain request). The no-JS path sends no
        // signals, so each value falls back to its top-level query param. The
        // collection and the fixed config (queryBy, facetBy, perPage) always
        // travel as top-level params baked into the endpoint URL, never as
        // user-controlled signals.
        $isDatastar = $this->_isDatastarRequest();
        $signals = ($isDatastar && $this->_hasSignalPayload()) ? ServerSentEventGenerator::readSignals() : [];
        $facets = $signals['facets'] ?? $request->getParam('facets', []);

        $options = [
            'q' => (string)($signals['q'] ?? $request->getParam('q', '')),
            'facetBy' => (string)$request->getParam('facetBy', ''),
            'facets' => is_array($facets) ? array_values(array_filter($facets, 'is_scalar')) : [$facets],
            'sort' => (string)$request->getParam('sort', ''),
            'queryBy' => (string)$request->getParam('queryBy', ''),
            'page' => max(1, (int)($signals['page'] ?? $request->getParam('page', 1))),
            'perPage' => (int)$request->getParam('perPage', 20),
        ];

        // Field-name allowlist: every field referenced by query_by, sort_by, or
        // facet_by must be a declared field of this collection (not just value
        // sanitisation). Anything else is rejected.
        $this->_assertDeclaredFields($collection, $options);

        $startedAt = microtime(true);
        $result = Typesense::$plugin->getSearch()->frontendSearch($handle, $options);
        $elapsedMs = (int)round((microtime(true) - $startedAt) * 1000);

        $variables = [
            'collection' => $handle,
            'options' => $options,
            'result' => $result,
            'urls' => $this->_resolveUrls($collection, $result),
            'endpoint' => $this->_endpointUrl($handle, $options),
            'trackEndpoint' => UrlHelper::actionUrl('typesense/search/track-event'),
            'csrfToken' => $request->getCsrfToken(),
        ];

        $view = Craft::$app->getView();
        $results = $view->renderTemplate('_typesense/results', $variables, View::TEMPLATE_MODE_SITE);
        $facetsHtml = $view->renderTemplate('_typesense/facets', $variables, View::TEMPLATE_MODE_SITE);
        $pagination = $view->renderTemplate('_typesense/pagination', $variables, View::TEMPLATE_MODE_SITE);

        // The morph contract: each region must carry its stable id or Datastar
        // has nothing to patch. A project template override that drops the id is
        // a silent no-morph, so name it loudly in the logs.
        $this->_assertRegionContract($results, 'ts-results', '_typesense/results');
        $this->_assertRegionContract($facetsHtml, 'ts-facets', '_typesense/facets');
        $this->_assertRegionContract($pagination, 'ts-pagination', '_typesense/pagination');

        if ($isDatastar) {
            return $this->_streamFragments($results, $facetsHtml, $pagination, (int)($result['found'] ?? 0), $elapsedMs);
        }

        return $this->asRaw($results . $facetsHtml . $pagination);
    }

    // Private Methods
    // =========================================================================

    /**
     * Warns when a rendered region has lost its stable id, which is the Datastar
     * morph target. A project template override that drops the id renders fine on
     * first load but silently stops morphing, so this names the region and its
     * required id in the logs rather than failing a public request.
     *
     * @param string $html
     * @param string $id the required element id (the morph selector, without the #)
     * @param string $template the overridable template path, for the log message
     * @return void
     * @author CraftPulse
     */
    private function _assertRegionContract(string $html, string $id, string $template): void
    {
        if (str_contains($html, 'id="' . $id . '"')) {
            return;
        }

        Craft::warning(
            "The Typesense search fragment \"{$template}\" is missing the required id=\"{$id}\". "
            . 'Datastar cannot morph a region without it, so live updates will not apply. '
            . 'Keep the id on the top-level element when overriding this template.',
            __METHOD__,
        );
    }

    /**
     * Builds the fragment endpoint URL with the fixed search config baked in as
     * query params. The collection and the queryBy/facetBy/perPage config are
     * not user-controlled signals, so they travel on the URL; Datastar appends
     * the client signals (q, page, facets) under its own `datastar` param.
     *
     * @param string $handle
     * @param array<string, mixed> $options
     * @return string
     * @author CraftPulse
     */
    private function _endpointUrl(string $handle, array $options): string
    {
        return UrlHelper::actionUrl('typesense/search/results', array_filter([
            'collection' => $handle,
            'queryBy' => (string)$options['queryBy'],
            'facetBy' => (string)$options['facetBy'],
            'perPage' => (string)$options['perPage'],
        ], static fn(string $value): bool => $value !== ''));
    }

    /**
     * Whether the request carries a Datastar signal payload to decode: the
     * `datastar` query param on a GET or DELETE, or a request body otherwise.
     * Guards the SDK's readSignals(), which reads $_GET['datastar'] unguarded.
     *
     * @return bool
     * @author CraftPulse
     */
    private function _hasSignalPayload(): bool
    {
        if ($this->request->getIsGet() || $this->request->getIsDelete()) {
            return $this->request->getQueryParam(Consts::DATASTAR_KEY) !== null;
        }

        return $this->request->getRawBody() !== '';
    }

    /**
     * Whether this is a Datastar fetch (its client sends the `Datastar-Request`
     * header on every request), which selects the SSE transport.
     *
     * @return bool
     * @author CraftPulse
     */
    private function _isDatastarRequest(): bool
    {
        return $this->request->getHeaders()->has('Datastar-Request');
    }

    /**
     * Streams the three region fragments as Datastar SSE patch-elements events
     * (each morphed in place by its stable id) plus a patch-signals event
     * carrying the found count, elapsed milliseconds, and the cleared searching
     * flag. The body is assembled and returned as one text/event-stream response
     * (a search morph is a short, one-shot patch, not a long-lived stream), so
     * Craft sends the SSE headers and body through its normal response pipeline.
     *
     * @param string $results
     * @param string $facets
     * @param string $pagination
     * @param int $found
     * @param int $elapsedMs
     * @return Response
     * @author CraftPulse
     */
    private function _streamFragments(string $results, string $facets, string $pagination, int $found, int $elapsedMs): Response
    {
        $body = (new PatchElements($results, ['selector' => '#ts-results', 'mode' => ElementPatchMode::Outer]))->getOutput()
            . (new PatchElements($facets, ['selector' => '#ts-facets', 'mode' => ElementPatchMode::Outer]))->getOutput()
            . (new PatchElements($pagination, ['selector' => '#ts-pagination', 'mode' => ElementPatchMode::Outer]))->getOutput()
            . (new PatchSignals(Json::encode([
                'tsFound' => $found,
                'tsMs' => $elapsedMs,
                'tsSearching' => false,
            ])))->getOutput();

        $headers = $this->response->getHeaders();

        foreach (ServerSentEventGenerator::headers() as $name => $value) {
            $headers->set($name, $value);
        }

        return $this->asRaw($body);
    }

    /**
     * Resolves the element URL for each hit, batched into one element query per
     * site, so the results fragment can link each hit to its element (rather than
     * to nothing) without an N+1 lookup.
     *
     * @param Collection $collection
     * @param array<string, mixed> $result
     * @return array<int, string> Element URLs keyed by element id.
     * @author CraftPulse
     */
    private function _resolveUrls(Collection $collection, array $result): array
    {
        $idsBySite = [];

        foreach ($result['hits'] ?? [] as $hit) {
            $document = $hit['document'] ?? [];
            $elementId = (int)($document['elementId'] ?? 0);
            $siteId = (int)($document['siteId'] ?? Craft::$app->getSites()->getCurrentSite()->id);

            if ($elementId > 0) {
                $idsBySite[$siteId][] = $elementId;
            }
        }

        $urls = [];
        $type = $collection->getElementType();

        foreach ($idsBySite as $siteId => $ids) {
            $elements = $type::find()->id($ids)->siteId($siteId)->status(null)->all();

            foreach ($elements as $element) {
                $url = $element->getUrl();

                if ($url !== null) {
                    $urls[(int)$element->id] = $url;
                }
            }
        }

        return $urls;
    }

    /**
     * A cheap per-IP throttle for the anonymous event endpoint, backed by the
     * cache: at most `MAX_EVENTS_PER_WINDOW` events per `THROTTLE_WINDOW` seconds
     * per client IP. Over the cap, the event is dropped. This is best-effort
     * abuse limiting, not a security boundary (put a real rate limiter, for
     * example a CDN rule, in front of any high-traffic install).
     *
     * @return bool whether this request is over the cap
     * @author CraftPulse
     */
    private function _throttled(): bool
    {
        $cache = Craft::$app->getCache();

        if ($cache === null) {
            return false;
        }

        $key = 'typesense:track:' . md5((string)$this->request->getUserIP());
        $count = (int)$cache->get($key);

        if ($count >= self::MAX_EVENTS_PER_WINDOW) {
            return true;
        }

        $cache->set($key, $count + 1, self::THROTTLE_WINDOW);

        return false;
    }

    /**
     * Asserts every field name referenced by the anonymous request's query_by,
     * sort_by, and facet_by is a declared field of the collection (a field-name
     * allowlist, so a visitor cannot pivot the server-keyed query onto columns
     * the collection never declared).
     *
     * @param Collection $collection
     * @param array<string, mixed> $options
     * @return void
     * @throws BadRequestHttpException when a referenced field is not declared
     * @author CraftPulse
     */
    private function _assertDeclaredFields(Collection $collection, array $options): void
    {
        $allowed = ['id', 'elementId', 'siteId'];

        foreach ($collection->getFields() as $field) {
            $allowed[] = (string)($field->toArray()['name'] ?? '');
        }

        $referenced = [];

        foreach (explode(',', (string)$options['queryBy']) as $name) {
            $referenced[] = trim($name);
        }

        foreach (explode(',', (string)$options['sort']) as $clause) {
            $referenced[] = trim(explode(':', trim($clause))[0]);
        }

        $referenced[] = trim((string)$options['facetBy']);

        foreach ($referenced as $name) {
            if ($name !== '' && !in_array($name, $allowed, true)) {
                throw new BadRequestHttpException("Unknown search field \"{$name}\".");
            }
        }
    }
}
