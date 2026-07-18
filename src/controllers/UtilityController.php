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
use craftpulse\typesense\enums\MultisiteStrategy;
use craftpulse\typesense\jobs\ApplySchema;
use craftpulse\typesense\services\Audit;
use craftpulse\typesense\Typesense;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Operational actions behind the Typesense CP utility: sync, flush, apply
 * schema, and the suspend toggle, globally or per collection. Each responds to
 * both a full-page form post (the global blocks) and the per-row action menu
 * (AJAX). Gated by typesense:manageOps.
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
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(OpsController::PERMISSION_MANAGE_OPS);

        return true;
    }

    /**
     * Queues an additive schema apply for one collection: it creates the
     * collection if missing and adds newly declared fields. A field-type change
     * is not applied in place (Typesense re-creates the collection for that), so
     * use a Rebuild on the Aliases screen for structural changes.
     *
     * @return Response|null
     * @throws NotFoundHttpException
     * @throws \yii\web\BadRequestHttpException
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionApplySchema(): ?Response
    {
        $this->requirePostRequest();

        $handle = (string)$this->request->getRequiredBodyParam('collection');
        $collection = Typesense::$plugin->getCollectionRegistry()->get($handle);

        if ($collection === null) {
            throw new NotFoundHttpException('Collection not found.');
        }

        $priority = Typesense::$plugin->getSync()->getQueuePriority();
        $queue = Craft::$app->getQueue();
        $siteIds = $collection->getMultisiteStrategy() === MultisiteStrategy::CollectionPerSite
            ? Craft::$app->getSites()->getAllSiteIds()
            : [Craft::$app->getSites()->getPrimarySite()->id];

        foreach ($siteIds as $siteId) {
            $queue->priority($priority)->push(new ApplySchema([
                'collectionHandle' => $handle,
                'siteId' => $siteId,
            ]));
        }

        return $this->asSuccess(Craft::t('typesense', 'Schema apply queued.'), [], 'utilities/typesense');
    }

    /**
     * Queues a flush (delete and re-sync): one collection when a `collection`
     * body param is posted, otherwise every collection.
     *
     * @return Response|null
     * @throws \yii\web\BadRequestHttpException
     * @author CraftPulse
     */
    public function actionFlush(): ?Response
    {
        $this->requirePostRequest();

        $handle = trim((string)$this->request->getBodyParam('collection', ''));

        if ($handle !== '') {
            Typesense::$plugin->getSync()->flush($handle);
            Typesense::$plugin->getAudit()->record(Audit::EVENT_INDEX_FLUSHED, ['scope' => $handle]);

            return $this->asSuccess(Craft::t('typesense', 'Flush queued for {collection}.', ['collection' => $handle]), [], 'utilities/typesense');
        }

        Typesense::$plugin->getSync()->flushAll();
        Typesense::$plugin->getAudit()->record(Audit::EVENT_INDEX_FLUSHED, ['scope' => 'all']);

        return $this->asSuccess(Craft::t('typesense', 'Flush queued.'), [], 'utilities/typesense');
    }

    /**
     * Queues a sync: one collection when a `collection` body param is posted,
     * otherwise every collection.
     *
     * @return Response|null
     * @throws \yii\web\BadRequestHttpException
     * @author CraftPulse
     */
    public function actionSync(): ?Response
    {
        $this->requirePostRequest();

        $handle = trim((string)$this->request->getBodyParam('collection', ''));

        if ($handle !== '') {
            Typesense::$plugin->getSync()->syncCollection($handle);
            Typesense::$plugin->getAudit()->record(Audit::EVENT_INDEX_SYNCED, ['scope' => $handle]);

            return $this->asSuccess(Craft::t('typesense', 'Sync queued for {collection}.', ['collection' => $handle]), [], 'utilities/typesense');
        }

        Typesense::$plugin->getSync()->syncAll();
        Typesense::$plugin->getAudit()->record(Audit::EVENT_INDEX_SYNCED, ['scope' => 'all']);

        return $this->asSuccess(Craft::t('typesense', 'Sync queued.'), [], 'utilities/typesense');
    }

    /**
     * Toggles the sync-suspend switch: globally, or for one collection when a
     * `collection` body param is posted. Suspend is runtime database state, so
     * this works even when allowAdminChanges is disabled.
     *
     * @return Response|null
     * @throws \yii\web\BadRequestHttpException
     * @author CraftPulse
     */
    public function actionToggleSuspend(): ?Response
    {
        $this->requirePostRequest();

        $collection = $this->request->getBodyParam('collection');
        $collection = is_string($collection) && $collection !== '' ? $collection : null;

        $suspended = Typesense::$plugin->getSyncSuspend()->toggle($collection);

        return $this->asSuccess(
            $suspended ? Craft::t('typesense', 'Sync suspended.') : Craft::t('typesense', 'Sync resumed.'),
            [],
            'utilities/typesense',
        );
    }
}
