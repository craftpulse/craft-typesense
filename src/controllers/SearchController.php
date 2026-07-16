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
use craft\helpers\Html;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\helpers\FrontendTemplates;
use craftpulse\typesense\models\Settings;
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
    protected array|bool|int $allowAnonymous = ['results', 'track-event', 'ask'];

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

        /** @var \craftpulse\typesense\models\Settings $settings */
        $settings = Typesense::$plugin->getSettings();

        $variables = [
            'collection' => $handle,
            'options' => $options,
            'result' => $result,
            'urls' => $this->_resolveUrls($collection, $result),
            'endpoint' => $this->_endpointUrl($handle, $options),
            'trackEndpoint' => UrlHelper::actionUrl('typesense/search/track-event'),
            'csrfToken' => $request->getCsrfToken(),
            'theme' => $settings->frontendThemeConfig,
        ];

        // Each region renders through the override resolver, which enforces the
        // morph contract: if a project override drops the required id, it
        // re-renders the plugin default for that region and warns, so live
        // updates keep working.
        $results = FrontendTemplates::renderRegion('results', 'ts-results', $variables);
        $facetsHtml = FrontendTemplates::renderRegion('facets', 'ts-facets', $variables);
        $pagination = FrontendTemplates::renderRegion('pagination', 'ts-pagination', $variables);

        if ($isDatastar) {
            return $this->_streamFragments($results, $facetsHtml, $pagination, (int)($result['found'] ?? 0), $elapsedMs);
        }

        return $this->asRaw($results . $facetsHtml . $pagination);
    }

    /**
     * Answers a free-form question about a collection with a conversational (RAG)
     * response. Opt-in and off by default: a collection enables it with
     * `->ask('conversation-model-id')` in the fluent config, and must also be
     * searchable, or this 404s. Every request is a per-question LLM call (cost);
     * the throttle and on/off policy are the site developer's deployment decision
     * (the plugin ships a knob and a sane default, not a policy).
     *
     * On a server that streams conversations (v29+), a Datastar request gets the
     * answer token-by-token over SSE (patch-elements append into the answer
     * region). Otherwise, and for the no-JS path, it answers one-shot: the full
     * answer plus the cited hits, as text/html.
     *
     * @return Response
     * @throws NotFoundHttpException when the collection is unknown or not opted in
     * @throws BadRequestHttpException when the question is empty
     * @throws \yii\base\InvalidConfigException
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     * @throws \yii\base\Exception
     * @author CraftPulse
     */
    public function actionAsk(): Response
    {
        $request = $this->request;
        $handle = (string)$request->getParam('collection', '');
        $collection = Typesense::$plugin->getCollectionRegistry()->get($handle);

        // Trust boundary + opt-in: only a searchable collection that the author
        // explicitly opted into conversations (a model is configured) answers.
        if ($collection === null || !$collection->isSearchable() || !$collection->isAskEnabled()) {
            throw new NotFoundHttpException('Conversational search is not enabled for this collection.');
        }

        /** @var \craftpulse\typesense\models\Settings $settings */
        $settings = Typesense::$plugin->getSettings();

        if ($this->_askThrottled($settings)) {
            return $this->asJson(['ok' => false, 'error' => 'throttled']);
        }

        $signals = ($this->_isDatastarRequest() && $this->_hasSignalPayload()) ? ServerSentEventGenerator::readSignals() : [];
        $question = trim((string)($signals['q'] ?? $request->getParam('q', '')));
        $question = mb_substr($question, 0, max(1, $settings->askMaxQuestionLength));

        if ($question === '') {
            throw new BadRequestHttpException('A question is required.');
        }

        $modelId = (string)$collection->getConversationModelId();
        $queryBy = (string)$request->getParam('queryBy', '');
        $search = array_filter(['q' => $question, 'query_by' => $queryBy], static fn(string $value): bool => $value !== '');
        $streams = Typesense::$plugin->getClient()->getServerCapabilities()?->conversationStream() ?? false;

        if ($streams && $this->_isDatastarRequest()) {
            return $this->_streamAnswer($handle, $modelId, $search, $collection, $queryBy);
        }

        // One-shot: the full answer plus cited hits.
        $result = Typesense::$plugin->getSearch()->conversationalSearch($handle, $modelId, $search);
        $answer = (string)($result['conversation']['answer'] ?? '');
        $variables = [
            'answer' => $answer,
            'result' => $result,
            'urls' => $this->_resolveUrls($collection, $result),
            'theme' => $settings->frontendThemeConfig,
            'streaming' => false,
        ];
        $html = FrontendTemplates::renderRegion('ask-answer', 'ts-answer', $variables);

        if ($this->_isDatastarRequest()) {
            $body = (new PatchElements($html, ['selector' => '#ts-answer', 'mode' => ElementPatchMode::Outer]))->getOutput()
                . (new PatchSignals(Json::encode(['tsAsking' => false])))->getOutput();

            foreach (ServerSentEventGenerator::headers() as $name => $value) {
                $this->response->getHeaders()->set($name, $value);
            }

            return $this->asRaw($body);
        }

        return $this->asRaw($html);
    }

    // Private Methods
    // =========================================================================

    /**
     * A per-IP throttle for the conversational ask endpoint, backed by the cache,
     * with the window and cap taken from the plugin settings (a developer knob,
     * not a security boundary; front a public site with a real rate limiter).
     *
     * @param Settings $settings
     * @return bool whether this request is over the cap
     * @author CraftPulse
     */
    private function _askThrottled(Settings $settings): bool
    {
        $cache = Craft::$app->getCache();

        if ($cache === null) {
            return false;
        }

        $key = 'typesense:ask:' . md5((string)$this->request->getUserIP());
        $count = (int)$cache->get($key);

        if ($count >= max(1, $settings->askThrottlePerWindow)) {
            return true;
        }

        $cache->set($key, $count + 1, max(1, $settings->askThrottleWindowSeconds));

        return false;
    }

    /**
     * Streams a conversational answer token-by-token as Datastar SSE
     * patch-elements (append) into the answer region, then patches the cited
     * hits and clears the asking flag. This is a live stream: it sends the SSE
     * headers, echoes and flushes each chunk through the generator, and marks the
     * response sent so Craft does not send it a second time.
     *
     * @param string $handle
     * @param string $modelId
     * @param array<string, mixed> $search
     * @param Collection $collection
     * @param string $queryBy
     * @return Response
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     * @throws \yii\base\Exception
     * @author CraftPulse
     */
    private function _streamAnswer(string $handle, string $modelId, array $search, Collection $collection, string $queryBy): Response
    {
        $sse = new ServerSentEventGenerator();
        $sse->sendHeaders();

        // Seed the answer region, then append each streamed token in place.
        $sse->patchElements('<div id="ts-answer" class="ts-answer"></div>', ['selector' => '#ts-answer', 'mode' => ElementPatchMode::Outer]);

        Typesense::$plugin->getClient()->streamConversation($handle, $modelId, $search, static function(string $message) use ($sse): void {
            $sse->patchElements(Html::encode($message), ['selector' => '#ts-answer', 'mode' => ElementPatchMode::Append]);
        });

        // Cited hits: a cheap non-conversational search for the same question,
        // patched below the answer once the stream completes.
        $hits = Typesense::$plugin->getSearch()->frontendSearch($handle, ['q' => $search['q'] ?? '*', 'queryBy' => $queryBy, 'perPage' => 5]);
        $sources = FrontendTemplates::render('_ask-sources', [
            'result' => $hits,
            'urls' => $this->_resolveUrls($collection, $hits),
        ]);
        $sse->patchElements($sources, ['selector' => '#ts-answer-sources', 'mode' => ElementPatchMode::Outer]);
        $sse->patchSignals(Json::encode(['tsAsking' => false]));

        // We streamed the response directly; stop Craft sending it again.
        $this->response->isSent = true;

        return $this->response;
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
