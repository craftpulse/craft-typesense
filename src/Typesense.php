<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2025 CraftPulse
 */

namespace craftpulse\typesense;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin;
use craft\console\Application as ConsoleApplication;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\queue\BaseJob;
use craft\queue\Queue;
use craft\events\ElementEvent;
use yii\queue\ExecEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterCpAlertsEvent;
use craft\events\RegisterElementActionsEvent;
use craft\events\RegisterGqlQueriesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\Cp;
use craft\helpers\ElementHelper;
use craft\helpers\UrlHelper;
use craft\services\Elements;
use craft\services\Gql;
use craft\services\Utilities;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;

use craftpulse\typesense\base\PluginTrait;
use craftpulse\typesense\controllers\SettingsController;
use craftpulse\typesense\elementactions\Reindex;
use craftpulse\typesense\elementactions\ViewInSearch;
use craftpulse\typesense\gql\queries\SearchQuery;
use craftpulse\typesense\helpers\FileLog;
use craftpulse\typesense\models\Settings;
use craftpulse\typesense\services\Client;
use craftpulse\typesense\utilities\TypesenseUtility;
use craftpulse\typesense\variables\TypesenseVariable;


use Typesense\Exceptions\ObjectNotFound;
use Typesense\Exceptions\ServerError;
use yii\base\Event;

/**
 * Craft plugins are very much like little applications in and of themselves. We’ve made
 * it as simple as we can, but the training wheels are off. Little prior knowledge is
 * going to be required to write a plugin.
 *
 * For the purposes of the plugin docs, we’re going to assume that you know PHP and SQL,
 * as well as some semi-advanced concepts like object-oriented programming and PHP namespaces.
 *
 * https://docs.craftcms.com/v3/extend/
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     1.0.0
 *
 * @property  Client $client
 *
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
    public string $schemaVersion = '5.9.0';

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

    /**
     * Set to `true` so the plugin's settings link stays visible when
     * `allowAdminChanges` is off (the screen renders read-only).
     *
     * @var bool
     */
    public bool $hasReadOnlyCpSettings = true;

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
    public function init(): void
    {
        parent::init();
        self::$plugin = $this;

        $this->_registerComponents();
        $this->installEventListeners();
        $this->getSync()->registerEventListeners();
        $this->_registerSyncFailureAlerts();
        $this->_registerVariable();

        // Add in our console commands
        if (Craft::$app instanceof ConsoleApplication) {
            $this->controllerNamespace = 'craftpulse\typesense\console\controllers';
        } else {
            $this->controllerNamespace = 'craftpulse\typesense\controllers';
        }

        // Create endpoint for custom logs
        FileLog::create('typesense', 'craftpulse\craft-typesense\*');

        // init log
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
        return Craft::$app->getResponse()->redirect(UrlHelper::cpUrl('typesense/settings'));
    }

    /**
     * @inheritdoc
     * @return array<string, mixed>|null
     */
    public function getCpNavItem(): ?array
    {
        $subNavs = [];
        $navItem = parent::getCpNavItem();
        $currentUser = Craft::$app->getUser();

        // Only show sub navigation the user has permission to view. The
        // collections overview and synonyms now live in the Typesense utility
        // (Free) and, later, the Pro control-panel managers.

        // Gate on the permission, not on allowAdminChanges: the screen stays
        // reachable in read-only mode (it renders read-only there).
        if ($currentUser->checkPermission(SettingsController::PERMISSION_MANAGE_SETTINGS)) {
            $subNavs['settings'] = [
                'label' => Craft::t('typesense', 'Settings'),
                'url' => 'typesense/settings',
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
    protected function installEventListeners(): void
    {
        // Register CP URL rules and permissions unconditionally. The events only
        // fire in the relevant request contexts, and gating registration on the
        // boot-time request context hides the routes from console/queue and test
        // requests.
        $this->installCpEventListeners();

        $this->_registerProjectConfigEventListeners();
    }

    /**
     * Install site event listeners for Control Panel requests only
     */
    protected function installCpEventListeners(): void
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

        // Handler: Cp::EVENT_REGISTER_ALERTS (Cockpit pairing warning)
        Event::on(
            Cp::class,
            Cp::EVENT_REGISTER_ALERTS,
            function (RegisterCpAlertsEvent $event) {
                $warning = $this->getCompatibility()->cockpitPairingWarning();

                if ($warning !== null) {
                    $event->alerts[] = $warning;
                }
            }
        );

        // Handler: Utilities::EVENT_REGISTER_UTILITY_TYPES
        Event::on(
            Utilities::class,
            Utilities::EVENT_REGISTER_UTILITIES,
            function (RegisterComponentTypesEvent $event) {
                $event->types[] = TypesenseUtility::class;
            }
        );

        // Handler: Element::EVENT_REGISTER_ACTIONS (Reindex bulk action)
        $elementTypes = [Entry::class, Category::class, Asset::class];

        if (class_exists('craft\\commerce\\elements\\Product')) {
            $elementTypes[] = 'craft\\commerce\\elements\\Product';
        }

        foreach ($elementTypes as $elementType) {
            Event::on(
                $elementType,
                Element::EVENT_REGISTER_ACTIONS,
                function (RegisterElementActionsEvent $event) {
                    $event->actions[] = Reindex::class;
                    $event->actions[] = ViewInSearch::class;
                }
            );
        }

        // Handler: Gql::EVENT_REGISTER_GQL_QUERIES (Free Typesense search query)
        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_QUERIES,
            function (RegisterGqlQueriesEvent $event) {
                $event->queries = array_merge($event->queries, SearchQuery::getQueries());
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
     *
     * @return array<string, string>
     */
    protected function customAdminCpRoutes(): array
    {
        return [
            'typesense' => 'typesense/settings/edit',
            'typesense/settings' => 'typesense/settings/edit',
        ];
    }

    /**
     * Return the custom Control Panel user permissions.
     *
     * @return array<string, array<string, string>>
     */
    protected function customAdminCpPermissions(): array
    {
        return [
            SettingsController::PERMISSION_MANAGE_SETTINGS => [
                'label' => Craft::t('typesense', 'Manage plugin settings'),
            ],
        ];
    }

    /**
     * Register Typesense’s project config event listeners
     */
    private function _registerProjectConfigEventListeners(): void
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
     * Registers the sync-failure email alert on queue job errors.
     */
    private function _registerSyncFailureAlerts(): void
    {
        Event::on(
            Queue::class,
            Queue::EVENT_AFTER_ERROR,
            function (ExecEvent $event) {
                $job = $event->job;

                if (is_object($job) && str_starts_with($job::class, 'craftpulse\\typesense\\jobs\\')) {
                    $description = $job instanceof BaseJob ? (string)$job->getDescription() : $job::class;
                    $this->getNotifications()->notifyJobFailure($description, (string)$event->error?->getMessage());
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
