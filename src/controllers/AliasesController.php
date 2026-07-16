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
use craftpulse\typesense\enums\MultisiteStrategy;
use craftpulse\typesense\jobs\RebuildCollection;
use craftpulse\typesense\Typesense;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The Pro aliases manager: one table of collections showing each collection's
 * current physical target and when it was last built, with a per-row
 * zero-downtime rebuild, plus a capability-gated clone. Gated by the edition and
 * `typesense:manageAliases`.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class AliasesController extends ProController
{
    // Constants
    // =========================================================================

    /**
     * @var string The permission that gates the aliases screens.
     */
    public const PERMISSION_MANAGE_ALIASES = 'typesense:manageAliases';

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

        $this->requirePermission(self::PERMISSION_MANAGE_ALIASES);

        return true;
    }

    /**
     * Clones a collection's schema into a new collection (capability-gated).
     *
     * @return Response|null
     * @throws \yii\web\BadRequestHttpException
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionClone(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(self::PERMISSION_MANAGE_ALIASES);

        $source = trim((string)$this->request->getRequiredBodyParam('source'));
        $target = trim((string)$this->request->getRequiredBodyParam('target'));

        if ($target === '' || !Typesense::$plugin->getAliases()->clone($source, $target)) {
            return $this->asFailure(Craft::t('typesense', 'Could not clone the collection.'));
        }

        return $this->asSuccess(Craft::t('typesense', 'Cloned {source} to {target}.', ['source' => $source, 'target' => $target]), [], 'typesense/aliases');
    }

    /**
     * The aliases manager: the server's aliases and the collections eligible for
     * a zero-downtime rebuild.
     *
     * @return Response
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionIndex(): Response
    {
        $capabilities = Typesense::$plugin->getClient()->getServerCapabilities();

        return $this->renderTemplate('typesense/aliases/index', [
            'rows' => $this->_rows(),
            'supportsCloning' => $capabilities?->collectionCloning() ?? false,
        ]);
    }

    /**
     * Queues a zero-downtime rebuild for a collection (all its sites).
     *
     * @return Response|null
     * @throws NotFoundHttpException
     * @throws \yii\web\BadRequestHttpException
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionRebuild(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(self::PERMISSION_MANAGE_ALIASES);

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
            $queue->priority($priority)->push(new RebuildCollection([
                'collectionHandle' => $handle,
                'siteId' => $siteId,
            ]));
        }

        return $this->asSuccess(Craft::t('typesense', 'Rebuild queued. The alias keeps serving the current collection until the swap.'), [], 'typesense/aliases');
    }

    // Private Methods
    // =========================================================================

    /**
     * One row per registered collection: its logical name, the physical
     * collection currently serving it (via an alias, or itself when not yet
     * aliased), and when that physical collection was last built (its server
     * created_at). Fail-soft: an unreachable server yields null timestamps.
     *
     * @return array<int, array{name: string, resolved: string, physical: string, aliased: bool, lastBuilt: int|null}>
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    private function _rows(): array
    {
        $registry = Typesense::$plugin->getCollectionRegistry();
        $aliases = Typesense::$plugin->getAliases()->all();
        $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;
        $created = [];
        $client = Typesense::$plugin->getClient()->client();

        if ($client !== null) {
            try {
                foreach ($client->collections->retrieve() as $info) {
                    if (!is_array($info)) {
                        continue;
                    }

                    $created[(string)($info['name'] ?? '')] = isset($info['created_at']) ? (int)$info['created_at'] : null;
                }
            } catch (\Throwable) {
                // Fail-soft: leave timestamps null when the server is unreachable.
            }
        }

        $rows = [];

        foreach ($registry->getAll() as $name => $collection) {
            $resolved = $registry->resolveName($collection, $primarySiteId);
            $aliased = array_key_exists($resolved, $aliases);
            $physical = $aliased ? (string)$aliases[$resolved] : $resolved;
            $rows[] = [
                'name' => (string)$name,
                'resolved' => $resolved,
                'physical' => $physical,
                'aliased' => $aliased,
                'lastBuilt' => $created[$physical] ?? null,
            ];
        }

        return $rows;
    }
}
