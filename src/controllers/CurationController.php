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

use craftpulse\typesense\controllers\base\ProController;
use yii\web\Response;

/**
 * The Pro curation manager. Gated by the edition (via [[ProController]]) and the
 * `typesense:manageCuration` permission. The CRUD screens land in a later phase;
 * the controller carries its permission contract and gate now.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class CurationController extends ProController
{
    // Constants
    // =========================================================================

    /**
     * @var string The permission that gates the curation manager.
     */
    public const PERMISSION_MANAGE_CURATION = 'typesense:manageCuration';

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

        $this->requirePermission(self::PERMISSION_MANAGE_CURATION);

        return true;
    }

    /**
     * The curation manager landing screen.
     *
     * @return Response
     * @throws \yii\base\Exception
     * @author CraftPulse
     */
    public function actionIndex(): Response
    {
        return $this->renderTemplate('typesense/curation/index');
    }
}
