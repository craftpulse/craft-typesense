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
            $items['mapping'] = [
                'label' => Craft::t('typesense', 'Mapping'),
                'url' => UrlHelper::cpUrl("typesense/collections/{$uid}/mapping"),
            ];
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

        // The index picker is the shared entry point for the collection edit
        // screen's tabs, so it admits either collection-screen permission; every
        // other action keeps its own manageCollections gate.
        if ($action->id === 'index') {
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

        return $this->renderTemplate('typesense/collections/index', [
            'managed' => Typesense::$plugin->getManagedCollections()->getAll(),
            'configCollections' => $registry->getAll(),
            'overriddenNames' => $registry->getOverriddenNames(),
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
        $definition = $uid !== null
            ? (Typesense::$plugin->getManagedCollections()->getByUid((string)$uid) ?? new CollectionDefinition())
            : new CollectionDefinition();

        $definition->name = (string)$this->request->getBodyParam('name', $definition->name);
        $definition->multisite = (string)$this->request->getBodyParam('multisite', $definition->multisite);
        $definition->enabled = (bool)$this->request->getBodyParam('enabled', $definition->enabled);

        [$elementType, $source] = $this->_parseSource((string)$this->request->getBodyParam('source', ''));

        if ($elementType !== null) {
            $definition->elementType = $elementType;
            $definition->source = $source;
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

    // Private Methods
    // =========================================================================

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
     * Splits a source select value ("<elementType>:<source>") into its parts.
     *
     * @param string $value
     * @return array{0: class-string<ElementInterface>|null, 1: string|null}
     * @author CraftPulse
     */
    private function _parseSource(string $value): array
    {
        if ($value === '' || !str_contains($value, ':')) {
            return [null, null];
        }

        [$elementType, $source] = explode(':', $value, 2);

        if (!is_subclass_of($elementType, ElementInterface::class)) {
            return [null, null];
        }

        return [$elementType, $source === '' ? null : $source];
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
}
