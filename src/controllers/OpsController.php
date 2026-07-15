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
use craftpulse\typesense\controllers\base\ProController;
use craftpulse\typesense\Typesense;
use yii\web\Response;

/**
 * The Pro ops actions: point-in-time snapshot, database compaction, and cache
 * clear against the Typesense server. Reading the server's metrics and stats is
 * Free (surfaced in the utility); these state-changing operations are Pro and
 * gated by `typesense:manageCollections` (the index-management permission), and
 * edition- and permission-checked server-side.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class OpsController extends ProController
{
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

        $this->requirePermission(CollectionsController::PERMISSION_MANAGE_COLLECTIONS);

        return true;
    }

    /**
     * Clears the server's query cache.
     *
     * @return Response|null
     * @throws \yii\web\BadRequestHttpException
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionClearCache(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(CollectionsController::PERMISSION_MANAGE_COLLECTIONS);

        $response = Typesense::$plugin->getClient()->request('POST', '/operations/cache/clear');

        if (($response['success'] ?? false) !== true) {
            return $this->asFailure(Craft::t('typesense', 'Could not clear the cache.'));
        }

        return $this->asSuccess(Craft::t('typesense', 'Cache cleared.'), ['redirect' => 'utilities/typesense']);
    }

    /**
     * Compacts the on-disk database (non-blocking on the server).
     *
     * @return Response|null
     * @throws \yii\web\BadRequestHttpException
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionCompact(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(CollectionsController::PERMISSION_MANAGE_COLLECTIONS);

        $response = Typesense::$plugin->getClient()->request('POST', '/operations/db/compact');

        if (($response['success'] ?? false) !== true) {
            return $this->asFailure(Craft::t('typesense', 'Could not compact the database.'));
        }

        return $this->asSuccess(Craft::t('typesense', 'Database compaction started.'), ['redirect' => 'utilities/typesense']);
    }

    /**
     * Takes a point-in-time snapshot of the data directory to a server path.
     *
     * @return Response|null
     * @throws \yii\web\BadRequestHttpException
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionSnapshot(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(CollectionsController::PERMISSION_MANAGE_COLLECTIONS);

        $path = trim((string)$this->request->getBodyParam('snapshotPath', ''));

        if ($path === '') {
            return $this->asFailure(Craft::t('typesense', 'A snapshot path is required.'));
        }

        $response = Typesense::$plugin->getClient()->request('POST', '/operations/snapshot', null, null, [
            'snapshot_path' => $path,
        ]);

        if (($response['success'] ?? false) !== true) {
            return $this->asFailure(Craft::t('typesense', 'Could not start the snapshot.'));
        }

        return $this->asSuccess(Craft::t('typesense', 'Snapshot started to {path}.', ['path' => $path]), [
            'redirect' => 'utilities/typesense',
        ]);
    }
}
