<?php
/**
 * Typesense plugin for Craft CMS 3.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://percipio.london
 * @copyright Copyright (c) 2021 percipiolondon
 */

namespace percipiolondon\typesense;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\console\Application as ConsoleApplication;
use craft\errors\MissingComponentException;
use craft\events\ElementEvent;
use craft\events\PluginEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\App;
use craft\helpers\ElementHelper;
use craft\helpers\UrlHelper;
use craft\services\Elements;
use craft\services\Plugins;
use craft\services\ProjectConfig;
use craft\services\UserPermissions;
use craft\services\Utilities;
use craft\web\UrlManager;
use craft\web\twig\variables\CraftVariable;

use nystudio107\pluginvite\services\VitePluginService;

use percipiolondon\typesense\assetbundles\typesense\TypesenseAsset;
use percipiolondon\typesense\helpers\CollectionHelper;
use percipiolondon\typesense\helpers\ProjectConfigData;
use percipiolondon\typesense\models\Settings;
use percipiolondon\typesense\services\CollectionService;
use percipiolondon\typesense\services\TypesenseService;
use percipiolondon\typesense\typesense\Services as TypesenseServices;
use percipiolondon\typesense\utilities\TypesenseUtility;
use percipiolondon\typesense\variables\TypesenseVariable;

use Typesense\Client as TypesenseClient;

use yii\base\Event;

/**
 * Craft plugins are very much like little applications in and of themselves. We’ve made
 * it as simple as we can, but the training wheels are off. A little prior knowledge is
 * going to be required to write a plugin.
 *
 * For the purposes of the plugin docs, we’re going to assume that you know PHP and SQL,
 * as well as some semi-advanced concepts like object-oriented programming and PHP namespaces.
 *
 * https://docs.craftcms.com/v3/extend/
 *
 * @author    percipiolondon
 * @package   Typesense
 * @since     1.0.0
 *
 * @property  TypesenseService $typesenseService
 * @property  CollectionService $collectionService
 * @property  Settings $settings
 * @method    Settings getSettings()
 */
class Typesense extends Plugin
{
    // Static Properties
    // =========================================================================

    /**
     * Static property that is an instance of this plugin class so that it can be accessed via
     * Typesense::$plugin
     *
     * @var Typesense
     */
    public static $plugin;

    /**
     * @var Settings
     */
    public static $settings;

    /**
     * @var View
     */
    public static $view;

    // Public Properties
    // =========================================================================

    /**
     * To execute your plugin’s migrations, you’ll need to increase its schema version.
     *
     * @var string
     */
    public $schemaVersion = '1.0.0';

    /**
     * Set to `true` if the plugin should have its own section (main nav item) in the control panel.
     *
     * @var bool
     */
    public $hasCpSection = true;

    /**
     * Set to `true` if the plugin should have a settings view in the control panel.
     *
     * @var bool
     */
    public $hasCpSettings = true;

    use TypesenseServices;

    // Static Methods
    // =========================================================================
    /**
     * @inheritdoc
     */

    public function __construct($id, $parent = null, array $config = [])
    {
        $config['components'] = [
            'typesense' => Typesense::class,
            'collections' => CollectionService::class,
            // Register the vite service
            'vite' => [
                'class' => VitePluginService::class,
                'assetClass' => TypesenseAsset::class,
                'useDevServer' => true,
                'devServerPublic' => 'http://localhost:3001',
                'serverPublic' => 'http://localhost:8001',
                'errorEntry' => '/src/js/typesense.ts',
                'devServerInternal' => 'http://craft-typesense-buildchain:3001',
                'checkDevServer' => true,
            ]
        ];

        parent::__construct($id, $parent, $config);
    }

    // Public Methods
    // =========================================================================

    /**
     * Set our $plugin static property to this class so that it can be accessed via
     * Typesense::$plugin
     *
     * Called after the plugin class is instantiated; do any one-time initialization
     * here such as hooks and events.
     *
     * If you have a '/vendor/autoload.php' file, it will be loaded for you automatically;
     * you do not need to load it in your init() method.
     *
     */
    public function init()
    {
        parent::init();
        self::$plugin = $this;

        // Initialize properties
        self::$settings = self::$plugin->getSettings();
        self::$view = Craft::$app->getView();

        $this->name = self::$settings->pluginName;

        // Add in our console commands
        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'percipiolondon\typesense\console\controllers';
        }


        // Install our event listeners
        $this->installEventListeners();

        $this->_registerEventHandlers();

        $this->_createTypesenseClient();

        // Register our utilities
        /*Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITY_TYPES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = TypesenseUtility::class;
            }
        ); */

        // Register our variables
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function (Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('typesense', [
                    'class' => TypesenseVariable::class,
                    'viteService' => $this->vite,
                ]);
            }
        );

        // Do something after we're installed
        /*Event::on(
            Plugins::class,
            Plugins::EVENT_AFTER_INSTALL_PLUGIN,
            function (PluginEvent $event) {
                if ($event->plugin === $this) {
                    // We were just installed
                }
            }
        );*/

        Craft::info(
            Craft::t(
                'typesense',
                '{name} plugin loaded',
                ['name' => $this->name]
            ),
            __METHOD__
        );
    }

    /**
     * @inheritdoc
     */
    public function getSettings()
    {
        return parent::getSettings();;
    }

    /**
     * @inheritdoc
     */
    public function getSettingsResponse()
    {
        // redirect to plugin settings page
        Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('typesense/plugin'));
    }

    /**
     * @inheritdoc
     */
    public function getCpNavItem(){
        $subNavs = [];
        $navItem = parent::getCpNavItem();
        /** @var User $currentUser */
        $request = Craft::$app->getRequest();
        $currentUser = Craft::$app->getUser()->getIdentity();
        // Only show sub navigation the user has permission to view
        if ($currentUser->can('typesense:dashboard')) {
            $subNavs['dashboard'] = [
                'label' => Craft::t('typesense', 'Dashboard'),
                'url' => 'typesense/dashboard'
            ];
        }
        if ($currentUser->can('typesense:collections')) {
            $subNavs['collections'] = [
                'label' => Craft::t('typesense', 'Collections'),
                'url' => 'typesense/collections'
            ];
        }

        $editableSettings = true;
        // Check against allowAdminChanges
        if ( !Craft::$app->getConfig()->getGeneral()->allowAdminChanges ) {
            $editableSettings = false;
        }

        if ($currentUser->can('typesense:plugin-settings') && $editableSettings) {
            $subNavs['plugin'] = [
                'label' => Craft::t('typesense', 'Plugin settings'),
                'url' => 'typesense/plugin',
            ];
        }

        $navItem = array_merge($navItem, [
            'subnav' => $subNavs,
        ]);

        return $navItem;
    }

    // Protected Methods
    // =========================================================================

    /**
     *
     */
    protected function installEventListeners()
    {
        $request = Craft::$app->getRequest();
        // Install our event listeners
        if ($request->getIsCpRequest() && !$request->getIsConsoleRequest()) {
            $this->installCpEventListeners();
        }
        $this->_registerProjectConfigEventListeners();
    }

    /**
     * Install site event listeners for Control Panel requests only
     */
    protected function installCpEventListeners()
    {

        // Handler: UrlManager::EVENT_REGISTER_CP_URL_RULES
        Event::on(
            UrlManager::class,
            UrlManager::EVENT_REGISTER_CP_URL_RULES,
            function (RegisterUrlRulesEvent $event) {
                Craft::debug(
                    'UrlManager::EVENT_REGISTER_CP_URL_RULES',
                    __METHOD__
                );
                // Register our Control Panel routes
                $event->rules = array_merge(
                    $event->rules,
                    $this->customAdminCpRoutes()
                );
            }
        );

        // Handler: UserPermissions::EVENT_REGISTER_PERMISSIONS
        Event::on(
            UserPermissions::class,
            UserPermissions::EVENT_REGISTER_PERMISSIONS,
            function (RegisterUserPermissionsEvent $event) {
                Craft::debug(
                    'UserPermissions::EVENT_REGISTER_PERMISSIONS',
                    __METHOD__
                );
                // Register our custom permissions
                $event->permissions[Craft::t('typesense', 'Typesense')] = $this->customAdminCpPermissions();
            }
        );
    }

    /**
     * Creates and returns the model used to store the plugin’s settings.
     *
     * @return Model|null
     */
    protected function createSettingsModel()
    {
        return new Settings();
    }

    /**
     * Return the custom Control Panel routes
     *
     * @return array
     */
    protected function customAdminCpRoutes(): array
    {
        return [
            'typesense' => 'typesense/settings/dashboard',
            'typesense/dashboard' => 'typesense/settings/dashboard',
            'typesense/plugin' => 'typesense/settings/plugin',
            'typesense/collections' => 'typesense/collections/collections',
            'typesense/save-collection' => 'typesense/collections/save-collection',
            'typesense/sync-collection' => 'typesense/collections/sync-collection',
            'typesense/flush-collection' => 'typesense/collections/flush-collection',
        ];
    }

    /**
     * Return the custom Control Panel user permissions.
     *
     * @return array
     */
    protected function customAdminCpPermissions(): array
    {
        return [
            'typesense:dashboard' => [
                'label' => Craft::t('typesense', 'Dashboard'),
            ],
            'typesense:collections' => [
                'label' => Craft::t('typesense', 'Collections'),
            ],
            'typesense:manage-collections' => [
                'label' => Craft::t('typesense', 'Manage Collections'),
            ],
            'typesense:plugin-settings' => [
                'label' => Craft::t('typesense', 'Edit Plugin Settings'),
            ]
        ];
    }

    /**
     * Register Typesense’s project config event listeners
     */
    private function _registerProjectConfigEventListeners() {
        $projectConfigService = Craft::$app->getProjectConfig();

        $collectionService = $this->getCollections();
        $projectConfigService
            ->onAdd(CollectionService::CONFIG_COLLECTIONS_KEY, [$collectionService, 'handleChangedCollection'])
            ->onUpdate(CollectionService::CONFIG_COLLECTIONS_KEY, [$collectionService, 'handleChangedCollection'])
            ->onRemove(CollectionService::CONFIG_COLLECTIONS_KEY, [$collectionService, 'handleDeletedCollection']);

        Event::on(ProjectConfig::class, ProjectConfig::EVENT_REBUILD, function(RebuildConfigEvent $event) {
            $event->config['typesense'] = ProjectConfigData::rebuildProjectConfig();
        });
    }

    /**
     * Set all the after events to upsert/delete the documents
     */
    private function _registerEventHandlers(): void
    {
        $events = [
            [Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT],
            [Elements::class, Elements::EVENT_AFTER_RESTORE_ELEMENT],
            [Elements::class, Elements::EVENT_AFTER_UPDATE_SLUG_AND_URI],
        ];

        foreach ($events as $event) {
            Event::on(
                $event[0],
                $event[1],
                function (ElementEvent $event) {
                    $entry = $event->element;
                    $section = $entry->section->handle ?? null;

                    if (ElementHelper::isDraftOrRevision($entry) && !$section) {
                        // don’t do anything with drafts or revisions
                        return;
                    }

                    $collection = CollectionHelper::getCollection($section);

                    if($collection) {
                        Craft::$container->get(TypesenseClient::class)->collections[$section]->documents->upsert($collection->schema['resolver']($entry));
                    }

//                    Craft::dd(Craft::$container->get(TypesenseClient::class)->collections[$section]->documents->export());
                }
            );
        }

        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_DELETE_ELEMENT,
            function (ElementEvent $event) {
                $entry = $event->element;
                $section = $entry->section->handle;
                $id = $entry->id;

                if (ElementHelper::isDraftOrRevision($entry)) {
                    // don’t do anything with drafts or revisions
                    return;
                }

                $collection = CollectionHelper::getCollection($section);

                if($collection) {
                    Craft::$container->get(TypesenseClient::class)->collections[$section]->documents->delete(['filter_by' => 'id: '.$id]);
                }

//                Craft::dd(Craft::$container->get(TypesenseClient::class)->collections[$section]->documents->export());
            }
        );
    }

    /**
     * @throws MissingComponentException
     */
    private function _createTypesenseClient(): void
    {
        try {
            if ($this::$settings->serverType === 'server' && App::parseEnv($this::$settings->apiKey)) {
                Craft::$container->setSingleton(TypesenseClient::class, function() {
                    return new TypesenseClient([
                        'api_key' => App::parseEnv($this::$settings->apiKey),
                        'nodes' => [
                            [
                                'host' => App::parseEnv($this::$settings->server),
                                'port' => App::parseEnv($this::$settings->port),
                                'protocol' => App::parseEnv($this::$settings->protocol),
                            ],
                        ],
                        'connection_timeout_seconds' => 2,
                    ]);
                });
            } else if ($this::$settings->serverType === 'cluster' && App::parseEnv($this::$settings->apiKey)) {
                Craft::$container->setSingleton(TypesenseClient::class, function() {
                    return new TypesenseClient([
                        'api_key' => App::parseEnv($this::$settings->apiKey),
                        'nodes' => $this->_createNodes($this::$settings),
                        'connection_timeout_seconds' => 2,
                    ]);
                });
            } else if ($this::$settings->serverType === 'cloud' && App::parseEnv($this::$settings->apiKey)) {
                // Currently nothing!
            } else {
                Craft::$app->getSession()->setNotice(Craft::t('typesense', 'Please provide your typesense API key in the settings to get started'));
            }

            if(App::parseEnv($this::$settings->apiKey)) {
                // Save Typesense collections out of the config
                self::$plugin->collections->saveCollections();
            }
        } catch( \Exception $e ) {
            Craft::$app->getSession()->setError(Craft::t('typesense', 'There was an error with the Typesense Client Connection, check the logs'));
            Craft::error($e->getMessage(), __METHOD__);
        }
    }

    private function _createNodes(Settings $settings): array {
        $typesenseNodes = explode(";", App::parseEnv($this::$settings->cluster));
        $nodes = [];

        foreach ($typesenseNodes as $node) {
            $nodes[] = [
                'host'      => $node,
                'port'      => App::parseEnv($this::$settings->clusterPort),
                'protocol'  => 'https', //App::parseEnv($this::$settings->protocol),
            ];
        }

        return $nodes;
    }

}
