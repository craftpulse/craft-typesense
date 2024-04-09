<?php
/**
 * Typesense plugin for Craft CMS 4.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://percipio.london
 * @copyright Copyright (c) 2021 percipiolondon
 */

namespace percipiolondon\typesense;

use Craft;
use craft\base\Model;
use craft\base\Plugin;
use craft\console\Application as ConsoleApplication;
use craft\elements\Entry;
use craft\events\ElementEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\ElementHelper;
use craft\helpers\UrlHelper;
use craft\services\Elements;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;

use percipiolondon\typesense\base\PluginTrait;
use percipiolondon\typesense\helpers\CollectionHelper;
use percipiolondon\typesense\helpers\FileLog;
use percipiolondon\typesense\helpers\ProjectConfigDataHelper;
use percipiolondon\typesense\models\Settings;
use percipiolondon\typesense\services\CollectionService;
use percipiolondon\typesense\services\TypesenseService;
use percipiolondon\typesense\variables\TypesenseVariable;


use Typesense\Exceptions\ObjectNotFound;
use Typesense\Exceptions\ServerError;
use yii\base\Event;
use yii\db\Expression;

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
 */
class Typesense extends Plugin
{
    // Static Properties
    // =========================================================================

    /**
     * Static property that is an instance of this plugin class so that it can be accessed via
     * self::$plugin
     *
     * @var Typesense
     */
    public static Typesense $plugin;

    /**
     * @var Settings|Model|null
     */
    public static Settings|Model|null $settings = null;

    // Public Properties
    // =========================================================================

    /**
     * To execute your plugin’s migrations, you’ll need to increase its schema version.
     *
     * @var string
     */
    public string $schemaVersion = '1.0.0';

    /**
     * Set to `true` if the plugin should have its own section (main nav item) in the control panel.
     *
     * @var bool
     */
    public bool $hasCpSection = true;

    /**
     * Set to `true` if the plugin should have a settings view in the control panel.
     *
     * @var bool
     */
    public bool $hasCpSettings = true;

    use PluginTrait;

    // Public Methods
    // =========================================================================

    /**
     * Set our $plugin static property to this class so that it can be accessed via
     * self::$plugin
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

        $this->_registerComponents();
        $this->installEventListeners();
        $this->_registerEventHandlers();
        $this->_registerVariable();

        // Add in our console commands
        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'percipiolondon\typesense\console\controllers';
        }

        // Create endpoint for custom logs
        FileLog::create('typesense', 'percipiolondon\craft-typesense\*');

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
    //    public function getSettings()
//    {
//        return parent::getSettings();
//    }

    /**
     * @inheritdoc
     */
    public function getSettingsResponse(): mixed
    {
        // redirect to plugin settings page
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('typesense/plugin'));
    }

    /**
     * @inheritdoc
     */
    public function getCpNavItem(): ?array
    {
        $subNavs = [];
        $navItem = parent::getCpNavItem();
        Craft::$app->getUser()->getIdentity();

        // Only show sub navigation the user has permission to view
        if (Craft::$app->getUser()->checkPermission('typesense:dashboard')) {
            $subNavs['dashboard'] = [
                'label' => Craft::t('typesense', 'Dashboard'),
                'url' => 'typesense/dashboard',
            ];
        }

        if (Craft::$app->getUser()->checkPermission('typesense:collections')) {
            $subNavs['collections'] = [
                'label' => Craft::t('typesense', 'Collections'),
                'url' => 'typesense/collections',
            ];
        }

        //        if (Craft::$app->getUser()->checkPermission('typesense:collections')) {
//            $subNavs['documents'] = [
//                'label' => Craft::t('typesense', 'Documents'),
//                'url' => 'typesense/documents',
//            ];
//        }

        $editableSettings = true;
        // Check against allowAdminChanges
        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $editableSettings = false;
        }

        if (Craft::$app->getUser()->checkPermission('typesense:plugin-settings') && $editableSettings) {
            $subNavs['plugin'] = [
                'label' => Craft::t('typesense', 'Plugin settings'),
                'url' => 'typesense/plugin',
            ];
        }

        return array_merge($navItem, [
            'subnav' => $subNavs,
        ]);
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
                $event->permissions[] = [
                    'heading' => Craft::t('typesense', 'Typesense'),
                    'permissions' => $this->customAdminCpPermissions()
                ];
            }
        );
    }

    /**
     * Creates and returns the model used to store the plugin’s settings.
     */
    protected function createSettingsModel(): ?\craft\base\Model
    {
        return new Settings();
    }

    /**
     * Return the custom Control Panel routes
     */
    protected function customAdminCpRoutes(): array
    {
        return [
            'typesense' => 'typesense/settings/dashboard',
            'typesense/dashboard' => 'typesense/settings/dashboard',
            'typesense/plugin' => 'typesense/settings/plugin',
            'typesense/collections' => 'typesense/collections/collections',
            'typesense/documents' => 'typesense/collections/documents',
            'typesense/documents/<sectionId:\d+>' => 'typesense/collections/document',
            'typesense/save-collection' => 'typesense/collections/save-collection',
            'typesense/sync-collection' => 'typesense/collections/sync-collection',
            'typesense/flush-collection' => 'typesense/collections/flush-collection',
        ];
    }

    /**
     * Return the custom Control Panel user permissions.
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
            ],
        ];
    }

    /**
     * Register Typesense’s project config event listeners
     */
    private function _registerProjectConfigEventListeners()
    {
        // $projectConfigService = Craft::$app->getProjectConfig();

        // $collectionService = self::$plugin->getCollections();
        // $projectConfigService
        //     ->onAdd(CollectionService::CONFIG_COLLECTIONS_KEY, [$collectionService, 'handleChangedCollection'])
        //     ->onUpdate(CollectionService::CONFIG_COLLECTIONS_KEY, [$collectionService, 'handleChangedCollection'])
        //     ->onRemove(CollectionService::CONFIG_COLLECTIONS_KEY, [$collectionService, 'handleDeletedCollection']);

        // Event::on(ProjectConfig::class, ProjectConfig::EVENT_REBUILD, function(RebuildConfigEvent $event) {
        //     $event->config['typesense'] = ProjectConfigDataHelper::rebuildProjectConfig();
        // });
    }

    /**
     * Set all the after events to upsert/delete the documents
     */
    private function _registerEventHandlers(): void
    {
        /* SAVE EVENTS */
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
                    // Ignore any element that is not an entry
                    if (!($event->element instanceof Entry)) {
                        return;
                    }

                    $entry = $event->element;
                    $id = $entry->id;
                    $sectionHande = $entry->section->handle ?? null;
                    $type = $entry->type->handle ?? null;
                    $collection = null;

                    if (ElementHelper::isDraftOrRevision($entry)) {
                        // don’t do anything with drafts or revisions
                        return;
                    }

                    if ($sectionHande) {
                        $section = '';

                        if ($type) {
                            $section = $sectionHande . '.' . $type;
                        }

                        $collection = CollectionHelper::getCollectionBySection($section);

                        // get the generic type if specific doesn't exist
                        if (is_null($collection)) {
                            $section = $sectionHande . '.all';
                            $collection = CollectionHelper::getCollectionBySection($section);
                        }

                        //create collection if it doesn't exist
                        if (!$collection instanceof \percipiolondon\typesense\TypesenseCollectionIndex) {
                            self::$plugin->getCollections()->saveCollections();
                            $collection = CollectionHelper::getCollectionBySection($section);
                        }
                    }

                    if (($entry->enabled && $entry->getEnabledForSite()) && $entry->getStatus() === 'live') {
                        // element is enabled --> save to Typesense
                        if ($collection !== null) {
                            Craft::info('Typesense edit / add / delete document based of: ' . $entry->title, __METHOD__);

                            try {
                                $resolver = $collection->schema['resolver']($entry);

                                if ($resolver) {
                                    self::$plugin->getClient()->client()->collections[$collection->indexName]->documents->upsert($resolver);
                                }
                            } catch (ObjectNotFound | ServerError $e) {
                                Craft::$app->session->setFlash('error', Craft::t('typesense', 'There was an issue saving your action, check the logs for more info'));
                                Craft::error($e->getMessage(), __METHOD__);
                            }
                        }
                    } else {
                        // element is disabled --> delete from Typesense
                        if ($collection !== null) {
                            self::$plugin->getClient()->client()->collections[$collection->indexName]->documents->delete(['filter_by' => 'id: ' . $id]);
                        }
                    }
                }
            );
        }

        /* DELETE EVENT */
        Event::on(
            Elements::class,
            Elements::EVENT_AFTER_DELETE_ELEMENT,
            function (ElementEvent $event) {
                $entry = $event->element;
                $section = $entry->section->handle ?? null;
                $id = $entry->id;
                $type = $entry->type->handle ?? null;
                $collection = null;

                if (ElementHelper::isDraftOrRevision($entry)) {
                    // don’t do anything with drafts or revisions
                    return;
                }

                if ($section) {
                    if ($type) {
                        $section = $section . '.' . $type;
                    }

                    $collection = CollectionHelper::getCollectionBySection($section);

                    //create collection if it doesn't exist
                    if (!$collection instanceof \percipiolondon\typesense\TypesenseCollectionIndex) {
                        self::$plugin->getCollections()->saveCollections();
                        $collection = CollectionHelper::getCollectionBySection($section);
                    }
                }

                if ($collection !== null) {
                    self::$plugin->getClient()->client()->collections[$collection->indexName]->documents->delete(['filter_by' => 'id: ' . $id]);
                }
            }
        );
    }

    private function _registerVariable(): void
    {
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function (Event $event) {
            /** @var CraftVariable $variable */
            $variable = $event->sender;
            $variable->set('typesense', [
                'class' => TypesenseVariable::class,
                'viteService' => $this->getVite(),
            ]);
        });
    }
}
