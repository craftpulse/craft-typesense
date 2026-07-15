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
use craftpulse\typesense\controllers\base\ProController;
use craftpulse\typesense\models\CollectionDefinition;
use craftpulse\typesense\Typesense;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The Pro relevance-tuning editor: per control-panel-managed collection, authors
 * the search preset (raw Typesense parameters), additive boost rules (compiled
 * to the indexed boost_score at index time), text-match buckets, result grouping,
 * and MMR diversification. Version-sensitive controls (buckets, MMR) are hidden
 * on servers that do not support them. Config-file collections tune relevance in
 * fluent config, so only CP-managed collections are editable here. The Relevance
 * and Vector / AI tabs live on the collection edit screen; gated by the edition
 * and `typesense:manageRelevance`.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class RelevanceController extends ProController
{
    // Constants
    // =========================================================================

    /**
     * @var string The permission that gates the relevance screens.
     */
    public const PERMISSION_MANAGE_RELEVANCE = 'typesense:manageRelevance';

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

        $this->requirePermission(self::PERMISSION_MANAGE_RELEVANCE);

        return true;
    }

    /**
     * The Relevance tab of a CP-managed collection's edit screen: search preset,
     * boost rules, buckets, grouping, and diversification (the vector and AI
     * controls live on the Vector / AI tab).
     *
     * @param string $uid
     * @return Response
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionEdit(string $uid): Response
    {
        $definition = $this->_requireDefinition($uid);
        $capabilities = Typesense::$plugin->getClient()->getServerCapabilities();

        return $this->renderTemplate('typesense/relevance/_edit', [
            'definition' => $definition,
            'relevance' => $definition->relevance,
            'supportsBuckets' => $capabilities?->textMatchBuckets() ?? false,
            'supportsMmr' => $capabilities?->mmr() ?? false,
            'navItems' => CollectionsController::editScreenNavItems($definition),
        ]);
    }

    /**
     * The Vector / AI tab of a CP-managed collection's edit screen:
     * auto-embedding config plus the experimental-AI features and the link to the
     * conversation (RAG) model manager.
     *
     * @param string $uid
     * @return Response
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionVector(string $uid): Response
    {
        $definition = $this->_requireDefinition($uid);
        $embeddings = Typesense::$plugin->getEmbeddings();
        $modelOptions = [['label' => Craft::t('typesense', 'Choose a model'), 'value' => '']];

        foreach ($embeddings::BUILTIN_MODELS as $id => $model) {
            $modelOptions[] = ['label' => $model['label'] . ' (' . $id . ')', 'value' => $id];
        }

        return $this->renderTemplate('typesense/relevance/_vector', [
            'definition' => $definition,
            'embedding' => $definition->embedding,
            'modelOptions' => $modelOptions,
            'providers' => $embeddings::PROVIDERS,
            'experimentalFeatures' => Typesense::$plugin->getAiModels()->experimentalFeatures(),
            'navItems' => CollectionsController::editScreenNavItems($definition),
        ]);
    }

    /**
     * Persists the Relevance tab (search preset, boosts, grouping, buckets, MMR)
     * for a CP-managed collection and returns to the same tab.
     *
     * @return Response|null
     * @throws NotFoundHttpException
     * @throws \yii\web\BadRequestHttpException
     * @throws \Throwable
     * @author CraftPulse
     */
    public function actionSaveRelevance(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(self::PERMISSION_MANAGE_RELEVANCE);

        $definition = $this->_requireDefinition((string)$this->request->getRequiredBodyParam('uid'));
        $definition->relevance = $this->_relevanceBody();
        Typesense::$plugin->getManagedCollections()->save($definition);

        return $this->asModelSuccess($definition, Craft::t('typesense', 'Saved. Re-sync the collection to apply boost changes.'), 'definition', [], 'typesense/collections/' . $definition->uid . '/relevance');
    }

    /**
     * Persists the Vector / AI tab (auto-embedding config) for a CP-managed
     * collection and returns to the same tab.
     *
     * @return Response|null
     * @throws NotFoundHttpException
     * @throws \yii\web\BadRequestHttpException
     * @throws \Throwable
     * @author CraftPulse
     */
    public function actionSaveVector(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(self::PERMISSION_MANAGE_RELEVANCE);

        $definition = $this->_requireDefinition((string)$this->request->getRequiredBodyParam('uid'));
        $embedding = $this->_embeddingBody();

        // Validate a remote embedding config's shape before persisting (never a
        // live call); a bad config would break the collection's next rebuild.
        if (!empty($embedding['enabled'])) {
            $errors = Typesense::$plugin->getEmbeddings()->validateModelConfig($embedding);

            if ($errors !== []) {
                return $this->asFailure(Craft::t('typesense', 'Embedding config: {error}', ['error' => reset($errors)]));
            }
        }

        $definition->embedding = $embedding;
        Typesense::$plugin->getManagedCollections()->save($definition);

        return $this->asModelSuccess($definition, Craft::t('typesense', 'Saved. Re-sync the collection to apply embedding changes.'), 'definition', [], 'typesense/collections/' . $definition->uid . '/vector');
    }

    /**
     * The conversation (RAG) model management screen, reached from a collection's
     * Vector / AI tab. Conversation models are global (not per-collection); the
     * collection uid only threads the back-link to the originating tab.
     *
     * @param string $uid
     * @return Response
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionConversationModels(string $uid): Response
    {
        $definition = $this->_requireDefinition($uid);

        return $this->renderTemplate('typesense/relevance/_conversation-models', [
            'definition' => $definition,
            'models' => Typesense::$plugin->getAiModels()->conversationModels(),
        ]);
    }

    /**
     * Deletes a conversation (RAG) model.
     *
     * @return Response|null
     * @throws \yii\web\BadRequestHttpException
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionDeleteConversationModel(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(self::PERMISSION_MANAGE_RELEVANCE);

        Typesense::$plugin->getAiModels()->deleteConversationModel((string)$this->request->getRequiredBodyParam('id'));

        return $this->asSuccess(Craft::t('typesense', 'Conversation model deleted.'), [], $this->request->getReferrer() ?: 'typesense/collections');
    }

    /**
     * Creates a conversation (RAG) model from the vector/AI editor. The api key
     * is an environment reference, validated (shape + resolution) before any
     * request; no live LLM call is made.
     *
     * @return Response|null
     * @throws \yii\web\BadRequestHttpException
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionSaveConversationModel(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(self::PERMISSION_MANAGE_RELEVANCE);

        $request = $this->request;
        $config = [
            'id' => trim((string)$request->getBodyParam('id', '')),
            'model_name' => trim((string)$request->getBodyParam('model_name', '')),
            'api_key' => trim((string)$request->getBodyParam('api_key', '')),
            'history_collection' => trim((string)$request->getBodyParam('history_collection', '')),
            'system_prompt' => trim((string)$request->getBodyParam('system_prompt', '')),
        ];

        $errors = Typesense::$plugin->getAiModels()->validateConversationModel($config);

        if ($errors !== []) {
            return $this->asFailure(Craft::t('typesense', 'Conversation model: {error}', ['error' => reset($errors)]));
        }

        if (Typesense::$plugin->getAiModels()->upsertConversationModel($config) === null) {
            return $this->asFailure(Craft::t('typesense', 'Could not create the conversation model.'));
        }

        return $this->asSuccess(Craft::t('typesense', 'Conversation model created.'), [], $this->request->getReferrer() ?: 'typesense/collections');
    }

    // Private Methods
    // =========================================================================

    /**
     * Builds the auto-embedding config from the posted fields: the model, the
     * source field handles, and (for a remote provider) the credential env-var
     * references.
     *
     * @return array<string, mixed>
     * @author CraftPulse
     */
    private function _embeddingBody(): array
    {
        $request = $this->request;
        $from = array_values(array_filter(array_map(
            'trim',
            preg_split('/[\r\n,]+/', (string)$request->getBodyParam('embedFrom', '')) ?: [],
        ), static fn(string $handle): bool => $handle !== ''));

        $config = [];

        foreach (['api_key', 'url', 'openai_url', 'access_token', 'refresh_token', 'client_id', 'client_secret', 'project_id'] as $key) {
            $value = trim((string)$request->getBodyParam('embed_' . $key, ''));

            if ($value !== '') {
                $config[$key] = $value;
            }
        }

        // A typed remote model id (for example openai/text-embedding-3-small)
        // wins over the built-in select when provided.
        $remote = trim((string)$request->getBodyParam('embedModelRemote', ''));
        $model = $remote !== '' ? $remote : trim((string)$request->getBodyParam('embedModel', ''));

        return [
            'enabled' => (bool)$request->getBodyParam('embedEnabled', false),
            'model' => $model,
            'from' => $from,
            'config' => $config,
        ];
    }

    /**
     * Normalises the posted boost rules (an editable-table body param) into the
     * stored rule shape. Each row is a single-condition rule (field, operator,
     * value, weight); the condition builder translates directly to a boost that
     * bakes into boost_score. Multi-condition rules remain a fluent-config
     * capability. Rows without a field are dropped.
     *
     * @return array<int, array<string, mixed>>
     * @author CraftPulse
     */
    private function _boostRules(): array
    {
        $raw = $this->request->getBodyParam('boostRules', []);
        $rules = [];

        if (!is_array($raw)) {
            return [];
        }

        foreach ($raw as $row) {
            $field = trim((string)($row['field'] ?? ''));

            if ($field === '') {
                continue;
            }

            $rules[] = [
                'label' => trim((string)($row['label'] ?? '')),
                'weight' => (float)($row['weight'] ?? 0),
                'match' => 'all',
                'conditions' => [[
                    'field' => $field,
                    'operator' => (string)($row['operator'] ?? 'eq'),
                    'value' => (string)($row['value'] ?? ''),
                ]],
            ];
        }

        return $rules;
    }

    /**
     * Builds the relevance definition from the editor's posted fields.
     *
     * @return array<string, mixed>
     * @author CraftPulse
     */
    private function _relevanceBody(): array
    {
        $request = $this->request;
        $searchPreset = [];

        foreach (['num_typos', 'prefix', 'drop_tokens_threshold', 'typo_tokens_threshold', 'min_len_1typo', 'min_len_2typo'] as $key) {
            $value = trim((string)$request->getBodyParam($key, ''));

            if ($value !== '') {
                $searchPreset[$key] = $value;
            }
        }

        return [
            'boostRules' => $this->_boostRules(),
            'textMatchBuckets' => max(0, (int)$request->getBodyParam('textMatchBuckets', 0)),
            'grouping' => [
                'field' => trim((string)$request->getBodyParam('groupBy', '')),
                'limit' => max(1, (int)$request->getBodyParam('groupLimit', 1)),
            ],
            'mmr' => [
                'enabled' => (bool)$request->getBodyParam('mmrEnabled', false),
                'lambda' => (float)$request->getBodyParam('mmrLambda', 0),
            ],
            'searchPreset' => $searchPreset,
        ];
    }

    /**
     * Loads a CP-managed collection definition or throws.
     *
     * @param string $uid
     * @return CollectionDefinition
     * @throws NotFoundHttpException
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    private function _requireDefinition(string $uid): CollectionDefinition
    {
        $definition = Typesense::$plugin->getManagedCollections()->getByUid($uid);

        if ($definition === null) {
            throw new NotFoundHttpException('Collection not found.');
        }

        return $definition;
    }
}
