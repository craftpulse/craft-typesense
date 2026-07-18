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
use craft\elements\Entry;
use craftpulse\typesense\services\Audit;
use craftpulse\typesense\Typesense;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Legacy sync commands, routed through the new sync engine.
 *
 * `typesense/default/sync` and `typesense/default/flush` are kept as backwards
 * compatible aliases; the richer sync command surface arrives in a later phase.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     1.0.0
 */
class DefaultController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Queues a flush (delete and re-sync) of every collection.
     *
     * @return int
     * @author CraftPulse
     */
    public function actionFlush(): int
    {
        foreach (Typesense::$plugin->getCollectionRegistry()->getAll() as $collection) {
            $this->stdout('Flush ' . $collection->getName() . PHP_EOL);
        }

        Typesense::$plugin->getSync()->flushAll();
        Typesense::$plugin->getAudit()->record(Audit::EVENT_INDEX_FLUSHED, ['scope' => 'all']);

        return ExitCode::OK;
    }

    /**
     * Queues a full sync of every collection.
     *
     * @return int
     * @author CraftPulse
     */
    public function actionSync(): int
    {
        foreach (Typesense::$plugin->getCollectionRegistry()->getAll() as $collection) {
            $this->stdout('Sync ' . $collection->getName() . PHP_EOL);
        }

        Typesense::$plugin->getSync()->syncAll();
        Typesense::$plugin->getAudit()->record(Audit::EVENT_INDEX_SYNCED, ['scope' => 'all']);

        return ExitCode::OK;
    }

    /**
     * Resaves entries whose scheduled post date is today so they reach Typesense.
     *
     * Craft does not fire a save when a scheduled entry becomes live, so this
     * command (run from cron) resaves today's freshly-live entries; the resave
     * triggers the sync engine's element listener.
     *
     * @return int
     * @author CraftPulse
     */
    public function actionUpdateScheduledPosts(): int
    {
        $morning = date('Y-m-d 00:00:00');
        $evening = date('Y-m-d 23:59:59');

        $entries = Entry::find()
            ->postDate(['and', ">= {$morning}", "<= {$evening}"])
            ->status(null)
            ->andWhere('[[elements.dateUpdated]] < [[entries.postDate]]')
            ->all();

        foreach ($entries as $entry) {
            Craft::$app->getElements()->saveElement($entry);
        }

        $count = count($entries);
        Craft::info("Typesense update scheduled posts fired with {$count} result(s)", __METHOD__);
        $this->stdout("Updated {$count} scheduled entr(ies)." . PHP_EOL);

        return ExitCode::OK;
    }
}
