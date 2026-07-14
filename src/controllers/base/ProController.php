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
}
