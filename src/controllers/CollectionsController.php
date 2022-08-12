<?php
/**
 * Typesense plugin for Craft CMS 3.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://percipio.london
 * @copyright Copyright (c) 2021 percipiolondon
 */

namespace percipiolondon\typesense\controllers;

use Craft;
use craft\helpers\Queue;

use craft\web\Controller;
use Http\Client\Exception;
use percipiolondon\typesense\helpers\CollectionHelper;
use percipiolondon\typesense\jobs\SyncDocumentsJob;

use percipiolondon\typesense\Typesense;


use Typesense\Exceptions\TypesenseClientError;
use yii\base\InvalidConfigException;
use yii\di\NotInstantiableException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Default Controller
 *
 * Generally speaking, controllers are the middlemen between the front end of
 * the CP/website and your plugin’s services. They contain action methods which
 * handle individual tasks.
 *
 * A common pattern used throughout Craft involves a controller action gathering
 * post data, saving it on a model, passing the model off to a service, and then
 * responding to the request appropriately depending on the service method’s response.
 *
 * Action methods begin with the prefix “action”, followed by a description of what
 * the method does (for example, actionSaveIngredient()).
 *
 * https://craftcms.com/docs/plugins/controllers
 *
 * @author    percipiolondon
 * @package   Typesense
 * @since     1.0.0
 */
class CollectionsController extends Controller
{
    // Protected Properties
    // =========================================================================
    protected $allowAnonymous = ['create-collection', 'drop-collection', 'list-collections', 'retrieve-collection', 'index-documents', 'list-documents', 'delete-documents'];

    // Public Methods
    // =========================================================================

    /**
     * @throws InvalidConfigException
     * @throws ForbiddenHttpException
     */
    public function init()
    {
        parent::init();

        $this->requirePermission('typesense:manage-collections');
    }


    /**
     * Collections display
     *
     * @return Response The rendered result
     */
    public function actionCollections(): Response
    {
        $variables = [];
        $entriesCount = [
            'entries' => [],
        ];

        $pluginName = Typesense::$plugin->getSettings()->pluginName;
        $templateTitle = Craft::t('typesense', 'Collections');

        $variables['controllerHandle'] = 'collections';
        $variables['pluginName'] = Typesense::$plugin->getSettings()->pluginName;
        $variables['title'] = $templateTitle;
        $variables['docTitle'] = "{$pluginName} - {$templateTitle}";
        $variables['selectedSubnavItem'] = 'collections';

        $indexes = Typesense::$plugin->getSettings()->collections;

        foreach ($indexes as $index) {
            $entry = $index->criteria->one();
            $section = $entry->section ?? null;

            if ($section) {
                $variables['sections'][] = [
                    'id' => $section->id,
                    'name' => $section->name,
                    'handle' => $section->handle,
                    'type' => $entry->type->handle,
                    'entryCount' => $index->criteria->count(),
                    'index' => $index->indexName,
                ];
            }
        }

        $variables['csrf'] = [
            'name' => Craft::$app->getConfig()->getGeneral()->csrfTokenName,
            'value' => Craft::$app->getRequest()->getCsrfToken(),
        ];

        // Render the template
        return $this->renderTemplate('typesense/collections/index', $variables);
    }

    /**
     * @return Response
     */
    public function actionFlushCollection(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $index = $request->getBodyParam('index');
        $collection = Typesense::$plugin->getCollections()->getCollectionByCollectionRetrieve($index);

        //delete collection
        if (!empty($collection)) {
            Typesense::$plugin->getClient()->client()->collections[$index]->delete();
        }

        Queue::push(new SyncDocumentsJob([
            'criteria' => [
                'index' => $index,
                'type' => 'Flush',
            ],
        ]));

        return $this->asJson([
            'success' => true,
        ]);
    }

    /**
     * @return Response
     * @throws Exception
     * @throws TypesenseClientError
     * @throws InvalidConfigException
     * @throws NotInstantiableException
     * @throws BadRequestHttpException
     */
    public function actionSyncCollection(): Response
    {
        $this->requirePostRequest();

        $request = Craft::$app->getRequest();
        $index = $request->getBodyParam('index');

        Queue::push(new SyncDocumentsJob([
            'criteria' => [
                'index' => $index,
                'type' => 'Sync',
            ],
        ]));

        return $this->asJson([
            'success' => true,
            'documents' => CollectionHelper::convertDocumentsToArray($index),
        ]);
    }

    /**
     * @return Response
     * @throws BadRequestHttpException
     */
//    public function actionSaveCollection(): Response {
//        $this->requirePostRequest();
//
//        $request = Craft::$app->getRequest();
//        $collection = CollectionHelper::collectionToSync($request);
//
//        // Always update the sync data ( after all the data has synced before re-writing in the database )
//        $collection->dateSynced = DateTimeHelper::toDateTime(DateTimeHelper::currentTimeStamp());
//
//        // Save the collection
//        if (!Typesense::$plugin->getCollections()->saveCollection($collection)) {
//            // Response error
//            //$this->setFailFlash(Craft::t('typesense', 'Couldn’t save collection.'));
//
//            return $this->asJson([
//                'error' => Craft::t('typesense', 'Couldn’t save collection.'),
//            ]);
//        }
//
//        return $this->asJson($collection);
//    }

//    public function actionCreateCollection()
//    {
//
//        $schema = [
//            'name'      => 'news',
//            'fields'    => [
//                [
//                    'name'  => 'title',
//                    'type'  => 'string'
//                ],
//                [
//                    'name'  => 'slug',
//                    'type'  => 'string',
//                    'facet' => true
//                ],
//                [
//                    'name'  => 'dateCreated',
//                    'type'  => 'int32',
//
//                ]
//            ],
//            'default_sorting_field' => 'dateCreated' // can only be an integer
//        ];
//
//        if ( !Typesense::$plugin->getClient()->client()->collections['news'] ) {
//            Typesense::$plugin->getClient()->client()->collections->create($schema);
//            return 'index successfully created';
//        } else {
//            return 'this index already exists';
//        }
//
//    }
//
//    public function actionIndexDocuments()
//    {
//        $documents = [
//            [
//                'id' => '1',
//                'title' => 'Typesense Entry 1',
//                'slug' => 'typesense-entry-1',
//                'dateCreated' => 1639663729
//            ],
//            [
//                'id' => '2',
//                'title' => 'Typesense Entry 2',
//                'slug' => 'typesense-entry-2',
//                'dateCreated' => 1639663729
//            ],
//            [
//                'id' => '3',
//                'title' => 'Typesense Entry 3',
//                'slug' => 'typesense-entry-3',
//                'dateCreated' => 1639663729
//            ],
//            [
//                'id' => '4',
//                'title' => 'Typesense Entry 4',
//                'slug' => 'typesense-entry-4',
//                'dateCreated' => 1639663729
//            ],
//            [
//                'id' => '5',
//                'title' => 'Typesense Entry 5',
//                'slug' => 'typesense-entry-5',
//                'dateCreated' => 1639663729
//            ],
//            [
//                'id' => '6',
//                'title' => 'Typesense Entry 6',
//                'slug' => 'typesense-entry-6',
//                'dateCreated' => 1639663729
//            ],
//            [
//                'id' => '7',
//                'title' => 'Typesense Entry 7',
//                'slug' => 'typesense-entry-7',
//                'dateCreated' => 1639663729
//            ],
//            [
//                'id' => '8',
//                'title' => 'Typesense Entry 8',
//                'slug' => 'typesense-entry-8',
//                'dateCreated' => 1639663729
//            ],
//            [
//                'id' => '9',
//                'title' => 'Typesense Entry 9',
//                'slug' => 'typesense-entry-9',
//                'dateCreated' => 1639663729
//            ],
//            [
//                'id' => '10',
//                'title' => 'Typesense Entry 10',
//                'slug' => 'typesense-entry-10',
//                'dateCreated' => 1639663729
//            ],
//        ];
//
//
//        if ( Typesense::$plugin->getClient()->client()->collections['news'] ) {
//            foreach ( $documents as $document) {
//                Typesense::$plugin->getClient()->client()->collections['news']->documents->upsert($document);
//            }
//            return 'All elements added to the index';
//        } else {
//            return 'this index doesn\'t exist';
//        }
//    }

    /**
     * @return Response|string
     * @throws Exception
     * @throws TypesenseClientError
     * @throws InvalidConfigException
     * @throws NotInstantiableException
     */
    public function actionListDocuments(): Response|string
    {
        $request = Craft::$app->getRequest();
        $index = $request->getBodyParam('index');

        if (isset(Typesense::$plugin->getClient()->client()->collections[$index])) {
            return $this->asJson(Typesense::$plugin->getClient()->client()->collections[$index]->documents->export());
        }

        return "this index doesn't exist";
    }

    /**
     * @return string
     * @throws Exception
     * @throws TypesenseClientError
     * @throws InvalidConfigException
     * @throws NotInstantiableException
     */
    public function actionDeleteDocuments(): string
    {
        $request = Craft::$app->getRequest();
        $index = $request->getBodyParam('index');

        if (isset(Typesense::$plugin->getClient()->client()->collections[$index])) {
            Typesense::$plugin->getClient()->client()->collections[$index]->documents->delete(['filter_by' => 'title: Typesense']);
            return "documents for this index are deleted";
        }

        return "this index doesn't exist";
    }

    /**
     * @return string
     * @throws Exception
     * @throws TypesenseClientError
     * @throws InvalidConfigException
     * @throws NotInstantiableException
     */
    public function actionDropCollection(): string
    {
        $request = Craft::$app->getRequest();
        $index = $request->getBodyParam('index');

        if (isset(Typesense::$plugin->getClient()->client()->collections[$index])) {
            Typesense::$plugin->getClient()->client()->collections[$index]->delete();
            return "this index is successfully deleted";
        }

        return "this index doesn't exist";
    }

    /**
     * @return Response
     * @throws Exception
     * @throws TypesenseClientError
     * @throws InvalidConfigException
     * @throws NotInstantiableException
     */
    public function actionListCollections(): Response
    {
        return $this->asJson(Typesense::$plugin->getClient()->client()->collections->retrieve());
    }

    /**
     * @return Response
     * @throws Exception
     * @throws TypesenseClientError
     * @throws InvalidConfigException
     * @throws NotInstantiableException
     */
    public function actionRetrieveCollection()
    {
        $request = Craft::$app->getRequest();
        $index = $request->getBodyParam('index');

        return $this->asJson(Typesense::$plugin->getClient()->client()->collections[$index]->retrieve());
    }
}
