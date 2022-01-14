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

use percipiolondon\typesense\Typesense;

use Craft;
use craft\web\Controller;
use craft\helpers\Json;

use Typesense\Client as TypesenseClient;

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
class CollectionController extends Controller
{

    // Protected Properties
    // =========================================================================

    /**
     * @var    bool|array Allows anonymous access to this controller's actions.
     *         The actions must be in 'kebab-case'
     * @access protected
     */
    protected $allowAnonymous = ['create-collection', 'drop-collection', 'list-collections', 'retrieve-collection', 'index-documents', 'list-documents', 'delete-documents'];

    // Public Methods
    // =========================================================================

    public function actionCreateCollection()
    {
        $schema = [
            'name'      => 'news',
            'fields'    => [
                [
                    'name'  => 'title',
                    'type'  => 'string'
                ],
                [
                    'name'  => 'slug',
                    'type'  => 'string',
                    'facet' => true
                ],
                [
                    'name'  => 'dateCreated',
                    'type'  => 'int32',

                ]
            ],
            'default_sorting_field' => 'dateCreated' // can only be an integer
        ];

        if ( !$this->createClient()->collections['news'] ) {
            $this->createClient()->collections->create($schema);
            return 'index successfully created';
        } else {
            return 'this index already exists';
        }

    }

    public function actionIndexDocuments()
    {

        $documents = [
            [
                'id' => '1',
                'title' => 'Typesense Entry 1',
                'slug' => 'typesense-entry-1',
                'dateCreated' => 1639663729
            ],
            [
                'id' => '2',
                'title' => 'Typesense Entry 2',
                'slug' => 'typesense-entry-2',
                'dateCreated' => 1639663729
            ],
            [
                'id' => '3',
                'title' => 'Typesense Entry 3',
                'slug' => 'typesense-entry-3',
                'dateCreated' => 1639663729
            ],
            [
                'id' => '4',
                'title' => 'Typesense Entry 4',
                'slug' => 'typesense-entry-4',
                'dateCreated' => 1639663729
            ],
            [
                'id' => '5',
                'title' => 'Typesense Entry 5',
                'slug' => 'typesense-entry-5',
                'dateCreated' => 1639663729
            ],
            [
                'id' => '6',
                'title' => 'Typesense Entry 6',
                'slug' => 'typesense-entry-6',
                'dateCreated' => 1639663729
            ],
            [
                'id' => '7',
                'title' => 'Typesense Entry 7',
                'slug' => 'typesense-entry-7',
                'dateCreated' => 1639663729
            ],
            [
                'id' => '8',
                'title' => 'Typesense Entry 8',
                'slug' => 'typesense-entry-8',
                'dateCreated' => 1639663729
            ],
            [
                'id' => '9',
                'title' => 'Typesense Entry 9',
                'slug' => 'typesense-entry-9',
                'dateCreated' => 1639663729
            ],
            [
                'id' => '10',
                'title' => 'Typesense Entry 10',
                'slug' => 'typesense-entry-10',
                'dateCreated' => 1639663729
            ],
        ];

        if ( $this->createClient()->collections['news'] ) {
            foreach ( $documents as $document) {
                $this->createClient()->collections['news']->documents->upsert($document);
            }
            return 'All elements added to the index';
        } else {
            return 'this index doesn\'t exist';
        }
    }

    public function actionListDocuments(): Response
    {

        if ( $this->createClient()->collections['news'] ) {
            return $this->asJson($this->createClient()->collections['news']->documents->export());
        } else {
            return 'this index doesn\'t exist';
        }
    }

    public function actionDeleteDocuments()
    {

        if ( $this->createClient()->collections['news'] ) {
            $this->createClient()->collections['news']->documents->delete(['filter_by' => 'title: Typesense']);
        } else {
            return 'this index doesn\'t exist';
        }

    }

    public function actionDropCollection()
    {

        if ( $this->createClient()->collections['news'] ) {
            $this->createClient()->collections['news']->delete();
            return 'index successfully deleted';
        } else {
            return 'this index doesn\'t exist';
        }
    }

    public function actionListCollections(): Response
    {
       return $this->asJson($this->createClient()->collections->retrieve());
    }

    public function actionRetrieveCollection()
    {
        return $this->asJson($this->createClient()->collections['news']->retrieve());
    }

    private function createClient()
    {
        return new TypesenseClient(
            [
                'api_key' => 'HxJXT2kcRJ9Aqkns7xjhVL1nNE2KFc2x',
                'nodes' => [
                    [
                        'host' => 'typesense',
                        'port' => '8108',
                        'protocol' => 'http',
                    ],
                ],
                'connection_timeout_seconds' => 2,
            ]
        );
    }
}
