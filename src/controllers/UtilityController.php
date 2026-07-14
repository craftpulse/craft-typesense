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
use craftpulse\typesense\models\Settings;
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
        $this->requirePermission(SettingsController::PERMISSION_MANAGE_SETTINGS);

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
     * Toggles the global sync-suspend switch.
     *
     * @return Response|null
     * @author CraftPulse
     */
    public function actionToggleSuspend(): ?Response
    {
        $this->requirePostRequest();

        $plugin = Typesense::$plugin;
        /** @var Settings $settings */
        $settings = $plugin->getSettings();
        $settings->syncSuspended = !$settings->syncSuspended;
        Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray());

        $this->setSuccessFlash($settings->syncSuspended
            ? Craft::t('typesense', 'Sync suspended.')
            : Craft::t('typesense', 'Sync resumed.'));

        return $this->redirectToPostedUrl();
    }
}
