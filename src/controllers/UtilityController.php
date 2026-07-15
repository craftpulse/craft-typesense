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
use craft\web\Controller;
use craftpulse\typesense\Typesense;
use yii\web\Response;

/**
 * Operational actions behind the Typesense CP utility: full sync, flush, and the
 * suspend toggle. Gated by typesense:manageSettings.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class UtilityController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @throws \yii\web\ForbiddenHttpException
     */
    public function beforeAction($action): bool
    {
        $this->requirePermission(OpsController::PERMISSION_MANAGE_OPS);

        return parent::beforeAction($action);
    }

    /**
     * Queues a full sync of all collections.
     *
     * @return Response|null
     * @author CraftPulse
     */
    public function actionSync(): ?Response
    {
        $this->requirePostRequest();
        Typesense::$plugin->getSync()->syncAll();
        $this->setSuccessFlash(Craft::t('typesense', 'Sync queued.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Queues a flush (delete and re-sync) of all collections.
     *
     * @return Response|null
     * @author CraftPulse
     */
    public function actionFlush(): ?Response
    {
        $this->requirePostRequest();
        Typesense::$plugin->getSync()->flushAll();
        $this->setSuccessFlash(Craft::t('typesense', 'Flush queued.'));

        return $this->redirectToPostedUrl();
    }

    /**
     * Toggles the sync-suspend switch: globally, or for one collection when a
     * `collection` body param is posted. Suspend is runtime database state, so
     * this works even when allowAdminChanges is disabled.
     *
     * @return Response|null
     * @author CraftPulse
     */
    public function actionToggleSuspend(): ?Response
    {
        $this->requirePostRequest();

        $collection = $this->request->getBodyParam('collection');
        $collection = is_string($collection) && $collection !== '' ? $collection : null;

        $suspended = Typesense::$plugin->getSyncSuspend()->toggle($collection);

        $this->setSuccessFlash($suspended
            ? Craft::t('typesense', 'Sync suspended.')
            : Craft::t('typesense', 'Sync resumed.'));

        return $this->redirectToPostedUrl();
    }
}
