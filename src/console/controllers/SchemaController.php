<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\console\controllers;

use Craft;
use craftpulse\typesense\enums\MultisiteStrategy;
use craftpulse\typesense\jobs\ApplySchema;
use craftpulse\typesense\jobs\RebuildCollection;
use craftpulse\typesense\services\Audit;
use craftpulse\typesense\Typesense;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Schema commands. Applying schema changes is always queued and polled, never a
 * blocking save, because a schema change blocks writes cluster-wide.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class SchemaController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Queues additive schema changes for every collection.
     *
     * @return int
     * @author CraftPulse
     */
    public function actionApply(): int
    {
        $registry = Typesense::$plugin->getCollectionRegistry();
        $priority = Typesense::$plugin->getSync()->getQueuePriority();
        $queue = Craft::$app->getQueue();
        $queued = 0;

        foreach ($registry->getAll() as $collection) {
            $siteIds = $collection->getMultisiteStrategy() === MultisiteStrategy::CollectionPerSite
                ? Craft::$app->getSites()->getAllSiteIds()
                : [Craft::$app->getSites()->getPrimarySite()->id];

            foreach ($siteIds as $siteId) {
                $queue->priority($priority)->push(new ApplySchema([
                    'collectionHandle' => $collection->getName(),
                    'siteId' => $siteId,
                ]));
                $queued++;
            }
        }

        Typesense::$plugin->getAudit()->record(Audit::EVENT_SCHEMA_APPLIED, ['queued' => $queued]);
        $this->stdout("Queued {$queued} schema-apply job(s)." . PHP_EOL);

        return ExitCode::OK;
    }

    /**
     * Queues a zero-downtime rebuild for every collection (or one, by handle):
     * a fresh physical collection, copied documents, an atomic alias swap, and
     * cleanup of the old physical collection.
     *
     * @param string|null $handle Rebuild only this collection.
     * @return int
     * @author CraftPulse
     */
    public function actionRebuild(?string $handle = null): int
    {
        $registry = Typesense::$plugin->getCollectionRegistry();
        $priority = Typesense::$plugin->getSync()->getQueuePriority();
        $queue = Craft::$app->getQueue();
        $queued = 0;

        foreach ($registry->getAll() as $collection) {
            if ($handle !== null && $collection->getName() !== $handle) {
                continue;
            }

            $siteIds = $collection->getMultisiteStrategy() === MultisiteStrategy::CollectionPerSite
                ? Craft::$app->getSites()->getAllSiteIds()
                : [Craft::$app->getSites()->getPrimarySite()->id];

            foreach ($siteIds as $siteId) {
                $queue->priority($priority)->push(new RebuildCollection([
                    'collectionHandle' => $collection->getName(),
                    'siteId' => $siteId,
                ]));
                $queued++;
            }
        }

        Typesense::$plugin->getAudit()->record(Audit::EVENT_COLLECTION_REBUILT, [
            'collection' => $handle ?? 'all',
            'queued' => $queued,
        ]);
        $this->stdout("Queued {$queued} rebuild job(s)." . PHP_EOL);

        return ExitCode::OK;
    }
}
