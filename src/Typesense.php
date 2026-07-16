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
use craft\base\ElementInterface;
use craft\base\Model;
use craft\base\Plugin;
use craft\console\Application as ConsoleApplication;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\events\DefineFieldLayoutCustomFieldsEvent;
use craft\events\DefineFieldLayoutFieldsEvent;
use craft\events\DefineHtmlEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterCpAlertsEvent;
use craft\events\RegisterElementActionsEvent;
use craft\events\RegisterGqlQueriesEvent;
use craft\events\RegisterTemplateRootsEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\Cp;
use craft\helpers\UrlHelper;
use craft\models\FieldLayout;
use craft\queue\BaseJob;
use craft\queue\Queue;
use craft\services\Dashboard;
use craft\services\Gql;
use craft\services\UserPermissions;
use craft\services\Utilities;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use craft\web\View;
use craftpulse\typesense\base\PluginTrait;
use craftpulse\typesense\controllers\AiProvidersController;
use craftpulse\typesense\controllers\AliasesController;
use craftpulse\typesense\controllers\AnalyticsController;
use craftpulse\typesense\controllers\CollectionsController;
use craftpulse\typesense\controllers\CurationController;
use craftpulse\typesense\controllers\DictionariesController;
use craftpulse\typesense\controllers\ExperimentsController;
use craftpulse\typesense\controllers\KeysController;
use craftpulse\typesense\controllers\OpsController;
use craftpulse\typesense\controllers\PlaygroundController;
use craftpulse\typesense\controllers\RelevanceController;
use craftpulse\typesense\controllers\SettingsController;
use craftpulse\typesense\controllers\SynonymsController;
use craftpulse\typesense\elementactions\Reindex;
use craftpulse\typesense\elementactions\ViewInSearch;
use craftpulse\typesense\fieldlayoutelements\MappingField;
use craftpulse\typesense\fieldlayoutelements\NativeMappingField;
use craftpulse\typesense\gql\queries\SearchQuery;
use craftpulse\typesense\helpers\FileLog;
use craftpulse\typesense\models\CollectionDefinition;
use craftpulse\typesense\models\Settings;
use craftpulse\typesense\services\Client;
use craftpulse\typesense\utilities\TypesenseUtility;
use craftpulse\typesense\variables\TypesenseVariable;
use craftpulse\typesense\widgets\HealthWidget;
use yii\base\Event;


use yii\queue\ExecEvent;

/**
 * The Typesense plugin: synchronises Craft elements into a Typesense search
 * server. Wires the service components, control-panel routes, nav, permissions,
 * element actions, and event listeners, and exposes the settings and edition.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 *
 * @property  Client $client
 *
 * @property  Settings $settings
 * @property-read bool $isPro
 */
class Typesense extends Plugin
{
    // Constants
    // =========================================================================

    /**
     * @var string The Free edition handle: the complete search engine, no
     * control-panel authoring.
     */
    public const EDITION_FREE = 'free';

    /**
     * @var string The Pro edition handle: adds the control-panel authoring layer
     * (collections cockpit, mapping UI, curation, analytics, keys).
     */
    public const EDITION_PRO = 'pro';

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
    public string $schemaVersion = '5.9.6';

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

    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @return array<int, string>
     */
    public static function editions(): array
    {
        return [
            self::EDITION_FREE,
            self::EDITION_PRO,
        ];
    }

    // Public Methods
    // =========================================================================

    /**
     * Whether the active edition is Pro, gating the control-panel authoring
     * layer. Accessible as the `isPro` magic property.
     *
     * @return bool
     * @author CraftPulse
     */
    public function getIsPro(): bool
    {
        return $this->is(self::EDITION_PRO);
    }

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
        $this->_registerSiteTemplateRoots();
        $this->_registerCurationSidebar();
        $this->_registerInspectorSidebar();
        $this->_registerMappingPalette();

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
        /** @var \craft\web\Response $response */
        $response = Craft::$app->getResponse();

        return $response->redirect(UrlHelper::cpUrl('typesense/settings'));
    }

    /**
     * @inheritdoc
     * @return array<string, mixed>|null
     */
    public function getCpNavItem(): ?array
    {
        $navItem = parent::getCpNavItem();

        if ($navItem === null) {
            return null;
        }

        $subNavs = [];
        $currentUser = Craft::$app->getUser();

        // Pro control-panel screens surface only in the Pro edition (hide, never
        // badge), and then only for users holding the matching permission.
        if ($this->getIsPro()) {
            // The Collections screen is the home for everything per-collection
            // (settings, mapping, relevance, vector/AI as tabs), so its nav item
            // shows for either collection-screen family; each tab is gated
            // per-action, and the tab strip hides tabs the viewer cannot access.
            if (
                $currentUser->checkPermission(CollectionsController::PERMISSION_MANAGE_COLLECTIONS)
                || $currentUser->checkPermission(RelevanceController::PERMISSION_MANAGE_RELEVANCE)
                || $currentUser->checkPermission(SynonymsController::PERMISSION_MANAGE_SYNONYMS)
                || $currentUser->checkPermission(CurationController::PERMISSION_MANAGE_CURATION)
            ) {
                $subNavs['collections'] = [
                    'label' => Craft::t('typesense', 'Collections'),
                    'url' => 'typesense/collections',
                ];
            }

            if ($currentUser->checkPermission(AliasesController::PERMISSION_MANAGE_ALIASES)) {
                $subNavs['aliases'] = [
                    'label' => Craft::t('typesense', 'Aliases'),
                    'url' => 'typesense/aliases',
                ];
            }

            if ($currentUser->checkPermission(ExperimentsController::PERMISSION_MANAGE_EXPERIMENTS)) {
                $subNavs['experiments'] = [
                    'label' => Craft::t('typesense', 'A/B experiments'),
                    'url' => 'typesense/experiments',
                ];
            }

            if ($currentUser->checkPermission(DictionariesController::PERMISSION_MANAGE_DICTIONARIES)) {
                $subNavs['dictionaries'] = [
                    'label' => Craft::t('typesense', 'Dictionaries'),
                    'url' => 'typesense/dictionaries',
                ];
            }

            if ($currentUser->checkPermission(KeysController::PERMISSION_MANAGE_KEYS)) {
                $subNavs['keys'] = [
                    'label' => Craft::t('typesense', 'API keys'),
                    'url' => 'typesense/keys',
                ];
            }

            if ($currentUser->checkPermission(AnalyticsController::PERMISSION_VIEW_ANALYTICS)) {
                $subNavs['analytics'] = [
                    'label' => Craft::t('typesense', 'Analytics'),
                    'url' => 'typesense/analytics',
                ];
            }

            if ($currentUser->checkPermission(AiProvidersController::PERMISSION_MANAGE_AI_PROVIDERS)) {
                $subNavs['ai-providers'] = [
                    'label' => Craft::t('typesense', 'AI providers'),
                    'url' => 'typesense/ai-providers',
                ];
            }

            // The playground sits at the bottom of the Pro subnav, directly above
            // Settings (Michael's Fix 11 ruling).
            if ($currentUser->checkPermission(PlaygroundController::PERMISSION_VIEW_DIAGNOSTICS)) {
                $subNavs['playground'] = [
                    'label' => Craft::t('typesense', 'Playground'),
                    'url' => 'typesense/playground',
                ];
            }
        }

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
            function(RegisterUrlRulesEvent $event) {
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
            function(RegisterUserPermissionsEvent $event) {
                Craft::debug(
                    'UserPermissions::EVENT_REGISTER_PERMISSIONS',
                    __METHOD__
                );
                // Register our custom permissions
                $event->permissions[] = [
                    'heading' => Craft::t('typesense', 'Typesense'),
                    'permissions' => $this->customAdminCpPermissions(),
                ];
            }
        );

        // Handler: Cp::EVENT_REGISTER_ALERTS (Cockpit pairing warning)
        Event::on(
            Cp::class,
            Cp::EVENT_REGISTER_ALERTS,
            function(RegisterCpAlertsEvent $event) {
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
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = TypesenseUtility::class;
            }
        );

        // Handler: Dashboard::EVENT_REGISTER_WIDGET_TYPES
        Event::on(
            Dashboard::class,
            Dashboard::EVENT_REGISTER_WIDGET_TYPES,
            function(RegisterComponentTypesEvent $event) {
                $event->types[] = HealthWidget::class;
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
                function(RegisterElementActionsEvent $event) {
                    $event->actions[] = Reindex::class;
                    $event->actions[] = ViewInSearch::class;
                }
            );
        }

        // Handler: Gql::EVENT_REGISTER_GQL_QUERIES (Free Typesense search query)
        Event::on(
            Gql::class,
            Gql::EVENT_REGISTER_GQL_QUERIES,
            function(RegisterGqlQueriesEvent $event) {
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
        $routes = [
            'typesense' => 'typesense/settings/edit',
            'typesense/settings' => 'typesense/settings/edit',
        ];

        // Pro routes are registered only in the Pro edition (hide, never badge).
        if ($this->getIsPro()) {
            $routes['typesense/collections'] = 'typesense/collections/index';
            $routes['typesense/collections/new'] = 'typesense/collections/edit';
            $routes['typesense/collections/config/<name:[\w\-]+>/synonyms/new'] = 'typesense/synonyms/edit-synonym';
            $routes['typesense/collections/config/<name:[\w\-]+>/synonyms/synonym/<synonymId:[\w\-]+>'] = 'typesense/synonyms/edit-synonym';
            $routes['typesense/collections/config/<name:[\w\-]+>/synonyms'] = 'typesense/synonyms/list';
            $routes['typesense/collections/config/<name:[\w\-]+>/curation/new'] = 'typesense/curation/edit-rule';
            $routes['typesense/collections/config/<name:[\w\-]+>/curation/rule/<ruleId:[\w\-]+>'] = 'typesense/curation/edit-rule';
            $routes['typesense/collections/config/<name:[\w\-]+>/curation'] = 'typesense/curation/list';
            $routes['typesense/collections/config/<name:[\w\-]+>'] = 'typesense/collections/config-overview';
            $routes['typesense/collections/<uid:[\w\-]+>/mapping'] = 'typesense/collections/mapping';
            $routes['typesense/collections/<uid:[\w\-]+>/relevance'] = 'typesense/relevance/edit';
            $routes['typesense/collections/<uid:[\w\-]+>/vector'] = 'typesense/relevance/vector';
            $routes['typesense/collections/<uid:[\w\-]+>/synonyms/new'] = 'typesense/synonyms/edit-synonym';
            $routes['typesense/collections/<uid:[\w\-]+>/synonyms/synonym/<synonymId:[\w\-]+>'] = 'typesense/synonyms/edit-synonym';
            $routes['typesense/collections/<uid:[\w\-]+>/synonyms'] = 'typesense/synonyms/list';
            $routes['typesense/collections/<uid:[\w\-]+>/curation/new'] = 'typesense/curation/edit-rule';
            $routes['typesense/collections/<uid:[\w\-]+>/curation/rule/<ruleId:[\w\-]+>'] = 'typesense/curation/edit-rule';
            $routes['typesense/collections/<uid:[\w\-]+>/curation'] = 'typesense/curation/list';
            $routes['typesense/collections/<uid:[\w\-]+>'] = 'typesense/collections/edit';
            $routes['typesense/playground'] = 'typesense/playground/index';
            $routes['typesense/playground/<collection:[\w\-]+>/browse'] = 'typesense/playground/browse';
            $routes['typesense/playground/<collection:[\w\-]+>'] = 'typesense/playground/index';
            $routes['typesense/aliases'] = 'typesense/aliases/index';
            $routes['typesense/experiments'] = 'typesense/experiments/index';
            $routes['typesense/experiments/new'] = 'typesense/experiments/edit';
            $routes['typesense/experiments/<handle:[\w\-]+>'] = 'typesense/experiments/edit';
            $routes['typesense/dictionaries'] = 'typesense/dictionaries/index';
            $routes['typesense/dictionaries/stemming'] = 'typesense/dictionaries/stemming';
            $routes['typesense/dictionaries/<collection:[\w\-]+>'] = 'typesense/dictionaries/stopwords';
            $routes['typesense/keys'] = 'typesense/keys/index';
            $routes['typesense/keys/new'] = 'typesense/keys/create';
            $routes['typesense/keys/profile'] = 'typesense/keys/edit-profile';
            $routes['typesense/keys/profile/<handle:[\w\-]+>'] = 'typesense/keys/edit-profile';
            $routes['typesense/analytics'] = 'typesense/analytics/index';
            $routes['typesense/ai-providers'] = 'typesense/ai-providers/index';
            $routes['typesense/ai-providers/provider/new'] = 'typesense/ai-providers/edit-provider';
            $routes['typesense/ai-providers/provider/<handle:[\w\-]+>'] = 'typesense/ai-providers/edit-provider';
            $routes['typesense/ai-providers/model/new'] = 'typesense/ai-providers/edit-model';
            $routes['typesense/ai-providers/model/<handle:[\w\-]+>'] = 'typesense/ai-providers/edit-model';
        }

        return $routes;
    }

    /**
     * Return the custom Control Panel user permissions.
     *
     * @return array<string, array<string, string>>
     */
    protected function customAdminCpPermissions(): array
    {
        $permissions = [
            SettingsController::PERMISSION_MANAGE_SETTINGS => [
                'label' => Craft::t('typesense', 'Manage plugin settings'),
            ],
        ];

        // Pro permissions are registered only in the Pro edition (hide, never
        // badge). One granular handle per screen family, no umbrellas.
        if ($this->getIsPro()) {
            $permissions[CollectionsController::PERMISSION_MANAGE_COLLECTIONS] = [
                'label' => Craft::t('typesense', 'Manage collections and mappings'),
            ];
            $permissions[RelevanceController::PERMISSION_MANAGE_RELEVANCE] = [
                'label' => Craft::t('typesense', 'Manage relevance'),
            ];
            $permissions[SynonymsController::PERMISSION_MANAGE_SYNONYMS] = [
                'label' => Craft::t('typesense', 'Manage synonyms'),
            ];
            $permissions[CurationController::PERMISSION_MANAGE_CURATION] = [
                'label' => Craft::t('typesense', 'Manage curation'),
            ];
            $permissions[DictionariesController::PERMISSION_MANAGE_DICTIONARIES] = [
                'label' => Craft::t('typesense', 'Manage dictionaries'),
            ];
            $permissions[AliasesController::PERMISSION_MANAGE_ALIASES] = [
                'label' => Craft::t('typesense', 'Manage aliases and rebuilds'),
            ];
            $permissions[ExperimentsController::PERMISSION_MANAGE_EXPERIMENTS] = [
                'label' => Craft::t('typesense', 'Manage A/B experiments'),
            ];
            $permissions[KeysController::PERMISSION_MANAGE_KEYS] = [
                'label' => Craft::t('typesense', 'Manage API keys'),
            ];
            $permissions[AnalyticsController::PERMISSION_VIEW_ANALYTICS] = [
                'label' => Craft::t('typesense', 'View analytics'),
            ];
            $permissions[AiProvidersController::PERMISSION_MANAGE_AI_PROVIDERS] = [
                'label' => Craft::t('typesense', 'Manage AI providers and conversation models'),
            ];
            $permissions[PlaygroundController::PERMISSION_VIEW_DIAGNOSTICS] = [
                'label' => Craft::t('typesense', 'Use the search playground and diagnostics'),
            ];
            $permissions[OpsController::PERMISSION_MANAGE_OPS] = [
                'label' => Craft::t('typesense', 'Run sync operations (sync, flush, suspend, rebuild ops)'),
            ];
        }

        return $permissions;
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
            function(ExecEvent $event) {
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
        Event::on(CraftVariable::class, CraftVariable::EVENT_INIT, function(Event $event) {
            /** @var CraftVariable $variable */
            $variable = $event->sender;
            $variable->set('typesense', TypesenseVariable::class);
        });
    }

    /**
     * Registers the `_typesense` site template root, which holds the front-end
     * search fragment partials the fragment endpoint renders. A project can
     * override any of them by placing a same-named template under
     * `templates/_typesense/`.
     *
     * @return void
     * @author CraftPulse
     */
    /**
     * Registers the entry-edit curation sidebar panel (Pro + manageCuration),
     * listing the element's pins and offering a quick-pin. Renders nothing for
     * Free, for users without the permission, or for elements that match no
     * control-panel-managed curation collection.
     *
     * @return void
     * @author CraftPulse
     */
    /**
     * Scopes the field-layout designer palette to a collection's mapping layout:
     * the source's custom fields (and Commerce variant fields) become the custom
     * palette, and the asset pseudo fields plus registered computed fields become
     * native fields. Both events fire for every layout in the CMS, so each bails
     * unless the layout's provider is a collection definition.
     *
     * @return void
     * @author CraftPulse
     */
    private function _registerMappingPalette(): void
    {
        Event::on(
            FieldLayout::class,
            FieldLayout::EVENT_DEFINE_CUSTOM_FIELDS,
            function(DefineFieldLayoutCustomFieldsEvent $event) {
                $provider = $event->sender->provider ?? null;

                if (!$provider instanceof CollectionDefinition) {
                    return;
                }

                $sources = $this->getMappingSources();
                $fields = [];

                foreach ($sources->customFieldsFor($provider) as $field) {
                    $fields[] = MappingField::forField($field, 'field');
                }

                foreach ($sources->variantFieldsFor($provider) as $field) {
                    $fields[] = MappingField::forField($field, 'variant');
                }

                $event->fields = [Craft::t('typesense', 'Fields') => $fields];
            }
        );

        Event::on(
            FieldLayout::class,
            FieldLayout::EVENT_DEFINE_NATIVE_FIELDS,
            function(DefineFieldLayoutFieldsEvent $event) {
                $provider = $event->sender->provider ?? null;

                if (!$provider instanceof CollectionDefinition) {
                    return;
                }

                if (is_a($provider->elementType, Asset::class, true)) {
                    foreach ($this->getMappingSources()->assetPseudoFields() as $pseudo) {
                        $event->fields[] = new NativeMappingField([
                            'handle' => (string)$pseudo['handle'],
                            'label' => (string)$pseudo['label'],
                            'derivedType' => (string)$pseudo['derivedType'],
                            'kind' => 'pseudo',
                        ]);
                    }
                }

                foreach ($this->getSchema()->getComputedFields() as $computed) {
                    $event->fields[] = new NativeMappingField([
                        'handle' => $computed->name,
                        'label' => $computed->name,
                        'derivedType' => $computed->type,
                        'kind' => 'computed',
                    ]);
                }
            }
        );
    }

    private function _registerCurationSidebar(): void
    {
        Event::on(
            Element::class,
            Element::EVENT_DEFINE_SIDEBAR_HTML,
            function(DefineHtmlEvent $event) {
                if (!$this->getIsPro()) {
                    return;
                }

                if (!Craft::$app->getUser()->checkPermission(CurationController::PERMISSION_MANAGE_CURATION)) {
                    return;
                }

                $element = $event->sender;

                if (!$element instanceof ElementInterface || $element->id === null) {
                    return;
                }

                if (!$this->_registryTargetsElement($element)) {
                    return;
                }

                $collections = $this->_curatableCollections($element);

                if ($collections === []) {
                    return;
                }

                $event->html .= Craft::$app->getView()->renderTemplate('typesense/_sidebar/curation', [
                    'element' => $element,
                    'collections' => $collections,
                    'pins' => $this->getCurationIndex()->forElement((int)$element->id),
                ]);
            }
        );
    }

    /**
     * Registers the entry-edit inspector sidebar panel (Pro + manageCollections):
     * read-only diagnostics for the element, per collection (membership state,
     * last-indexed timestamp, live document JSON, skip reasons), from the
     * inspector service. Renders nothing for Free, for users without the
     * permission, or for elements that match no collection.
     *
     * @return void
     * @author CraftPulse
     */
    private function _registerInspectorSidebar(): void
    {
        Event::on(
            Element::class,
            Element::EVENT_DEFINE_SIDEBAR_HTML,
            function(DefineHtmlEvent $event) {
                if (!$this->getIsPro()) {
                    return;
                }

                if (!Craft::$app->getUser()->checkPermission(PlaygroundController::PERMISSION_VIEW_DIAGNOSTICS)) {
                    return;
                }

                $element = $event->sender;

                if (!$element instanceof ElementInterface || $element->id === null) {
                    return;
                }

                // Short-circuit before any query or Typesense call: most element
                // edits are for types no collection targets.
                if (!$this->_registryTargetsElement($element)) {
                    return;
                }

                $inspection = $this->getInspector()->inspectElement($element);

                if ($inspection['collections'] === []) {
                    return;
                }

                $event->html .= Craft::$app->getView()->renderTemplate('typesense/_sidebar/inspector', [
                    'inspection' => $inspection,
                ]);
            }
        );
    }

    /**
     * Whether any registered collection targets the element's type. A cheap
     * class-only check (no element queries, no Typesense calls) so the entry-edit
     * sidebars can bail immediately for element types no collection indexes,
     * which is the common case on most element edits.
     *
     * @param ElementInterface $element
     * @return bool
     * @author CraftPulse
     */
    private function _registryTargetsElement(ElementInterface $element): bool
    {
        foreach ($this->getCollectionRegistry()->getAll() as $collection) {
            $type = $collection->getElementType();

            if ($element instanceof $type) {
                return true;
            }
        }

        return false;
    }

    /**
     * The control-panel-managed curation collections an element is a member of.
     *
     * @param ElementInterface $element
     * @return array<int, string>
     * @author CraftPulse
     */
    private function _curatableCollections(ElementInterface $element): array
    {
        $curation = $this->getCuration();
        $sync = $this->getSync();
        $names = [];

        foreach ($this->getCollectionRegistry()->getAll() as $name => $collection) {
            $type = $collection->getElementType();

            if (!$element instanceof $type) {
                continue;
            }

            if ($curation->isConfigOwned($collection)) {
                continue;
            }

            if ($sync->matchesCollection($collection, $element)) {
                $names[] = $name;
            }
        }

        return $names;
    }

    private function _registerSiteTemplateRoots(): void
    {
        Event::on(
            View::class,
            View::EVENT_REGISTER_SITE_TEMPLATE_ROOTS,
            function(RegisterTemplateRootsEvent $event) {
                $event->roots['_typesense'] = $this->getBasePath() . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR . 'frontend';
            }
        );
    }
}
