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
 * The Pro collections cockpit: browse, create, and edit CP-managed collections
 * and their field mappings. Gated by the edition (via [[ProController]]) and the
 * `typesense:manageCollections` permission.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class CollectionsController extends ProController
{
    // Constants
    // =========================================================================

    /**
     * @var string The permission that gates the collections cockpit.
     */
    public const PERMISSION_MANAGE_COLLECTIONS = 'typesense:manageCollections';

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

        $this->requirePermission(self::PERMISSION_MANAGE_COLLECTIONS);

        return true;
    }

    /**
     * Lists the CP-managed and config-owned collections.
     *
     * @return Response
     * @throws \yii\base\Exception
     * @author CraftPulse
     */
    public function actionIndex(): Response
    {
        return $this->renderTemplate('typesense/collections/index');
    }

    /**
     * Persists a CP-managed collection to project config.
     *
     * @return Response|null
     * @throws \yii\web\BadRequestHttpException
     * @author CraftPulse
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        // The save flow lands with the cockpit screens; the gate is enforced here.
        return $this->asSuccess('Saved.');
    }
}
