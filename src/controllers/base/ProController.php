<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\controllers\base;

use craft\web\Controller;
use craftpulse\typesense\Typesense;
use yii\base\InvalidConfigException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Base controller for every Pro control-panel screen. It enforces the edition
 * gate server-side: a request to any Pro action fails closed when the plugin is
 * not running the Pro edition, so a crafted POST cannot reach a Pro action just
 * because the nav that would launch it is hidden. Concrete controllers add
 * their permission gate on top.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
abstract class ProController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @param \yii\base\Action $action
     * @return bool
     * @throws ForbiddenHttpException if the plugin is not running the Pro edition
     * @throws InvalidConfigException
     * @throws \yii\web\BadRequestHttpException
     */
    public function beforeAction($action): bool
    {
        if (!Typesense::$plugin->getIsPro()) {
            throw new ForbiddenHttpException('This feature requires Typesense Pro.');
        }

        return parent::beforeAction($action);
    }

    // Protected Methods
    // =========================================================================

    /**
     * Returns a VueAdminTable tableDataEndpoint JSON payload for an in-memory
     * list: a case-insensitive search across the given keys, then the requested
     * page slice, plus the total (which drives the count footer). Server-side
     * data lists (synonyms, curation rules) grow unbounded per collection, so
     * they page here rather than shipping every row inline.
     *
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, string> $searchKeys the row keys the search term matches against
     * @return Response
     * @author CraftPulse
     */
    protected function paginatedTableData(array $rows, array $searchKeys): Response
    {
        $search = trim((string)$this->request->getParam('search', ''));

        if ($search !== '') {
            $needle = mb_strtolower($search);
            $rows = array_values(array_filter($rows, static function(array $row) use ($needle, $searchKeys): bool {
                foreach ($searchKeys as $key) {
                    if (str_contains(mb_strtolower((string)($row[$key] ?? '')), $needle)) {
                        return true;
                    }
                }

                return false;
            }));
        }

        $total = count($rows);
        $page = max(1, (int)$this->request->getParam('page', 1));
        $perPage = max(1, (int)$this->request->getParam('per_page', 100));

        return $this->asJson([
            'data' => array_slice($rows, ($page - 1) * $perPage, $perPage),
            'pagination' => ['total' => $total],
        ]);
    }
}
