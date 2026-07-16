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
use craftpulse\typesense\models\AiProvider;
use craftpulse\typesense\models\ConversationModel;
use craftpulse\typesense\services\AiProviders;
use craftpulse\typesense\Typesense;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * The Pro AI providers screen: manages the credential-and-endpoint provider
 * records the embedding and conversation subsystems draw on, and the conversation
 * model instances a searchable collection answers with. Providers and instances
 * live in project config keyed by handle; credentials are stored as environment
 * references only and resolved at the moment a config is sent to Typesense.
 * Config-file declarations are read-only here (presence-based ownership). Gated by
 * the edition and `typesense:manageAiProviders`.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class AiProvidersController extends ProController
{
    // Constants
    // =========================================================================

    /**
     * @var string The permission that gates the AI providers screen.
     */
    public const PERMISSION_MANAGE_AI_PROVIDERS = 'typesense:manageAiProviders';

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

        $this->requirePermission(self::PERMISSION_MANAGE_AI_PROVIDERS);

        return true;
    }

    /**
     * The AI providers screen: the provider records and the conversation model
     * instances, plus which instances are present on the server.
     *
     * @return Response
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionIndex(): Response
    {
        $providers = Typesense::$plugin->getAiProviders();
        $serverIds = array_column($providers->serverConversationModels(), 'id');

        return $this->renderTemplate('typesense/ai-providers/index', [
            'providers' => $providers->getProviders(),
            'models' => $providers->getConversationModels(),
            'serverModelIds' => array_map('strval', array_filter($serverIds, 'is_scalar')),
            'typeLabels' => $this->_typeLabels(),
        ]);
    }

    /**
     * The provider editor. The kind (embedding or conversation) is fixed per
     * editor: a new provider takes it from the launching button, an existing one
     * from the stored record, so the type select scopes to one kind's catalog and
     * the credential fields toggle natively off the type value alone.
     *
     * @param string|null $handle
     * @param string|null $kind
     * @return Response
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionEditProvider(?string $handle = null, ?string $kind = null): Response
    {
        $provider = $handle !== null
            ? (Typesense::$plugin->getAiProviders()->getProvider($handle) ?? throw new NotFoundHttpException('Provider not found.'))
            : new AiProvider();

        if ($handle === null) {
            $provider->kind = isset(AiProviders::TYPES[$kind]) ? (string)$kind : AiProviders::KIND_EMBEDDING;
        }

        return $this->renderTemplate('typesense/ai-providers/_provider', [
            'provider' => $provider,
            'isNew' => $handle === null,
            'types' => AiProviders::TYPES[$provider->kind],
        ]);
    }

    /**
     * Persists a provider.
     *
     * @return Response|null
     * @throws \yii\web\BadRequestHttpException
     * @throws \Throwable
     * @author CraftPulse
     */
    public function actionSaveProvider(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(self::PERMISSION_MANAGE_AI_PROVIDERS);

        $request = $this->request;
        $original = trim((string)$request->getBodyParam('originalHandle', ''));

        // A config-file provider is read-only; writing to project config under its
        // handle would be shadowed by config on read (a silent inert write).
        if ($original !== '' && (Typesense::$plugin->getAiProviders()->getProvider($original)?->readOnly ?? false)) {
            return $this->asFailure(Craft::t('typesense', 'This provider is declared in config/typesense.php and is read only.'));
        }

        $provider = new AiProvider();
        $provider->handle = $original !== '' ? $original : trim((string)$request->getBodyParam('handle', ''));
        $provider->name = (string)$request->getBodyParam('name', '');
        $provider->kind = (string)$request->getBodyParam('kind', AiProviders::KIND_EMBEDDING);
        $provider->type = (string)$request->getBodyParam('type', '');
        $provider->endpoint = trim((string)$request->getBodyParam('endpoint_' . $provider->type, ''));
        $provider->enabled = (bool)$request->getBodyParam('enabled', true);
        $provider->credentials = $this->_credentialsBody($provider->kind, $provider->type);

        if (!$provider->validate()) {
            return $this->asModelFailure($provider, Craft::t('typesense', 'Could not save the provider.'), 'provider');
        }

        $errors = Typesense::$plugin->getAiProviders()->validateProvider($provider);

        if ($errors !== []) {
            return $this->asFailure(Craft::t('typesense', 'Provider: {error}', ['error' => reset($errors)]));
        }

        if (!Typesense::$plugin->getAiProviders()->saveProvider($provider, $original !== '' ? $original : null)) {
            return $this->asModelFailure($provider, Craft::t('typesense', 'Could not save the provider.'), 'provider');
        }

        return $this->asModelSuccess($provider, Craft::t('typesense', 'Provider saved.'), 'provider', [], 'typesense/ai-providers');
    }

    /**
     * Deletes a provider.
     *
     * @return Response|null
     * @throws \yii\web\BadRequestHttpException
     * @throws \Throwable
     * @author CraftPulse
     */
    public function actionDeleteProvider(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(self::PERMISSION_MANAGE_AI_PROVIDERS);

        Typesense::$plugin->getAiProviders()->deleteProvider((string)$this->request->getRequiredBodyParam('id'));

        return $this->asSuccess(Craft::t('typesense', 'Provider deleted.'), [], 'typesense/ai-providers');
    }

    /**
     * The conversation model instance editor.
     *
     * @param string|null $handle
     * @return Response
     * @throws NotFoundHttpException
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionEditModel(?string $handle = null): Response
    {
        $providers = Typesense::$plugin->getAiProviders();
        $model = $handle !== null
            ? ($providers->getConversationModel($handle) ?? throw new NotFoundHttpException('Conversation model not found.'))
            : new ConversationModel();

        return $this->renderTemplate('typesense/ai-providers/_model', [
            'model' => $model,
            'isNew' => $handle === null,
            'providerOptions' => $this->_conversationProviderOptions(),
        ]);
    }

    /**
     * Persists a conversation model instance.
     *
     * @return Response|null
     * @throws \yii\web\BadRequestHttpException
     * @throws \Throwable
     * @author CraftPulse
     */
    public function actionSaveModel(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(self::PERMISSION_MANAGE_AI_PROVIDERS);

        $request = $this->request;
        $original = trim((string)$request->getBodyParam('originalHandle', ''));

        // A config-file conversation model is read-only; a write under its handle
        // would be shadowed by config on read (a silent inert write).
        if ($original !== '' && (Typesense::$plugin->getAiProviders()->getConversationModel($original)?->readOnly ?? false)) {
            return $this->asFailure(Craft::t('typesense', 'This conversation model is declared in config/typesense.php and is read only.'));
        }

        $model = new ConversationModel();
        $model->handle = $original !== '' ? $original : trim((string)$request->getBodyParam('handle', ''));
        $model->name = (string)$request->getBodyParam('name', '');
        $model->providerHandle = (string)$request->getBodyParam('providerHandle', '');
        $model->modelName = (string)$request->getBodyParam('modelName', '');
        $model->systemPrompt = (string)$request->getBodyParam('systemPrompt', '');
        $model->historyCollection = (string)$request->getBodyParam('historyCollection', '');
        $model->ttl = (int)$request->getBodyParam('ttl', 0);
        $model->maxBytes = (int)$request->getBodyParam('maxBytes', 0);

        if (!$model->validate()) {
            return $this->asModelFailure($model, Craft::t('typesense', 'Could not save the conversation model.'), 'model');
        }

        $errors = Typesense::$plugin->getAiProviders()->validateConversationModel($model);

        if ($errors !== []) {
            return $this->asFailure(Craft::t('typesense', 'Conversation model: {error}', ['error' => reset($errors)]));
        }

        if (!Typesense::$plugin->getAiProviders()->saveConversationModel($model, $original !== '' ? $original : null)) {
            return $this->asModelFailure($model, Craft::t('typesense', 'Could not save the conversation model.'), 'model');
        }

        return $this->asModelSuccess($model, Craft::t('typesense', 'Conversation model saved. Push it to the server to make it answerable.'), 'model', [], 'typesense/ai-providers');
    }

    /**
     * Deletes a conversation model instance from project config, and from the
     * server if it was pushed.
     *
     * @return Response|null
     * @throws \yii\web\BadRequestHttpException
     * @throws \Throwable
     * @author CraftPulse
     */
    public function actionDeleteModel(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(self::PERMISSION_MANAGE_AI_PROVIDERS);

        $handle = (string)$this->request->getRequiredBodyParam('id');
        Typesense::$plugin->getAiProviders()->deleteServerConversationModel($handle);
        Typesense::$plugin->getAiProviders()->deleteConversationModel($handle);

        return $this->asSuccess(Craft::t('typesense', 'Conversation model deleted.'), [], 'typesense/ai-providers');
    }

    /**
     * Pushes a conversation model instance to Typesense, resolving the provider's
     * credentials at push time. This is the explicit server operation, kept
     * separate from authoring so a save never makes a server call.
     *
     * @return Response|null
     * @throws \yii\web\BadRequestHttpException
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionPushModel(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(self::PERMISSION_MANAGE_AI_PROVIDERS);

        $handle = (string)$this->request->getRequiredBodyParam('id');
        $model = Typesense::$plugin->getAiProviders()->getConversationModel($handle);

        if ($model === null) {
            return $this->asFailure(Craft::t('typesense', 'Conversation model not found.'));
        }

        if (Typesense::$plugin->getAiProviders()->pushConversationModel($model) === null) {
            return $this->asFailure(Craft::t('typesense', 'Could not push the conversation model to the server.'));
        }

        return $this->asSuccess(Craft::t('typesense', 'Conversation model pushed to the server.'), [], 'typesense/ai-providers');
    }

    // Private Methods
    // =========================================================================

    /**
     * Reads the per-type credential env-reference fields from the posted form,
     * keyed as the chosen type declares.
     *
     * @param string $kind
     * @param string $type
     * @return array<string, string>
     * @author CraftPulse
     */
    private function _credentialsBody(string $kind, string $type): array
    {
        $spec = AiProviders::TYPES[$kind][$type] ?? null;

        if ($spec === null) {
            return [];
        }

        $credentials = [];

        foreach ($spec['credentials'] as $key) {
            $value = trim((string)$this->request->getBodyParam('cred_' . $type . '_' . $key, ''));

            if ($value !== '') {
                $credentials[$key] = $value;
            }
        }

        return $credentials;
    }

    /**
     * The conversation-kind provider select options for the model editor.
     *
     * @return array<int, array{label: string, value: string}>
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    private function _conversationProviderOptions(): array
    {
        $options = [['label' => Craft::t('typesense', 'Choose a provider'), 'value' => '']];

        foreach (Typesense::$plugin->getAiProviders()->getProviders(AiProviders::KIND_CONVERSATION) as $provider) {
            $options[] = ['label' => $provider->name, 'value' => $provider->handle];
        }

        return $options;
    }

    /**
     * A flat type-handle to label map across both kinds, for the index table.
     *
     * @return array<string, string>
     * @author CraftPulse
     */
    private function _typeLabels(): array
    {
        $labels = [];

        foreach (AiProviders::TYPES as $types) {
            foreach ($types as $type => $spec) {
                $labels[$type] = $spec['label'];
            }
        }

        return $labels;
    }
}
