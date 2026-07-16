<?php

/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://percipio.london
 * @copyright Copyright (c) 2021 percipiolondon
 */

namespace percipiolondon\typesense\console\controllers;

use Craft;
use craft\elements\Entry;
use craft\helpers\Queue;
use percipiolondon\typesense\jobs\SyncDocumentsJob;
use percipiolondon\typesense\events\DocumentEvent;

use percipiolondon\typesense\Typesense;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Default Command
 *
 * The first line of this class docblock is displayed as the description
 * of the Console Command in ./craft help
 *
 * Craft can be invoked via commandline console by using the `./craft` command
 * from the project root.
 *
 * Console Commands are just controllers that are invoked to handle console
 * actions. The segment routing is plugin-name/controller-name/action-name
 *
 * The actionIndex() method is what is executed if no sub-commands are supplied, e.g.:
 *
 * ./craft typesense/default
 *
 * Actions must be in 'kebab-case' so actionDoSomething() maps to 'do-something',
 * and would be invoked via:
 *
 * ./craft typesense/default/do-something
 *
 * @author    percipiolondon
 * @package   Typesense
 * @since     1.0.0
 */
class DefaultController extends Controller
{
    // Events
    // -------------------------------------------------------------------------

    /**
     * @event The event that is triggered before a flush / sync happens.
     */
    public const EVENT_BEFORE_FLUSH = 'beforeFlush';
    public const EVENT_BEFORE_SYNC = 'beforeSync';

    // Public Properties
    // =========================================================================

    /**
     * @var bool Whether to skip the confirmation prompt on the destructive
     * update-schema action.
     */
    public bool $force = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'update-schema') {
            $options[] = 'force';
        }

        return $options;
    }

    /**
     * Handle typesense/default console commands
     *
     * The first line of this method docblock is displayed as the description
     * of the Console Command in ./craft help
     *
     * @return mixed
     */
    public function actionFlush()
    {
        $indexes = Typesense::$plugin->getSettings()->collections;

        foreach ($indexes as $index) {
            $this->stdout('Flush ' . $index->indexName);
            $this->stdout(PHP_EOL);

            if ($this->hasEventHandlers(self::EVENT_BEFORE_FLUSH)) {
                $this->trigger(self::EVENT_BEFORE_FLUSH, new DocumentEvent([
                    'document' => [
                        'index' => $index->indexName,
                        'type' => 'Flush',
                    ]
                ]));
            }

            Queue::push(new SyncDocumentsJob([
                'criteria' => [
                    'index' => $index->indexName,
                    'type' => 'Flush'
                ]
            ]));
        }
    }

    /**
     * DESTRUCTIVE: drops and recreates every configured collection's fields.
     *
     * Rebuilds each collection's schema by dropping all of its fields and
     * re-adding them from config/typesense.php. Existing field data is removed
     * and must be re-synced afterwards. Intended for development when a schema is
     * tweaked. Prompts for confirmation unless --force is passed.
     *
     * @return mixed
     */
    public function actionUpdateSchema()
    {
        if (!$this->force && !$this->confirm('This DROPS and recreates the fields on every configured collection, removing existing field data. Continue?')) {
            $this->stdout('Aborted.' . PHP_EOL);
            return ExitCode::OK;
        }

        Typesense::$plugin->getCollections()->updateSchema();

        return ExitCode::OK;
    }

    public function actionSync()
    {
        $indexes = Typesense::$plugin->getSettings()->collections;

        foreach ($indexes as $index) {
            $this->stdout('Sync ' . $index->indexName);
            $this->stdout(PHP_EOL);

            if ($this->hasEventHandlers(self::EVENT_BEFORE_SYNC)) {
                $this->trigger(self::EVENT_BEFORE_SYNC, new DocumentEvent([
                    'document' => [
                        'index' => $index->indexName,
                        'type' => 'Sync',
                    ]
                ]));
            }

            Queue::push(new SyncDocumentsJob([
                'criteria' => [
                    'index' => $index->indexName,
                    'type' => 'Sync',
                ],
            ]));
        }
    }

    public function actionUpdateScheduledPosts()
    {
        $this->stdout("Start fetching entries with today's post date");
        $this->stdout(PHP_EOL);

        // set timestamps to fetch todays entries
        $morning = mktime(0, 0, 0, (int) date('m'), (int) date('d'), (int) date('y'));
        $evening = mktime(23, 59, 00, (int) date('m'), (int) date('d'), (int) date('y'));

        // select entries of today's postDate where the dateUpdated is before the postDate gets out
        $todaysEntries = Entry::find()
            ->where(['between', 'postDate', date('Y/m/d H:i', $morning), date('Y/m/d H:i', $evening)])
            ->andWhere('`elements`.`dateUpdated` < `entries`.`postDate`')
            ->all();

        // resave those entries to setup the document in typsense
        $count = 0;
        foreach ($todaysEntries as $entry) {
            Craft::$app->getElements()->saveElement($entry);
            $count += 1;
        }

        Craft::info('Typesense update scheduled post fired with ' . $count . ' result(s)', __METHOD__);
        $this->stdout("End fetching entries with today's post date with " . $count . ' result(s)');
        $this->stdout(PHP_EOL);
    }
}
