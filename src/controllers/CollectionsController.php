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
use craft\elements\Asset;
use craft\elements\Category;
use craft\elements\Entry;
use craft\elements\GlobalSet;
use craft\elements\Tag;
use craft\elements\User;
use craft\helpers\Json;
use craft\web\assets\cp\CpAsset;
use craftpulse\typesense\assetbundles\mapping\MappingAsset;
use craftpulse\typesense\controllers\base\ProController;
use craftpulse\typesense\models\CollectionDefinition;
use craftpulse\typesense\Typesense;
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

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @param \yii\base\Action $action
     * @return bool
     * @throws \yii\web\ForbiddenHttpException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\web\BadRequestHttpException
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
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

        $uid = (string)$this->request->getRequiredBodyParam('uid');
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

        return $this->renderTemplate('typesense/collections/_edit', [
            'definition' => $definition,
            'sourceOptions' => $this->_sourceOptions(),
            'isNew' => $definition->uid === null,
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
        $this->getView()->registerAssetBundle(MappingAsset::class);

        return $this->renderTemplate('typesense/collections/_mapping', [
            'definition' => $definition,
            'descriptors' => Typesense::$plugin->getMappingSources()->descriptorsFor($definition),
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
     * Persists the field mapping (keyed by field UID) for a CP-managed
     * collection to project config. Each `mappings[<uid>]` body param is a JSON
     * object of the field's chosen options.
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

        $posted = $this->request->getBodyParam('mappings', []);
        $mappings = [];

        if (is_array($posted)) {
            foreach ($posted as $fieldUid => $json) {
                $decoded = Json::decodeIfJson((string)$json);

                if (is_array($decoded) && $decoded !== []) {
                    $mappings[(string)$fieldUid] = $decoded;
                }
            }
        }

        $definition->mappings = $mappings;
        Typesense::$plugin->getManagedCollections()->save($definition);

        return $this->asModelSuccess($definition, Craft::t('typesense', 'Mapping saved.'), 'definition', [], 'typesense/collections/' . $definition->uid . '/mapping');
    }

    // Private Methods
    // =========================================================================

    /**
     * Splits a source select value ("<elementType>:<source>") into its parts.
     *
     * @param string $value
     * @return array{0: class-string|null, 1: string|null}
     * @author CraftPulse
     */
    private function _parseSource(string $value): array
    {
        if ($value === '' || !str_contains($value, ':')) {
            return [null, null];
        }

        [$elementType, $source] = explode(':', $value, 2);

        if (!class_exists($elementType)) {
            return [null, null];
        }

        /** @var class-string $elementType */
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
