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
use craft\helpers\UrlHelper;
use craft\web\Controller;
use craft\web\View;
use craftpulse\typesense\Typesense;
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
     * opted-out install records nothing and still returns cleanly. Only the click
     * and conversion event types are accepted from the front end.
     *
     * @return Response
     * @throws \yii\web\BadRequestHttpException
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionTrackEvent(): Response
    {
        $this->requirePostRequest();

        // Read from GET+POST: the Datastar binding carries the values as query
        // params on the POST (no per-hit signal store on the results fragment).
        $request = $this->request;
        $type = (string)$request->getParam('type', 'click');
        $type = in_array($type, ['click', 'conversion'], true) ? $type : 'click';

        $data = array_filter([
            'doc_id' => (string)$request->getParam('docId', ''),
            'q' => (string)$request->getParam('q', ''),
            'user_id' => (string)$request->getParam('userId', ''),
        ], static fn(string $value): bool => $value !== '');

        Typesense::$plugin->getAnalytics()->sendEvent(
            $type,
            (string)$request->getParam('name', $type),
            $data,
        );

        return $this->asJson(['ok' => true]);
    }

    /**
     * Renders the results, facets, and pagination fragments for a search.
     *
     * @return Response
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     * @throws \yii\base\Exception
     * @author CraftPulse
     */
    public function actionResults(): Response
    {
        $request = $this->request;
        $collection = (string)$request->getParam('collection', '');
        $facets = $request->getParam('facets', []);

        $options = [
            'q' => (string)$request->getParam('q', ''),
            'facetBy' => (string)$request->getParam('facetBy', ''),
            'facets' => is_array($facets) ? $facets : [$facets],
            'sort' => (string)$request->getParam('sort', ''),
            'queryBy' => (string)$request->getParam('queryBy', ''),
            'page' => (int)$request->getParam('page', 1),
            'perPage' => (int)$request->getParam('perPage', 20),
        ];

        $result = $collection !== ''
            ? Typesense::$plugin->getSearch()->frontendSearch($collection, $options)
            : [];

        $variables = [
            'collection' => $collection,
            'options' => $options,
            'result' => $result,
            'endpoint' => UrlHelper::actionUrl('typesense/search/results'),
        ];

        $view = Craft::$app->getView();
        $html = $view->renderTemplate('_typesense/results', $variables, View::TEMPLATE_MODE_SITE)
            . $view->renderTemplate('_typesense/facets', $variables, View::TEMPLATE_MODE_SITE)
            . $view->renderTemplate('_typesense/pagination', $variables, View::TEMPLATE_MODE_SITE);

        return $this->asRaw($html);
    }
}
