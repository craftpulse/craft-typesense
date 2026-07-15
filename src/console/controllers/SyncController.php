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

use craftpulse\typesense\Typesense;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Sync commands: full sync, per-collection sync, flush, reconcile, and the
 * global suspend switch.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class SyncController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Queues a full sync of every collection.
     *
     * @return int
     * @author CraftPulse
     */
    public function actionAll(): int
    {
        Typesense::$plugin->getSync()->syncAll();
        $this->stdout('Queued a full sync of all collections.' . PHP_EOL);

        return ExitCode::OK;
    }

    /**
     * Queues a full sync of one collection.
     *
     * @param string $handle
     * @return int
     * @author CraftPulse
     */
    public function actionCollection(string $handle): int
    {
        if (Typesense::$plugin->getCollectionRegistry()->get($handle) === null) {
            $this->stderr("No collection found with the handle \"{$handle}\"." . PHP_EOL);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        Typesense::$plugin->getSync()->syncCollection($handle);
        $this->stdout("Queued a sync of {$handle}." . PHP_EOL);

        return ExitCode::OK;
    }

    /**
     * Queues a flush (delete and re-sync) of every collection.
     *
     * @return int
     * @author CraftPulse
     */
    public function actionFlush(): int
    {
        Typesense::$plugin->getSync()->flushAll();
        $this->stdout('Queued a flush of all collections.' . PHP_EOL);

        return ExitCode::OK;
    }

    /**
     * Queues reconciliation of every collection (removes orphaned documents).
     *
     * @return int
     * @author CraftPulse
     */
    public function actionRefresh(): int
    {
        foreach (Typesense::$plugin->getCollectionRegistry()->getAll() as $collection) {
            Typesense::$plugin->getSync()->reconcile($collection);
        }

        $this->stdout('Queued reconciliation of all collections.' . PHP_EOL);

        return ExitCode::OK;
    }

    /**
     * Suspends element sync: globally, or for one collection when a handle is
     * given (for bulk imports).
     *
     * @param string|null $collection The collection to suspend, or null for all.
     * @return int
     * @author CraftPulse
     */
    public function actionSuspend(?string $collection = null): int
    {
        Typesense::$plugin->getSyncSuspend()->suspend($collection);
        $this->stdout(($collection !== null
            ? "Sync suspended for '$collection'."
            : 'Sync suspended.') . PHP_EOL);

        return ExitCode::OK;
    }

    /**
     * Resumes element sync: globally, or for one collection when a handle is
     * given.
     *
     * @param string|null $collection The collection to resume, or null for all.
     * @return int
     * @author CraftPulse
     */
    public function actionResume(?string $collection = null): int
    {
        Typesense::$plugin->getSyncSuspend()->resume($collection);
        $this->stdout(($collection !== null
            ? "Sync resumed for '$collection'."
            : 'Sync resumed.') . PHP_EOL);

        return ExitCode::OK;
    }
}
