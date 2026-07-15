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
use craft\helpers\Json;
use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\controllers\base\ProController;
use craftpulse\typesense\helpers\Locale;
use craftpulse\typesense\Typesense;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The Pro dictionaries manager: stopwords sets (per collection) and stemming
 * dictionaries. A collection that declares stopwords in config owns them and
 * renders read-only with the config notice; otherwise the set is editable in
 * the control panel. Stemming dictionaries are listed and imported (JSONL of
 * word/root pairs). Gated by the edition and `typesense:manageCollections`.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class DictionariesController extends ProController
{
    // Constants
    // =========================================================================

    /**
     * @var string The permission that gates the dictionaries screens.
     */
    public const PERMISSION_MANAGE_DICTIONARIES = 'typesense:manageDictionaries';

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

        $this->requirePermission(self::PERMISSION_MANAGE_DICTIONARIES);

        return true;
    }

    /**
     * Deletes a collection's control-panel-managed stopwords set.
     *
     * @return Response|null
     * @throws NotFoundHttpException
     * @throws \yii\web\BadRequestHttpException
     * @author CraftPulse
     */
    public function actionDeleteStopwords(): ?Response
    {
        $this->requirePostRequest();

        $collection = $this->_editableCollection((string)$this->request->getRequiredBodyParam('collection'));
        Typesense::$plugin->getDictionaries()->deleteStopwords($collection);

        return $this->asSuccess(Craft::t('typesense', 'Stopwords deleted.'));
    }

    /**
     * Imports a stemming dictionary from the editor (JSONL or word,root lines).
     *
     * @return Response|null
     * @throws \yii\web\BadRequestHttpException
     * @author CraftPulse
     */
    public function actionImportStemming(): ?Response
    {
        $this->requirePostRequest();

        $id = trim((string)$this->request->getBodyParam('id', ''));
        $entries = $this->_stemmingEntries();

        if ($id === '' || $entries === []) {
            return $this->asFailure(Craft::t('typesense', 'A dictionary needs an id and at least one word/root pair.'));
        }

        Typesense::$plugin->getDictionaries()->importStemmingDictionary($id, $entries);

        return $this->asSuccess(Craft::t('typesense', 'Dictionary imported.'), [], 'typesense/dictionaries/stemming');
    }

    /**
     * The collection picker for the stopwords manager.
     *
     * @return Response
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionIndex(): Response
    {
        return $this->renderTemplate('typesense/dictionaries/index', [
            'collections' => array_keys(Typesense::$plugin->getCollectionRegistry()->getAll()),
        ]);
    }

    /**
     * Saves a collection's control-panel-managed stopwords set.
     *
     * @return Response|null
     * @throws NotFoundHttpException
     * @throws \yii\web\BadRequestHttpException
     * @author CraftPulse
     */
    public function actionSaveStopwords(): ?Response
    {
        $this->requirePostRequest();

        $handle = (string)$this->request->getRequiredBodyParam('collection');
        $collection = $this->_editableCollection($handle);
        $locale = Locale::toTypesense((string)$this->request->getBodyParam('locale', ''));

        Typesense::$plugin->getDictionaries()->saveStopwords($collection, $this->_words(), $locale);

        return $this->asSuccess(Craft::t('typesense', 'Stopwords saved.'), [], 'typesense/dictionaries/' . $handle);
    }

    /**
     * The stemming-dictionaries screen (list + import).
     *
     * @return Response
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionStemming(): Response
    {
        return $this->renderTemplate('typesense/dictionaries/stemming', [
            'dictionaries' => Typesense::$plugin->getDictionaries()->stemmingDictionaries(),
        ]);
    }

    /**
     * The stopwords editor for a collection.
     *
     * @param string $collection
     * @return Response
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionStopwords(string $collection): Response
    {
        $model = $this->_requireCollection($collection);
        $dictionaries = Typesense::$plugin->getDictionaries();
        $configManaged = $dictionaries->isConfigManaged($model);
        $set = $configManaged ? ['stopwords' => $model->getStopwords(), 'locale' => ''] : $dictionaries->getStopwords($model);

        return $this->renderTemplate('typesense/dictionaries/_stopwords', [
            'handle' => $collection,
            'stopwords' => $set['stopwords'],
            'locale' => $set['locale'],
            'editable' => !$configManaged,
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Loads a collection and asserts its stopwords are control-panel-managed.
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

        if (Typesense::$plugin->getDictionaries()->isConfigManaged($collection)) {
            throw new NotFoundHttpException('This collection’s stopwords are managed by config.');
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
     * Parses the posted stemming dictionary into word/root pairs. Accepts either
     * JSONL (one `{"word","root"}` object per line) or `word,root` lines.
     *
     * @return array<int, array{word: string, root: string}>
     * @author CraftPulse
     */
    private function _stemmingEntries(): array
    {
        $raw = (string)$this->request->getBodyParam('entries', '');
        $entries = [];

        foreach (preg_split('/[\r\n]+/', $raw) ?: [] as $line) {
            $line = trim((string)$line);

            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, '{')) {
                $decoded = Json::decodeIfJson($line);

                if (is_array($decoded) && isset($decoded['word'], $decoded['root'])) {
                    $entries[] = ['word' => (string)$decoded['word'], 'root' => (string)$decoded['root']];
                }

                continue;
            }

            $parts = array_map('trim', explode(',', $line, 2));

            if (count($parts) === 2 && $parts[0] !== '' && $parts[1] !== '') {
                $entries[] = ['word' => $parts[0], 'root' => $parts[1]];
            }
        }

        return $entries;
    }

    /**
     * Normalises the posted stopwords (an editable-table body param, one word per
     * row) into a clean list.
     *
     * @return array<int, string>
     * @author CraftPulse
     */
    private function _words(): array
    {
        $raw = $this->request->getBodyParam('stopwords', []);

        if (!is_array($raw)) {
            return [];
        }

        $words = array_map(static fn($row): string => trim((string)($row['word'] ?? '')), $raw);

        return array_values(array_filter($words, static fn(string $word): bool => $word !== ''));
    }
}
