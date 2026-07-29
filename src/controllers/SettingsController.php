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
use craft\config\GeneralConfig;
use craft\web\Controller;
use craftpulse\typesense\models\Settings;
use craftpulse\typesense\services\Audit;
use craftpulse\typesense\Typesense;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Manages the plugin's connection and behaviour settings.
 *
 * Access is gated by the typesense:manage-settings permission (never requireAdmin),
 * so a site can delegate the screen to a non-admin group. Writability is a
 * separate axis: when allowAdminChanges is false the screen renders read-only and
 * the save action fails closed with a 403.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class SettingsController extends Controller
{
    // Constants
    // =========================================================================

    /**
     * @var string The permission that gates the settings screen.
     */
    public const PERMISSION_MANAGE_SETTINGS = 'typesense:manage-settings';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @throws ForbiddenHttpException
     */
    public function beforeAction($action): bool
    {
        // Who may be on the screen: covers both edit and save.
        $this->requirePermission(self::PERMISSION_MANAGE_SETTINGS);

        return parent::beforeAction($action);
    }

    /**
     * Renders the settings screen.
     *
     * @return Response
     * @author CraftPulse
     */
    public function actionEdit(): Response
    {
        /** @var GeneralConfig $general */
        $general = Craft::$app->getConfig()->getGeneral();

        return $this->renderTemplate('typesense/settings/_edit', [
            'plugin' => Typesense::$plugin,
            'settings' => Typesense::$plugin->getSettings(),
            'status' => Typesense::$plugin->getClient()->getStatus(),
            'readOnly' => !$general->allowAdminChanges,
        ]);
    }

    /**
     * Saves the settings. Fails closed with a 403 when writes are disabled.
     *
     * @return Response|null
     * @throws ForbiddenHttpException
     * @author CraftPulse
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        /** @var GeneralConfig $general */
        $general = Craft::$app->getConfig()->getGeneral();

        if (!$general->allowAdminChanges) {
            throw new ForbiddenHttpException('Typesense settings are read-only because allowAdminChanges is disabled in this environment.');
        }

        $plugin = Typesense::$plugin;
        /** @var Settings $settings */
        $settings = $plugin->getSettings();
        $settings->setAttributes($this->request->getBodyParam('settings', []), false);

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray())) {
            $this->setFailFlash(Craft::t('typesense', 'Could not save settings.'));

            return $this->renderTemplate('typesense/settings/_edit', [
                'plugin' => $plugin,
                'settings' => $settings,
                'status' => $plugin->getClient()->getStatus(),
                'readOnly' => false,
            ]);
        }

        Typesense::$plugin->getAudit()->record(Audit::EVENT_SETTINGS_SAVED);
        $this->setSuccessFlash(Craft::t('typesense', 'Settings saved.'));

        return $this->redirectToPostedUrl();
    }
}
