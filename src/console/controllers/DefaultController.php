<?php
/**
 * Typesense plugin for Craft CMS 3.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://percipio.london
 * @copyright Copyright (c) 2021 percipiolondon
 */

namespace percipiolondon\typesense\console\controllers;

use Craft;
use craft\helpers\Queue;
use percipiolondon\typesense\jobs\SyncDocumentsJob;

use percipiolondon\typesense\Typesense;
use yii\console\Controller;
use yii\helpers\Console;

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
    // Public Methods
    // =========================================================================

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
        $indexes = Typesense::$plugin->getClient()->client()->collections;

        foreach ($indexes as $index) {
            $this->stdout('Flush ' . $index->indexName);
            $this->stdout(PHP_EOL);
            $collection = Typesense::$plugin->getCollections()->getCollectionByCollectionRetrieve($index->indexName);

            //delete collection
            if (!empty($collection)) {
                Typesense::$plugin->getClient()->client()->collections[$index->indexName]->delete();
            }

            Queue::push(new SyncDocumentsJob([
                'criteria' => [
                    'index' => $index->indexName,
                    'type' => 'Flush',
                ],
            ]));
        }
    }
}
