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
use craftpulse\typesense\services\AiProviders;
use craftpulse\typesense\services\Audit;
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
 * and `typesense:manage-relevance`.
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
    public const PERMISSION_MANAGE_RELEVANCE = 'typesense:manage-relevance';

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
            'stopwordSetOptions' => $this->_stopwordSetOptions(),
            'navItems' => CollectionsController::editScreenNavItems($definition),
        ]);
    }

    /**
     * The Vector / AI section of a CP-managed collection's edit screen: the
     * auto-embedding config (built-in or a referenced embedding provider) and the
     * conversational ask opt-in (a referenced conversation model instance),
     * rendered as the Embedding and Conversation anchor panes.
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
        $providers = Typesense::$plugin->getAiProviders();

        $builtinOptions = [['label' => Craft::t('typesense', 'Choose a model'), 'value' => '']];

        foreach (Typesense::$plugin->getEmbeddings()::BUILTIN_MODELS as $id => $model) {
            $builtinOptions[] = ['label' => $model['label'] . ' (' . $id . ')', 'value' => $id];
        }

        $providerOptions = [['label' => Craft::t('typesense', 'Choose a provider'), 'value' => '']];

        foreach ($providers->getProviders(AiProviders::KIND_EMBEDDING) as $provider) {
            $providerOptions[] = ['label' => $provider->name, 'value' => $provider->handle];
        }

        $conversationOptions = [['label' => Craft::t('typesense', 'Choose a model'), 'value' => '']];

        foreach ($providers->getConversationModels() as $model) {
            $conversationOptions[] = ['label' => $model->name, 'value' => $model->handle];
        }

        return $this->renderTemplate('typesense/relevance/_vector', [
            'definition' => $definition,
            'embedding' => $definition->embedding,
            'conversation' => $definition->conversation,
            'builtinOptions' => $builtinOptions,
            'providerOptions' => $providerOptions,
            'conversationOptions' => $conversationOptions,
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

        Typesense::$plugin->getAudit()->record(Audit::EVENT_RELEVANCE_SAVED, [
            'uid' => $definition->uid,
        ]);

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
        $conversation = $this->_conversationBody();

        // Validate the embedding config's shape before persisting (never a live
        // call); a bad config would break the collection's next rebuild.
        if (!empty($embedding['enabled'])) {
            $errors = Typesense::$plugin->getEmbeddings()->validateModelConfig($embedding);

            if ($errors !== []) {
                return $this->asFailure(Craft::t('typesense', 'Embedding config: {error}', ['error' => reset($errors)]));
            }
        }

        // A conversational opt-in must reference an existing conversation model
        // instance (authored on the AI providers screen).
        if (!empty($conversation['enabled'])) {
            $handle = (string)($conversation['modelHandle'] ?? '');

            if ($handle === '' || Typesense::$plugin->getAiProviders()->getConversationModel($handle) === null) {
                return $this->asFailure(Craft::t('typesense', 'Choose a conversation model that exists.'));
            }
        }

        $definition->embedding = $embedding;
        $definition->conversation = $conversation;
        Typesense::$plugin->getManagedCollections()->save($definition);

        Typesense::$plugin->getAudit()->record(Audit::EVENT_RELEVANCE_SAVED, [
            'uid' => $definition->uid,
        ]);

        return $this->asModelSuccess($definition, Craft::t('typesense', 'Saved. Re-sync the collection to apply embedding changes.'), 'definition', [], 'typesense/collections/' . $definition->uid . '/vector');
    }

    // Private Methods
    // =========================================================================

    /**
     * Builds the auto-embedding config from the posted fields: the branch
     * (built-in or provider), the model, the referenced embedding provider, the
     * source field handles, the output dimensions, and the optional prefixes.
     * Credentials are never posted here: they live on the referenced provider.
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

        $builtIn = (string)$request->getBodyParam('embedBranch', 'builtin') !== 'provider';

        return [
            'enabled' => (bool)$request->getBodyParam('embedEnabled', false),
            'builtIn' => $builtIn,
            'model' => $builtIn
                ? trim((string)$request->getBodyParam('embedBuiltinModel', ''))
                : trim((string)$request->getBodyParam('embedModelName', '')),
            'providerHandle' => trim((string)$request->getBodyParam('embedProvider', '')),
            'dims' => max(0, (int)$request->getBodyParam('embedDims', 0)),
            'from' => $from,
            // Prefixes carry meaningful trailing spaces, so they are not trimmed.
            'indexingPrefix' => (string)$request->getBodyParam('embedIndexingPrefix', ''),
            'queryPrefix' => (string)$request->getBodyParam('embedQueryPrefix', ''),
        ];
    }

    /**
     * Builds the conversational ask config from the posted fields: the opt-in flag
     * and the referenced conversation model instance handle.
     *
     * @return array<string, mixed>
     * @author CraftPulse
     */
    private function _conversationBody(): array
    {
        return [
            'enabled' => (bool)$this->request->getBodyParam('askEnabled', false),
            'modelHandle' => trim((string)$this->request->getBodyParam('askModel', '')),
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
     * The stopword-set select options for the relevance editor: a blank "none"
     * plus the server's stopword sets. Fail-soft, so an unreachable server yields
     * just "none" rather than breaking the screen.
     *
     * @return array<int, array{label: string, value: string}>
     * @author CraftPulse
     */
    private function _stopwordSetOptions(): array
    {
        $options = [['label' => Craft::t('typesense', 'None'), 'value' => '']];

        try {
            foreach (Typesense::$plugin->getDictionaries()->stopwordSets() as $id) {
                $options[] = ['label' => $id, 'value' => $id];
            }
        } catch (\Throwable) {
            // Fail-soft: an unreachable server just yields the "none" option.
        }

        return $options;
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

        // The chosen server stopword set becomes the collection's default
        // `stopwords` search parameter, applied via its mirrored preset.
        $stopwordSet = trim((string)$request->getBodyParam('stopwordSet', ''));

        if ($stopwordSet !== '') {
            $searchPreset['stopwords'] = $stopwordSet;
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
