<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\Tag;
use craft\elements\User;
use craft\helpers\Cp;
use craft\helpers\StringHelper;
use craft\helpers\UrlHelper;
use craft\web\assets\cp\CpAsset;
use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\controllers\base\ProController;
use craftpulse\typesense\models\CollectionDefinition;
use craftpulse\typesense\Typesense;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The Pro collections cockpit: browse the CP-managed collections (alongside
 * read-only visibility of config-owned ones, flagged with the override notice),
 * and create, edit, and delete a CP-managed collection. Definitions persist to
 * project config through the ManagedCollections service. Gated by the edition
 * (via [[ProController]]) and the `typesense:manageCollections` permission.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class CollectionsController extends ProController
{
    // Constants
    // =========================================================================

    /**
     * @var string The permission that gates the collections cockpit.
     */
    public const PERMISSION_MANAGE_COLLECTIONS = 'typesense:manageCollections';

    // Static Methods
    // =========================================================================

    /**
     * The collection edit screen's inner-sidebar nav items, filtered to the
     * sections the current user may access: Settings and Mapping
     * (manageCollections), Relevance and Vector / AI (manageRelevance). A section
     * the viewer cannot access is omitted, so a user holding only one family sees
     * only its sections. A new (unsaved) collection has no nav (single Settings
     * pane). Each item keeps its own URL, controller action, and permission gate,
     * matching Craft core's user-settings screens
     * (vendor/craftcms/cms/src/templates/settings/users/_layout.twig).
     *
     * @param CollectionDefinition $definition
     * @return array<string, array{label: string, url: string}>
     * @author CraftPulse
     */
    public static function editScreenNavItems(CollectionDefinition $definition): array
    {
        if ($definition->uid === null) {
            return [];
        }

        $user = Craft::$app->getUser();
        $uid = $definition->uid;
        $items = [];

        if ($user->checkPermission(self::PERMISSION_MANAGE_COLLECTIONS)) {
            $items['settings'] = [
                'label' => Craft::t('typesense', 'Settings'),
                'url' => UrlHelper::cpUrl("typesense/collections/{$uid}"),
            ];

            // A union collection maps each member source separately (the Members
            // section); a regular collection maps its single source.
            if ($definition->isUnion()) {
                $items['members'] = [
                    'label' => Craft::t('typesense', 'Members'),
                    'url' => UrlHelper::cpUrl("typesense/collections/{$uid}/members"),
                ];
            } else {
                $items['mapping'] = [
                    'label' => Craft::t('typesense', 'Mapping'),
                    'url' => UrlHelper::cpUrl("typesense/collections/{$uid}/mapping"),
                ];
            }
        }

        if ($user->checkPermission(RelevanceController::PERMISSION_MANAGE_RELEVANCE)) {
            $items['relevance'] = [
                'label' => Craft::t('typesense', 'Relevance'),
                'url' => UrlHelper::cpUrl("typesense/collections/{$uid}/relevance"),
            ];
            $items['vector'] = [
                'label' => Craft::t('typesense', 'Vector / AI'),
                'url' => UrlHelper::cpUrl("typesense/collections/{$uid}/vector"),
            ];
        }

        if ($user->checkPermission(SynonymsController::PERMISSION_MANAGE_SYNONYMS)) {
            $items['synonyms'] = [
                'label' => Craft::t('typesense', 'Synonyms'),
                'url' => UrlHelper::cpUrl("typesense/collections/{$uid}/synonyms"),
            ];
        }

        if ($user->checkPermission(CurationController::PERMISSION_MANAGE_CURATION)) {
            $items['curation'] = [
                'label' => Craft::t('typesense', 'Curation'),
                'url' => UrlHelper::cpUrl("typesense/collections/{$uid}/curation"),
            ];
        }

        return $items;
    }

    /**
     * The inner-sidebar nav items for a config-file collection's screen, keyed by
     * name (config collections have no project-config uid). Their schema, mapping,
     * relevance, and vector settings live in the config file (read-only), so the
     * screen offers a read-only Overview plus the Synonyms and Curation sections,
     * which stay control-panel-editable whenever the config file is silent about
     * that domain (presence-based ownership, per Fix 5).
     *
     * @param string $name
     * @return array<string, array{label: string, url: string}>
     * @author CraftPulse
     */
    public static function configScreenNavItems(string $name): array
    {
        $user = Craft::$app->getUser();
        $items = [
            'overview' => [
                'label' => Craft::t('typesense', 'Overview'),
                'url' => UrlHelper::cpUrl("typesense/collections/config/{$name}"),
            ],
        ];

        if ($user->checkPermission(SynonymsController::PERMISSION_MANAGE_SYNONYMS)) {
            $items['synonyms'] = [
                'label' => Craft::t('typesense', 'Synonyms'),
                'url' => UrlHelper::cpUrl("typesense/collections/config/{$name}/synonyms"),
            ];
        }

        if ($user->checkPermission(CurationController::PERMISSION_MANAGE_CURATION)) {
            $items['curation'] = [
                'label' => Craft::t('typesense', 'Curation'),
                'url' => UrlHelper::cpUrl("typesense/collections/config/{$name}/curation"),
            ];
        }

        return $items;
    }

    /**
     * Resolves a collection-section screen from its route identity: a
     * control-panel-managed definition uid, or a config-file collection name.
     * Returns the runtime collection (null when a managed collection is disabled),
     * the section URL base and post key, the display name, the sidebar nav items,
     * and whether the screen is the disabled shell.
     *
     * @param string|null $uid
     * @param string|null $name
     * @return array{collection: ?Collection, editBase: string, editKey: string, screenName: string, navItems: array<string, array{label: string, url: string}>, disabled: bool}
     * @throws NotFoundHttpException
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public static function resolveSectionScreen(?string $uid, ?string $name): array
    {
        $registry = Typesense::$plugin->getCollectionRegistry();

        if ($name !== null) {
            $collection = $registry->get($name);

            if ($collection === null) {
                throw new NotFoundHttpException('Collection not found.');
            }

            return [
                'collection' => $collection,
                'editBase' => "typesense/collections/config/{$name}",
                'editKey' => "config:{$name}",
                'screenName' => $name,
                'navItems' => self::configScreenNavItems($name),
                'disabled' => false,
            ];
        }

        $definition = Typesense::$plugin->getManagedCollections()->getByUid((string)$uid);

        if ($definition === null) {
            throw new NotFoundHttpException('Collection not found.');
        }

        $collection = $registry->get($definition->name);

        return [
            'collection' => $collection,
            'editBase' => "typesense/collections/{$uid}",
            'editKey' => (string)$uid,
            'screenName' => $definition->getDisplayName(),
            'navItems' => self::editScreenNavItems($definition),
            'disabled' => !$definition->enabled || $collection === null,
        ];
    }

    /**
     * Resolves the runtime collection for a section post key: a managed
     * definition uid, or a "config:<name>" config-collection key.
     *
     * @param string $key
     * @return Collection|null
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public static function collectionForScreenKey(string $key): ?Collection
    {
        $registry = Typesense::$plugin->getCollectionRegistry();

        if (str_starts_with($key, 'config:')) {
            return $registry->get(substr($key, 7));
        }

        $definition = Typesense::$plugin->getManagedCollections()->getByUid($key);

        return $definition !== null ? $registry->get($definition->name) : null;
    }

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @param \yii\base\Action $action
     * @return bool
     * @throws ForbiddenHttpException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\web\BadRequestHttpException
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        // The index picker and the read-only config-collection overview are the
        // shared entry points for the collection screens, so they admit any
        // collection-screen permission; every other action keeps its own
        // manageCollections gate.
        if (in_array($action->id, ['index', 'config-overview'], true)) {
            if ($this->_firstSectionSuffix() === null) {
                throw new ForbiddenHttpException(Craft::t('typesense', 'User is not permitted to perform this action.'));
            }

            return true;
        }

        $this->requirePermission(self::PERMISSION_MANAGE_COLLECTIONS);

        return true;
    }

    /**
     * Deletes a CP-managed collection definition.
     *
     * @return Response|null
     * @throws \yii\web\BadRequestHttpException
     * @throws \Throwable
     * @author CraftPulse
     */
    public function actionDelete(): ?Response
    {
        $this->requirePostRequest();

        // VueAdminTable posts the row id; here the row id is the definition uid.
        $uid = (string)$this->request->getRequiredBodyParam('id');
        $definition = Typesense::$plugin->getManagedCollections()->getByUid($uid);

        if ($definition !== null) {
            Typesense::$plugin->getManagedCollections()->delete($definition);
        }

        return $this->asSuccess(Craft::t('typesense', 'Collection deleted.'));
    }

    /**
     * Renders the create/edit form for a CP-managed collection.
     *
     * @param string|null $uid
     * @param CollectionDefinition|null $definition
     * @return Response
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     * @author CraftPulse
     */
    public function actionEdit(?string $uid = null, ?CollectionDefinition $definition = null): Response
    {
        if ($definition === null) {
            $definition = $uid !== null
                ? Typesense::$plugin->getManagedCollections()->getByUid($uid)
                : new CollectionDefinition();
        }

        if ($definition === null) {
            throw new NotFoundHttpException('Collection not found.');
        }

        $suspend = Typesense::$plugin->getSyncSuspend();

        return $this->renderTemplate('typesense/collections/_edit', [
            'definition' => $definition,
            'sourceOptions' => $this->_sourceOptions(),
            'isNew' => $definition->uid === null,
            'suspended' => $suspend->isCollectionSuspended($definition->name),
            'globallySuspended' => $suspend->isGloballySuspended(),
            'navItems' => self::editScreenNavItems($definition),
        ]);
    }

    /**
     * The read-only Overview section of a config-file collection's screen. The
     * collection's schema, mapping, relevance, and vector settings are declared
     * in the config file, so they are shown as read-only facts here; its synonyms
     * and curation stay control-panel-editable when the config file is silent
     * about them (the Synonyms and Curation sections in the sidebar nav).
     *
     * @param string $name
     * @return Response
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionConfigOverview(string $name): Response
    {
        $collection = Typesense::$plugin->getCollectionRegistry()->get($name);

        if ($collection === null) {
            throw new NotFoundHttpException('Collection not found.');
        }

        return $this->renderTemplate('typesense/collections/_config-overview', [
            'collection' => $collection,
            'screenName' => $name,
            'navItems' => self::configScreenNavItems($name),
        ]);
    }

    /**
     * Lists the CP-managed and config-owned collections.
     *
     * @return Response
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionIndex(): Response
    {
        $registry = Typesense::$plugin->getCollectionRegistry();
        $managed = Typesense::$plugin->getManagedCollections()->getAll();
        $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;

        // The name the front end queries: the logical/alias name when a
        // collection sits behind an alias, the physical name otherwise. In both
        // cases that is resolveName() (an alias is registered under the resolved
        // logical name, and search routes it to the physical collection), so the
        // resolved name is query-correct either way.
        $managedTsNames = [];
        foreach ($managed as $uid => $definition) {
            $collection = $registry->get($definition->name);
            $managedTsNames[$uid] = $collection !== null
                ? $registry->resolveName($collection, $primarySiteId)
                : $registry->resolveName($definition->name);
        }

        // The "Code managed" tab lists only the read-only, code-owned sources:
        // config-file declarations and module (event) registrations. The merged
        // getAll() map is NOT used here, or a control-panel-managed collection
        // would leak in with a false config-owned status. Config wins over a
        // module name, so a config row represents an overridden name.
        $overridden = $registry->getOverriddenNames();
        $codeCollections = [];

        foreach ($registry->getConfigCollections() as $name => $collection) {
            $codeCollections[$name] = [
                'name' => (string)$name,
                'tsName' => $registry->resolveName($collection, $primarySiteId),
                'elementType' => $collection->getElementType(),
                'source' => 'config',
                'overridden' => in_array($name, $overridden, true),
            ];
        }

        foreach ($registry->getEventCollections() as $name => $collection) {
            // A config-file collection of the same name wins, so it is already
            // represented above (as overridden); do not double-list.
            if (isset($codeCollections[$name])) {
                continue;
            }

            $codeCollections[$name] = [
                'name' => (string)$name,
                'tsName' => $registry->resolveName($collection, $primarySiteId),
                'elementType' => $collection->getElementType(),
                'source' => 'module',
                'overridden' => false,
            ];
        }

        return $this->renderTemplate('typesense/collections/index', [
            'managed' => $managed,
            'managedTsNames' => $managedTsNames,
            'codeCollections' => $codeCollections,
            'canManageCollections' => Craft::$app->getUser()->checkPermission(self::PERMISSION_MANAGE_COLLECTIONS),
            'rowSectionSuffix' => $this->_firstSectionSuffix() ?? '',
        ]);
    }

    /**
     * Renders the field mapping screen for a CP-managed collection.
     *
     * @param string $uid
     * @return Response
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionMapping(string $uid): Response
    {
        $definition = Typesense::$plugin->getManagedCollections()->getByUid($uid);

        if ($definition === null) {
            throw new NotFoundHttpException('Collection not found.');
        }

        $this->getView()->registerAssetBundle(CpAsset::class);

        $readOnly = !Craft::$app->getConfig()->getGeneral()->allowAdminChanges;

        $designerHtml = Cp::fieldLayoutDesignerHtml($definition->getFieldLayout(), [
            'customizableTabs' => false,
            'customizableUi' => false,
            'pretendTabName' => Craft::t('typesense', 'Mapping'),
            'disabled' => $readOnly,
        ]);

        return $this->renderTemplate('typesense/collections/_mapping', [
            'definition' => $definition,
            'designerHtml' => $designerHtml,
            'readOnly' => $readOnly,
            'navItems' => self::editScreenNavItems($definition),
        ]);
    }

    /**
     * Persists a CP-managed collection definition to project config.
     *
     * @return Response|null
     * @throws \yii\web\BadRequestHttpException
     * @throws \Throwable
     * @author CraftPulse
     */
    public function actionSave(): ?Response
    {
        $this->requirePostRequest();

        // Re-check the gate after reading input (check, populate, re-check).
        $this->requirePermission(self::PERMISSION_MANAGE_COLLECTIONS);

        $uid = $this->request->getBodyParam('uid');
        $isNew = $uid === null;
        $definition = !$isNew
            ? (Typesense::$plugin->getManagedCollections()->getByUid((string)$uid) ?? new CollectionDefinition())
            : new CollectionDefinition();

        $definition->name = (string)$this->request->getBodyParam('name', $definition->name);
        $definition->displayName = (string)$this->request->getBodyParam('displayName', $definition->displayName);
        $definition->multisite = (string)$this->request->getBodyParam('multisite', $definition->multisite);
        $definition->enabled = (bool)$this->request->getBodyParam('enabled', $definition->enabled);

        // The collection type is chosen once, on creation, and locked afterwards
        // (the schema shape differs fundamentally); an edit keeps the stored type.
        if ($isNew) {
            $type = (string)$this->request->getBodyParam('collectionType', CollectionDefinition::COLLECTION_TYPE_REGULAR);
            $definition->collectionType = in_array($type, [CollectionDefinition::COLLECTION_TYPE_REGULAR, CollectionDefinition::COLLECTION_TYPE_UNION], true)
                ? $type
                : CollectionDefinition::COLLECTION_TYPE_REGULAR;
        }

        // A union collection's sources are its members (managed on the Members
        // section), not the single element-source picker.
        if (!$definition->isUnion()) {
            [$elementType, $source, $sourceType] = $this->_parseSource((string)$this->request->getBodyParam('source', ''));

            if ($elementType !== null) {
                $definition->elementType = $elementType;
                $definition->source = $source;
                $definition->sourceType = $sourceType;
            }
        }

        if (!Typesense::$plugin->getManagedCollections()->save($definition)) {
            return $this->asModelFailure($definition, Craft::t('typesense', 'Could not save the collection.'), 'definition');
        }

        return $this->asModelSuccess($definition, Craft::t('typesense', 'Collection saved.'), 'definition', [], 'typesense/collections');
    }

    /**
     * Toggles the runtime sync-suspend state for one collection. Gated by
     * manageCollections (the cockpit surface); the toggle is database state, so
     * it works even when allowAdminChanges is disabled.
     *
     * @return Response|null
     * @throws \yii\web\BadRequestHttpException
     * @author CraftPulse
     */
    public function actionToggleSuspend(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(self::PERMISSION_MANAGE_COLLECTIONS);

        $collection = (string)$this->request->getRequiredBodyParam('collection');
        $suspended = Typesense::$plugin->getSyncSuspend()->toggle($collection);

        return $this->asSuccess(
            $suspended
                ? Craft::t('typesense', 'Sync suspended.')
                : Craft::t('typesense', 'Sync resumed.'),
            ['suspended' => $suspended],
        );
    }

    /**
     * Persists the field mapping for a CP-managed collection: the field layout
     * assembled from the designer post (each element carrying its Typesense
     * mapping settings) is saved into the collection definition's project config.
     *
     * @return Response|null
     * @throws NotFoundHttpException
     * @throws \yii\web\BadRequestHttpException
     * @throws \Throwable
     * @author CraftPulse
     */
    public function actionSaveMapping(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(self::PERMISSION_MANAGE_COLLECTIONS);

        $uid = (string)$this->request->getRequiredBodyParam('uid');
        $definition = Typesense::$plugin->getManagedCollections()->getByUid($uid);

        if ($definition === null) {
            throw new NotFoundHttpException('Collection not found.');
        }

        $layout = Craft::$app->getFields()->assembleLayoutFromPost();
        $layout->uid = $definition->fieldLayoutUid ?? StringHelper::UUID();
        $definition->setFieldLayout($layout);

        Typesense::$plugin->getManagedCollections()->save($definition);

        return $this->asModelSuccess($definition, Craft::t('typesense', 'Mapping saved.'), 'definition', [], 'typesense/collections/' . $definition->uid . '/mapping');
    }

    /**
     * The Members section of a union collection: the member source list and one
     * mapping field-layout designer per member (visually separated), each scoped
     * to its member's source via a member field-layout provider.
     *
     * @param string $uid
     * @return Response
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionMembers(string $uid): Response
    {
        $definition = Typesense::$plugin->getManagedCollections()->getByUid($uid);

        if ($definition === null || !$definition->isUnion()) {
            throw new NotFoundHttpException('Union collection not found.');
        }

        $this->getView()->registerAssetBundle(CpAsset::class);
        $readOnly = !Craft::$app->getConfig()->getGeneral()->allowAdminChanges;

        $memberRows = [];
        $designers = [];

        foreach ($definition->getMemberModels() as $member) {
            $memberRows[] = [
                'handle' => $member->handle,
                'source' => $member->elementType . ':' . ($member->sourceType === CollectionDefinition::SOURCE_TYPE_ENTRY_TYPE ? 'type:' : '') . ($member->source ?? ''),
            ];

            $designers[$member->handle] = Craft::$app->getView()->namespaceInputs(
                static fn(): string => Cp::fieldLayoutDesignerHtml($member->getFieldLayout(), [
                    'customizableTabs' => false,
                    'customizableUi' => false,
                    'pretendTabName' => Craft::t('typesense', 'Mapping'),
                    'disabled' => $readOnly,
                ]),
                'member-' . $member->handle,
            );
        }

        return $this->renderTemplate('typesense/collections/_members', [
            'definition' => $definition,
            'memberRows' => $memberRows,
            'designers' => $designers,
            'sourceOptions' => $this->_sourceOptions(),
            'readOnly' => $readOnly,
            'navItems' => self::editScreenNavItems($definition),
        ]);
    }

    /**
     * Persists a union collection's member sources and each member's mapping
     * layout. The member list (source + handle rows) is rebuilt from the post, and
     * each rendered member's namespaced field layout is assembled and stored
     * inline. New members get an empty layout to map on the next load.
     *
     * @return Response|null
     * @throws NotFoundHttpException
     * @throws \yii\web\BadRequestHttpException
     * @throws \Throwable
     * @author CraftPulse
     */
    public function actionSaveMembers(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(self::PERMISSION_MANAGE_COLLECTIONS);

        $uid = (string)$this->request->getRequiredBodyParam('uid');
        $definition = Typesense::$plugin->getManagedCollections()->getByUid($uid);

        if ($definition === null || !$definition->isUnion()) {
            throw new NotFoundHttpException('Union collection not found.');
        }

        $existing = [];

        foreach ($definition->members as $member) {
            if (is_array($member) && isset($member['handle'])) {
                $existing[(string)$member['handle']] = $member;
            }
        }

        $rows = $this->request->getBodyParam('members', []);
        $members = [];
        $seen = [];

        foreach (is_array($rows) ? $rows : [] as $index => $row) {
            [$elementType, $source, $sourceType] = $this->_parseSource((string)($row['source'] ?? ''));

            if ($elementType === null) {
                continue;
            }

            $handle = $this->_memberHandle((string)($row['handle'] ?? ''), $index, $seen);
            $seen[$handle] = true;

            $member = [
                'handle' => $handle,
                'elementType' => $elementType,
                'source' => $source,
                'sourceType' => $sourceType,
                'fieldLayoutUid' => $existing[$handle]['fieldLayoutUid'] ?? StringHelper::UUID(),
            ];

            // Assemble this member's namespaced layout when its designer was
            // rendered; otherwise carry the stored layout (or leave it empty for a
            // freshly added member, to map on the next load).
            if ($this->request->getBodyParam("member-{$handle}") !== null) {
                $layout = Craft::$app->getFields()->assembleLayoutFromPost("member-{$handle}");
                $layout->uid = $member['fieldLayoutUid'];
                $member['fieldLayout'] = $layout->getConfig();
            } elseif (isset($existing[$handle]['fieldLayout'])) {
                $member['fieldLayout'] = $existing[$handle]['fieldLayout'];
            }

            $members[] = $member;
        }

        $definition->members = $members;

        if (!Typesense::$plugin->getManagedCollections()->save($definition)) {
            return $this->asModelFailure($definition, Craft::t('typesense', 'Could not save the members.'), 'definition');
        }

        return $this->asModelSuccess($definition, Craft::t('typesense', 'Members saved.'), 'definition', [], 'typesense/collections/' . $definition->uid . '/members');
    }

    // Private Methods
    // =========================================================================

    /**
     * A unique member handle: the posted handle when valid and unused, else one
     * derived from the row index, de-duplicated against the handles already seen.
     *
     * @param string $posted
     * @param int|string $index
     * @param array<string, bool> $seen
     * @return string
     * @author CraftPulse
     */
    private function _memberHandle(string $posted, int|string $index, array $seen): string
    {
        $handle = preg_replace('/[^a-zA-Z0-9_\-]/', '', $posted) ?: 'member' . ((int)$index + 1);

        if (!preg_match('/^[a-zA-Z]/', $handle)) {
            $handle = 'm' . $handle;
        }

        $base = $handle;
        $n = 2;

        while (isset($seen[$handle])) {
            $handle = $base . $n;
            $n++;
        }

        return $handle;
    }

    /**
     * The URL suffix of the current user's first accessible collection section,
     * used both to gate the index picker and to resolve its row links. Returns
     * null when the user may not reach any section.
     *
     * @return string|null
     * @author CraftPulse
     */
    private function _firstSectionSuffix(): ?string
    {
        $user = Craft::$app->getUser();

        return match (true) {
            $user->checkPermission(self::PERMISSION_MANAGE_COLLECTIONS) => '',
            $user->checkPermission(RelevanceController::PERMISSION_MANAGE_RELEVANCE) => '/relevance',
            $user->checkPermission(SynonymsController::PERMISSION_MANAGE_SYNONYMS) => '/synonyms',
            $user->checkPermission(CurationController::PERMISSION_MANAGE_CURATION) => '/curation',
            default => null,
        };
    }

    /**
     * Splits a source select value into its parts. A section-shaped source is
     * encoded "<elementType>:<sourceHandle>"; an entry-type-shaped source (a
     * single, possibly nested, entry type) is encoded "<elementType>:type:<handle>".
     *
     * @param string $value
     * @return array{0: class-string<ElementInterface>|null, 1: string|null, 2: string}
     * @author CraftPulse
     */
    private function _parseSource(string $value): array
    {
        if ($value === '' || !str_contains($value, ':')) {
            return [null, null, CollectionDefinition::SOURCE_TYPE_SECTION];
        }

        [$elementType, $source] = explode(':', $value, 2);

        if (!is_subclass_of($elementType, ElementInterface::class)) {
            return [null, null, CollectionDefinition::SOURCE_TYPE_SECTION];
        }

        if (str_starts_with((string)$source, 'type:')) {
            $handle = substr((string)$source, strlen('type:'));

            return [$elementType, $handle === '' ? null : $handle, CollectionDefinition::SOURCE_TYPE_ENTRY_TYPE];
        }

        return [$elementType, $source === '' ? null : $source, CollectionDefinition::SOURCE_TYPE_SECTION];
    }

    /**
     * Builds the grouped element-source options: every native element type with
     * a field layout, plus Commerce products when installed. Each option value
     * encodes the element type and the source handle.
     *
     * @return array<int, array<string, string>>
     * @author CraftPulse
     */
    private function _sourceOptions(): array
    {
        $options = [];

        foreach (Craft::$app->getEntries()->getAllSections() as $section) {
            $options[] = ['label' => Craft::t('typesense', 'Entries') . ': ' . $section->name, 'value' => Entry::class . ':' . $section->handle];
        }

        // Sectionless entry types (nested Matrix / CKEditor entry types) as their
        // own source: index the nested entries directly (see Gap 2).
        foreach ($this->_nestedEntryTypes() as $entryType) {
            $options[] = ['label' => Craft::t('typesense', 'Nested entry types') . ': ' . $entryType->name, 'value' => Entry::class . ':type:' . $entryType->handle];
        }

        foreach (Craft::$app->getCategories()->getAllGroups() as $group) {
            $options[] = ['label' => Craft::t('typesense', 'Categories') . ': ' . $group->name, 'value' => Category::class . ':' . $group->handle];
        }

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            $options[] = ['label' => Craft::t('typesense', 'Assets') . ': ' . $volume->name, 'value' => Asset::class . ':' . $volume->handle];
        }

        foreach (Craft::$app->getTags()->getAllTagGroups() as $group) {
            $options[] = ['label' => Craft::t('typesense', 'Tags') . ': ' . $group->name, 'value' => Tag::class . ':' . $group->handle];
        }

        foreach (Craft::$app->getGlobals()->getAllSets() as $set) {
            $options[] = ['label' => Craft::t('typesense', 'Globals') . ': ' . $set->name, 'value' => GlobalSet::class . ':' . $set->handle];
        }

        $options[] = ['label' => Craft::t('typesense', 'Users'), 'value' => User::class . ':'];

        return $options;
    }

    /**
     * The sectionless entry types: entry types bound to no section, so they are
     * used only as nested Matrix / CKEditor entry types. These are offered as
     * their own collection source (see Gap 2).
     *
     * @return array<int, \craft\models\EntryType>
     * @author CraftPulse
     */
    private function _nestedEntryTypes(): array
    {
        $entriesService = Craft::$app->getEntries();
        $sectionBound = [];

        foreach ($entriesService->getAllSections() as $section) {
            foreach ($section->getEntryTypes() as $entryType) {
                $sectionBound[$entryType->id] = true;
            }
        }

        return array_values(array_filter(
            $entriesService->getAllEntryTypes(),
            static fn(\craft\models\EntryType $entryType): bool => !isset($sectionBound[$entryType->id]),
        ));
    }
}
