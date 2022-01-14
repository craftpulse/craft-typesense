<?php
/**
 * Typesense plugin for Craft CMS 3.x
 *
 * @link      https://percipio.london/
 * @copyright Copyright (c) 2022 Percipio Global Ltd.
 * @license   https://percipio.london/license
 */

namespace percipiolondon\typesense\controllers;

use Craft;
use craft\helpers\UrlHelper;
use craft\models\Sites;
use craft\web\Controller;

use yii\base\InvalidConfigException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

use percipiolondon\typesense\Typesense;

/**
 * @author    Percipio.London
 * @package   Seomatic
 * @since     3.0.0
 */

class SettingsController extends Controller
{
    // Constants
    // =========================================================================

    // Public Methods
    // =========================================================================

    /**
     * Collections display
     *
     * @param string|null $siteHandle
     *
     * @return Response The rendered result
     * @throws NotFoundHttpException
     * @throws \yii\web\ForbiddenHttpException
     */
    public function actionCollections(string $siteHandle = null): Response
    {
        $variables = [];

        $pluginName = Typesense::$settings->pluginName;
        $templateTitle = Craft::t('typesense', 'Collections');

        $variables['controllerHandle'] = 'collections';
        $variables['pluginName'] = Typesense::$settings->pluginName;
        $variables['title'] = $templateTitle;
        $variables['docTitle'] = "{$pluginName} - {$templateTitle}";
        $variables['sections'] = Craft::$app->getSections()->getAllSections();
        $variables['selectedSubnavItem'] = 'collections';

        // Render the template
        return $this->renderTemplate('typesense/collections/index', $variables);
    }

    /**
     * Dashboard display
     *
     * @param string|null $siteHandle
     * @param bool        $showWelcome
     *
     * @return Response The rendered result
     * @throws NotFoundHttpException
     * @throws \yii\web\ForbiddenHttpException
     */
    public function actionDashboard(string $siteHandle = null, bool $showWelcome = false): Response
    {
        $variables = [];

        $pluginName = Typesense::$settings->pluginName;
        $templateTitle = Craft::t('typesense', 'Dashboard');

        $variables['controllerHandle'] = 'dashboard';
        $variables['pluginName'] = Typesense::$settings->pluginName;
        $variables['title'] = $templateTitle;
        $variables['docTitle'] = "{$pluginName} - {$templateTitle}";
        $variables['selectedSubnavItem'] = 'dashboard';
        $variables['showWelcome'] = $showWelcome;

        // Render the template
        return $this->renderTemplate('typesense/dashboard/index', $variables);
    }

    /**
     * Settings display
     *
     *
     * @return Response The rendered result
     * @throws NotFoundHttpException
     * @throws \yii\web\ForbiddenHttpException
     */
    public function actionPlugin(): Response
    {
        $variables = [];
        $pluginName = Typesense::$settings->pluginName;
        $templateTitle = Craft::t('typesense', 'Plugin Settings');

        $variables['fullPageForm'] = true;
        $variables['pluginName'] = Typesense::$settings->pluginName;
        $variables['title'] = $templateTitle;
        $variables['docTitle'] = "{$pluginName} - {$templateTitle}";
        $variables['selectedSubnavItem'] = 'plugin';
        $variables['settings'] = Typesense::$settings;

        // Render the template
        return $this->renderTemplate('typesense/settings/typesense-settings', $variables);
    }

    /**
     * Saves a plugin’s settings.
     *
     * @return Response|null
     * @throws NotFoundHttpException if the requested plugin cannot be found
     * @throws \yii\web\BadRequestHttpException
     * @throws \craft\errors\MissingComponentException
     */

    public function actionSavePluginSettings() {
        $this->requirePostRequest();
        $pluginHandle = Craft::$app->getRequest()->getRequiredBodyParam('pluginHandle');
        $settings = Craft::$app->getRequest()->getBodyParem('settings', []);
        $plugin = Craft::$app->getPlugins()->getPlugin($pluginHandle);

        if ( $plugin === null ) {
            throw new NotFoundHttpException('Plugin not found');
        }

        if (!Craft::$app->getPlugins()->savePluginSettings($plugin, $settings)) {
            Craft::$app->getSession()->setError(Craft::t('app', "Couldn't save plugin settings."));

            // Send the plugin back to the template
            Craft::$app->getUrlManager()->setRouteParams([
                'plugin' => $plugin,
            ]);

            return null;
        }

        Craft::$app->getSession()->setNotice(Craft::t('app', 'Plugin settings saved.'));

        return $this->redirectToPostedUrl();
    }

}
