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
use craft\elements\Entry;
use craft\errors\MissingComponentException;
use craft\helpers\UrlHelper;
use craft\models\Sites;
use craft\web\Controller;

use yii\base\InvalidConfigException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
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
     * Dashboard display
     *
     * @param string|null $siteHandle
     * @param bool        $showWelcome
     *
     * @return Response The rendered result
     * @throws NotFoundHttpException
     * @throws ForbiddenHttpException
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
     * @throws ForbiddenHttpException
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
     * @throws BadRequestHttpException
     * @throws MissingComponentException
     */

    public function actionSavePluginSettings()
    {
        $this->requirePostRequest();
        $pluginHandle = Craft::$app->getRequest()->getRequiredBodyParam('pluginHandle');
        $plugin = Craft::$app->getPlugins()->getPlugin($pluginHandle);

        if ( $plugin === null ) {
            throw new NotFoundHttpException('Plugin not found');
        }

        $settings = [
            'apiKey' => Craft::$app->getRequest()->getBodyParam('apiKey'),
            'cluster' => Craft::$app->getRequest()->getBodyParam('cluster'),
            'clusterPort' => Craft::$app->getRequest()->getBodyParam('clusterPort'),
            'port' => Craft::$app->getRequest()->getBodyParam('searchOnlyApiKey'),
            'protocol' => Craft::$app->getRequest()->getBodyParam('protocol'),
            'searchOnlyApiKey' => Craft::$app->getRequest()->getBodyParam('searchOnlyApiKey'),
            'server' => Craft::$app->getRequest()->getBodyParam('server'),
            'serverType' => Craft::$app->getRequest()->getBodyParam('serverType'),
        ];

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
