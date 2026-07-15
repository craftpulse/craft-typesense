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
use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\controllers\base\ProController;
use craftpulse\typesense\models\Settings;
use craftpulse\typesense\Typesense;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The Pro synonyms manager: CRUD for one-way and multi-way synonyms over the
 * dual-shape synonyms service. Control-panel-managed synonym scopes are
 * editable; config-managed scopes render read-only with the override notice.
 * Gated by the edition and `typesense:manageCollections`.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class SynonymsController extends ProController
{
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

        $this->requirePermission(CollectionsController::PERMISSION_MANAGE_COLLECTIONS);

        return true;
    }

    /**
     * Deletes one synonym.
     *
     * @return Response|null
     * @throws NotFoundHttpException
     * @throws \yii\web\BadRequestHttpException
     * @author CraftPulse
     */
    public function actionDeleteSynonym(): ?Response
    {
        $this->requirePostRequest();

        $collection = $this->_editableCollection((string)$this->request->getRequiredBodyParam('collection'));
        Typesense::$plugin->getSynonyms()->deleteOne($collection, (string)$this->request->getRequiredBodyParam('id'));

        return $this->asSuccess(Craft::t('typesense', 'Synonym deleted.'));
    }

    /**
     * The synonym editor for a collection.
     *
     * @param string $collection
     * @param string|null $synonymId
     * @return Response
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionEditSynonym(string $collection, ?string $synonymId = null): Response
    {
        $model = $this->_requireCollection($collection);
        $synonym = null;

        if ($synonymId !== null) {
            foreach (Typesense::$plugin->getSynonyms()->all($model) as $existing) {
                if ((string)($existing['id'] ?? '') === $synonymId) {
                    $synonym = $existing;
                    break;
                }
            }
        }

        return $this->renderTemplate('typesense/synonyms/_edit', [
            'handle' => $collection,
            'synonym' => $synonym,
            'isNew' => $synonym === null,
        ]);
    }

    /**
     * The collection picker for the synonyms manager.
     *
     * @return Response
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionIndex(): Response
    {
        return $this->renderTemplate('typesense/synonyms/index', [
            'collections' => array_keys(Typesense::$plugin->getCollectionRegistry()->getAll()),
        ]);
    }

    /**
     * Saves a synonym from the editor.
     *
     * @return Response|null
     * @throws NotFoundHttpException
     * @throws \yii\web\BadRequestHttpException
     * @author CraftPulse
     */
    public function actionSaveSynonym(): ?Response
    {
        $this->requirePostRequest();

        $handle = (string)$this->request->getRequiredBodyParam('collection');
        $collection = $this->_editableCollection($handle);
        $id = trim((string)$this->request->getBodyParam('id', ''));
        $synonyms = $this->_terms();

        if ($id === '' || $synonyms === []) {
            return $this->asFailure(Craft::t('typesense', 'A synonym needs an id and at least one term.'));
        }

        Typesense::$plugin->getSynonyms()->upsert($collection, $id, $this->_synonymBody($synonyms));

        return $this->asSuccess(Craft::t('typesense', 'Synonym saved.'), [], 'typesense/synonyms/' . $handle);
    }

    /**
     * Lists the synonyms for a collection.
     *
     * @param string $collection
     * @return Response
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionSynonyms(string $collection): Response
    {
        $model = $this->_requireCollection($collection);
        $synonyms = Typesense::$plugin->getSynonyms();

        return $this->renderTemplate('typesense/synonyms/_list', [
            'handle' => $collection,
            'synonyms' => $synonyms->all($model),
            'editable' => $synonyms->getManagedBy($model) === Settings::MANAGED_BY_CP,
            'overridden' => $synonyms->isConfigOverridden($model),
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Loads a collection and asserts its synonyms are control-panel-managed.
     *
     * @param string $handle
     * @return Collection
     * @throws NotFoundHttpException when unknown or config-managed (read-only)
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    private function _editableCollection(string $handle): Collection
    {
        $collection = $this->_requireCollection($handle);

        if (Typesense::$plugin->getSynonyms()->getManagedBy($collection) !== Settings::MANAGED_BY_CP) {
            throw new NotFoundHttpException('This collection’s synonyms are managed by config.');
        }

        return $collection;
    }

    /**
     * Loads a collection or throws.
     *
     * @param string $handle
     * @return Collection
     * @throws NotFoundHttpException
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    private function _requireCollection(string $handle): Collection
    {
        $collection = Typesense::$plugin->getCollectionRegistry()->get($handle);

        if ($collection === null) {
            throw new NotFoundHttpException('Collection not found.');
        }

        return $collection;
    }

    /**
     * Builds the Typesense synonym body from the editor's posted fields. A
     * non-empty root makes it a one-way synonym; otherwise it is multi-way.
     * An optional locale scopes the synonym to a language.
     *
     * @param array<int, string> $synonyms
     * @return array<string, mixed>
     * @author CraftPulse
     */
    private function _synonymBody(array $synonyms): array
    {
        $body = ['synonyms' => $synonyms];
        $root = trim((string)$this->request->getBodyParam('root', ''));
        $locale = trim((string)$this->request->getBodyParam('locale', ''));

        if ($root !== '') {
            $body['root'] = $root;
        }

        if ($locale !== '') {
            $body['locale'] = $locale;
        }

        return $body;
    }

    /**
     * Normalises the posted terms (a newline- or comma-separated list) into a
     * clean list of synonym terms.
     *
     * @return array<int, string>
     * @author CraftPulse
     */
    private function _terms(): array
    {
        $raw = (string)$this->request->getBodyParam('synonyms', '');
        $terms = preg_split('/[\r\n,]+/', $raw) ?: [];
        $terms = array_map('trim', $terms);

        return array_values(array_filter($terms, static fn(string $term): bool => $term !== ''));
    }
}
