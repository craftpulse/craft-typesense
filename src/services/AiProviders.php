<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use craftpulse\typesense\models\AiProvider;
use craftpulse\typesense\models\ConversationModel;
use craftpulse\typesense\models\Settings;
use craftpulse\typesense\Typesense;
use Throwable;

/**
 * The AI provider registry: the authoring and resolution layer behind the
 * embedding and conversation subsystems.
 *
 * Holds two resources, each stored in project config keyed by handle and merged
 * with the read-only declarations from `config/typesense.php` (presence-based
 * ownership, config wins): AI providers (credential-and-endpoint records) and
 * conversation model instances (named answer configurations that reference a
 * provider). It carries the per-kind provider type catalog the editor renders
 * from, resolves a provider's environment-referenced credentials to their values
 * at the moment a config is sent to Typesense (never storing raw secrets), and
 * pushes conversation model instances to Typesense's `/conversations/models` API.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class AiProviders extends Component
{
    // Constants
    // =========================================================================

    /**
     * @var string The embedding subsystem kind.
     */
    public const KIND_EMBEDDING = 'embedding';

    /**
     * @var string The conversation (RAG) subsystem kind.
     */
    public const KIND_CONVERSATION = 'conversation';

    /**
     * @var string The project-config key AI providers live under.
     */
    public const CONFIG_KEY_PROVIDERS = 'typesense.aiProviders';

    /**
     * @var string The project-config key conversation model instances live under.
     */
    public const CONFIG_KEY_MODELS = 'typesense.conversationModels';

    /**
     * @var array<string, array<string, array{label: string, credentials: array<int, string>, endpoint: bool, docUrl?: string, presetOf?: string}>>
     * The per-kind provider type catalog: for each type, its label, the credential
     * keys it needs (each an environment reference), whether it needs an endpoint
     * URL, and (for a guided preset) a documentation URL and the base type it
     * presets. The Anthropic conversation type is a guided preset over the
     * OpenAI-compatible type, kept as a distinct branch so the editor can label it
     * and link the gateway walkthrough.
     */
    public const TYPES = [
        self::KIND_EMBEDDING => [
            'openai' => ['label' => 'OpenAI', 'credentials' => ['api_key'], 'endpoint' => false],
            'azure' => ['label' => 'Azure OpenAI', 'credentials' => ['api_key'], 'endpoint' => true],
            'google' => ['label' => 'Google AI (PaLM)', 'credentials' => ['api_key'], 'endpoint' => false],
            'gcp' => ['label' => 'GCP Vertex AI', 'credentials' => ['access_token', 'refresh_token', 'client_id', 'client_secret', 'project_id'], 'endpoint' => false],
            'cloudflare' => ['label' => 'Cloudflare Workers AI', 'credentials' => ['api_key', 'account_id'], 'endpoint' => false],
            'custom' => ['label' => 'OpenAI-compatible (custom)', 'credentials' => ['api_key'], 'endpoint' => true],
        ],
        self::KIND_CONVERSATION => [
            'openai' => ['label' => 'OpenAI', 'credentials' => ['api_key'], 'endpoint' => false],
            'cloudflare' => ['label' => 'Cloudflare Workers AI', 'credentials' => ['api_key', 'account_id'], 'endpoint' => false],
            'vllm' => ['label' => 'vLLM (self-hosted)', 'credentials' => [], 'endpoint' => true],
            'custom' => ['label' => 'OpenAI-compatible (custom)', 'credentials' => ['api_key'], 'endpoint' => true],
            'anthropic-gateway' => [
                'label' => 'Anthropic (via OpenAI-compatible gateway)',
                'credentials' => ['api_key'],
                'endpoint' => true,
                'docUrl' => 'https://craft-pulse.com/docs/typesense/ai-providers#anthropic-gateway',
                'presetOf' => 'custom',
            ],
        ],
    ];

    // Public Methods
    // =========================================================================

    /**
     * Returns every AI provider, keyed by handle: the control-panel-authored ones
     * from project config, overlaid by the read-only ones from the config file
     * (config wins).
     *
     * @param string|null $kind Restrict to a single kind, or null for all.
     * @return array<string, AiProvider>
     * @author CraftPulse
     */
    public function getProviders(?string $kind = null): array
    {
        $providers = $this->getManagedProviders();

        // Config-file providers win over control-panel ones and are read-only.
        foreach ($this->getConfigProviders() as $handle => $provider) {
            $providers[$handle] = $provider;
        }

        if ($kind !== null) {
            $providers = array_filter($providers, static fn(AiProvider $p): bool => $p->kind === $kind);
        }

        return $providers;
    }

    /**
     * Returns only the control-panel-authored providers (project config), keyed by
     * handle.
     *
     * @return array<string, AiProvider>
     * @author CraftPulse
     */
    public function getManagedProviders(): array
    {
        $stored = Craft::$app->getProjectConfig()->get(self::CONFIG_KEY_PROVIDERS) ?? [];
        $providers = [];

        foreach ($stored as $handle => $config) {
            if (is_array($config)) {
                $providers[(string)$handle] = $this->_providerFromConfig((string)$handle, $config, false);
            }
        }

        return $providers;
    }

    /**
     * Returns only the config-file-declared providers (from `config/typesense.php`),
     * keyed by handle. Each is flagged read-only.
     *
     * @return array<string, AiProvider>
     * @author CraftPulse
     */
    public function getConfigProviders(): array
    {
        $providers = [];

        foreach ($this->_settings()->aiProviders as $handle => $declaration) {
            $provider = $this->_normalizeDeclaration($declaration, (string)$handle, AiProvider::class);

            if ($provider instanceof AiProvider) {
                $provider->readOnly = true;
                $providers[$provider->handle] = $provider;
            }
        }

        return $providers;
    }

    /**
     * Returns a provider by handle, or null.
     *
     * @param string $handle
     * @return AiProvider|null
     * @author CraftPulse
     */
    public function getProvider(string $handle): ?AiProvider
    {
        return $this->getProviders()[$handle] ?? null;
    }

    /**
     * Validates a provider and writes it to project config. When `$originalHandle`
     * differs from the provider's handle (a create, or a rename) the target handle
     * must be free: the handle is the storage key (unlike a UUID-keyed
     * collection), so writing onto an existing handle would silently clobber the
     * other record.
     *
     * @param AiProvider $provider
     * @param string|null $originalHandle the handle being edited, or null on create
     * @return bool false when the provider fails validation or the handle is taken
     * @throws \yii\base\ErrorException
     * @throws \yii\base\Exception
     * @throws \yii\base\NotSupportedException
     * @throws \yii\web\ServerErrorHttpException
     * @author CraftPulse
     */
    public function saveProvider(AiProvider $provider, ?string $originalHandle = null): bool
    {
        if (!$provider->validate() || $this->validateProvider($provider) !== []) {
            return false;
        }

        if ($provider->handle !== $originalHandle && $this->getProvider($provider->handle) !== null) {
            $provider->addError('handle', Craft::t('typesense', 'That handle is already in use.'));

            return false;
        }

        Craft::$app->getProjectConfig()->set(
            self::CONFIG_KEY_PROVIDERS . '.' . $provider->handle,
            $provider->getConfig(),
            "Save Typesense AI provider \u{201C}{$provider->handle}\u{201D}",
        );

        Typesense::$plugin->getAudit()->record(Audit::EVENT_AI_PROVIDER_SAVED, [
            'handle' => $provider->handle,
            'type' => $provider->type,
            'kind' => $provider->kind,
        ]);

        return true;
    }

    /**
     * Deletes a provider from project config.
     *
     * @param string $handle
     * @return void
     * @throws \yii\base\ErrorException
     * @throws \yii\base\Exception
     * @throws \yii\base\NotSupportedException
     * @throws \yii\web\ServerErrorHttpException
     * @author CraftPulse
     */
    public function deleteProvider(string $handle): void
    {
        Craft::$app->getProjectConfig()->remove(
            self::CONFIG_KEY_PROVIDERS . '.' . $handle,
            "Delete Typesense AI provider \u{201C}{$handle}\u{201D}",
        );

        Typesense::$plugin->getAudit()->record(Audit::EVENT_AI_PROVIDER_DELETED, [
            'handle' => $handle,
        ]);
    }

    /**
     * Validates a provider's shape against its type catalog entry and resolves its
     * credential references, never making a live call: the type must be known for
     * the kind, an endpoint-requiring type must have an endpoint, and every
     * declared credential must be present and resolve to a non-empty value.
     *
     * @param AiProvider $provider
     * @return array<int, string> The validation errors (empty when valid).
     * @author CraftPulse
     */
    public function validateProvider(AiProvider $provider): array
    {
        $spec = self::TYPES[$provider->kind][$provider->type] ?? null;

        if ($spec === null) {
            return ["Unknown {$provider->kind} provider type \"{$provider->type}\"."];
        }

        $errors = [];

        if ($spec['endpoint'] && trim($provider->endpoint) === '') {
            $errors[] = 'An endpoint URL is required for this provider type.';
        }

        foreach ($spec['credentials'] as $key) {
            $raw = trim((string)($provider->credentials[$key] ?? ''));

            if ($raw === '') {
                $errors[] = "The credential \"{$key}\" is required (as an environment reference).";

                continue;
            }

            if ((string)App::parseEnv($raw) === '') {
                $errors[] = "The environment variable for \"{$key}\" resolves to an empty value.";
            }
        }

        return $errors;
    }

    /**
     * Resolves a provider's environment-referenced credentials to their values,
     * keyed as its type declares. Called only at the moment a config is sent to
     * Typesense, never stored.
     *
     * @param AiProvider $provider
     * @return array<string, mixed>
     * @author CraftPulse
     */
    public function resolveCredentials(AiProvider $provider): array
    {
        $spec = self::TYPES[$provider->kind][$provider->type] ?? null;
        $resolved = [];

        foreach ($spec['credentials'] ?? [] as $key) {
            $raw = trim((string)($provider->credentials[$key] ?? ''));

            if ($raw !== '') {
                $resolved[$key] = App::parseEnv($raw);
            }
        }

        return $resolved;
    }

    // Conversation models
    // -------------------------------------------------------------------------

    /**
     * Returns every conversation model instance, keyed by handle: the
     * control-panel-authored ones from project config, overlaid by the read-only
     * ones from the config file (config wins).
     *
     * @return array<string, ConversationModel>
     * @author CraftPulse
     */
    public function getConversationModels(): array
    {
        $models = $this->getManagedConversationModels();

        // Config-file instances win over control-panel ones and are read-only.
        foreach ($this->getConfigConversationModels() as $handle => $model) {
            $models[$handle] = $model;
        }

        return $models;
    }

    /**
     * Returns only the control-panel-authored conversation model instances
     * (project config), keyed by handle.
     *
     * @return array<string, ConversationModel>
     * @author CraftPulse
     */
    public function getManagedConversationModels(): array
    {
        $stored = Craft::$app->getProjectConfig()->get(self::CONFIG_KEY_MODELS) ?? [];
        $models = [];

        foreach ($stored as $handle => $config) {
            if (is_array($config)) {
                $models[(string)$handle] = $this->_modelFromConfig((string)$handle, $config, false);
            }
        }

        return $models;
    }

    /**
     * Returns only the config-file-declared conversation model instances (from
     * `config/typesense.php`), keyed by handle. Each is flagged read-only.
     *
     * @return array<string, ConversationModel>
     * @author CraftPulse
     */
    public function getConfigConversationModels(): array
    {
        $models = [];

        foreach ($this->_settings()->conversationModels as $handle => $declaration) {
            $model = $this->_normalizeDeclaration($declaration, (string)$handle, ConversationModel::class);

            if ($model instanceof ConversationModel) {
                $model->readOnly = true;
                $models[$model->handle] = $model;
            }
        }

        return $models;
    }

    /**
     * Returns a conversation model instance by handle, or null.
     *
     * @param string $handle
     * @return ConversationModel|null
     * @author CraftPulse
     */
    public function getConversationModel(string $handle): ?ConversationModel
    {
        return $this->getConversationModels()[$handle] ?? null;
    }

    /**
     * Validates a conversation model instance and writes it to project config.
     * When `$originalHandle` differs from the instance's handle (a create, or a
     * rename) the target handle must be free: the handle is the storage key, so
     * writing onto an existing handle would silently clobber the other record.
     *
     * @param ConversationModel $model
     * @param string|null $originalHandle the handle being edited, or null on create
     * @return bool false when the instance fails validation or the handle is taken
     * @throws \yii\base\ErrorException
     * @throws \yii\base\Exception
     * @throws \yii\base\NotSupportedException
     * @throws \yii\web\ServerErrorHttpException
     * @author CraftPulse
     */
    public function saveConversationModel(ConversationModel $model, ?string $originalHandle = null): bool
    {
        if (!$model->validate() || $this->validateConversationModel($model) !== []) {
            return false;
        }

        if ($model->handle !== $originalHandle && $this->getConversationModel($model->handle) !== null) {
            $model->addError('handle', Craft::t('typesense', 'That handle is already in use.'));

            return false;
        }

        Craft::$app->getProjectConfig()->set(
            self::CONFIG_KEY_MODELS . '.' . $model->handle,
            $model->getConfig(),
            "Save Typesense conversation model \u{201C}{$model->handle}\u{201D}",
        );

        Typesense::$plugin->getAudit()->record(Audit::EVENT_AI_MODEL_SAVED, [
            'handle' => $model->handle,
            'providerHandle' => $model->providerHandle,
            'modelName' => $model->modelName,
        ]);

        return true;
    }

    /**
     * Deletes a conversation model instance from project config. The server-side
     * Typesense model, if pushed, is removed separately.
     *
     * @param string $handle
     * @return void
     * @throws \yii\base\ErrorException
     * @throws \yii\base\Exception
     * @throws \yii\base\NotSupportedException
     * @throws \yii\web\ServerErrorHttpException
     * @author CraftPulse
     */
    public function deleteConversationModel(string $handle): void
    {
        Craft::$app->getProjectConfig()->remove(
            self::CONFIG_KEY_MODELS . '.' . $handle,
            "Delete Typesense conversation model \u{201C}{$handle}\u{201D}",
        );

        Typesense::$plugin->getAudit()->record(Audit::EVENT_AI_MODEL_DELETED, [
            'handle' => $handle,
        ]);
    }

    /**
     * Validates a conversation model instance's references without a live call: it
     * must point at a known conversation-kind provider, and that provider's
     * credentials must themselves validate.
     *
     * @param ConversationModel $model
     * @return array<int, string> The validation errors (empty when valid).
     * @author CraftPulse
     */
    public function validateConversationModel(ConversationModel $model): array
    {
        $provider = $this->getProvider($model->providerHandle);

        if ($provider === null) {
            return ["Unknown AI provider \"{$model->providerHandle}\"."];
        }

        if ($provider->kind !== self::KIND_CONVERSATION) {
            return ["The provider \"{$model->providerHandle}\" is not a conversation provider."];
        }

        return $this->validateProvider($provider);
    }

    /**
     * Pushes a conversation model instance to Typesense's `/conversations/models`
     * API, resolving the referenced provider's credentials from their environment
     * references at push time. The instance handle is the server model id. Ensures
     * the history collection exists first. Returns the server model id, or null on
     * a validation failure or error.
     *
     * @param ConversationModel $model
     * @return string|null
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function pushConversationModel(ConversationModel $model): ?string
    {
        if ($this->validateConversationModel($model) !== []) {
            return null;
        }

        $provider = $this->getProvider($model->providerHandle);

        if ($provider === null) {
            return null;
        }

        $this->ensureHistoryCollection($model->historyCollection);

        $payload = array_merge($this->resolveCredentials($provider), [
            'id' => $model->handle,
            'model_name' => $model->modelName,
            'history_collection' => $model->historyCollection,
            'system_prompt' => $model->systemPrompt,
        ]);

        if ($provider->endpoint !== '') {
            $payload['vllm_url'] = $provider->endpoint;
        }

        if ($model->ttl > 0) {
            $payload['ttl'] = $model->ttl;
        }

        if ($model->maxBytes > 0) {
            $payload['max_bytes'] = $model->maxBytes;
        }

        $response = Typesense::$plugin->getClient()->request('POST', '/conversations/models', $payload);

        if (($response['id'] ?? null) === null) {
            return null;
        }

        Typesense::$plugin->getAudit()->record(Audit::EVENT_AI_MODEL_PUSHED, [
            'handle' => $model->handle,
            'providerHandle' => $model->providerHandle,
            'modelName' => $model->modelName,
        ]);

        return (string)$response['id'];
    }

    /**
     * Lists the conversation models currently on the server, fail-soft.
     *
     * @return array<int, array<string, mixed>>
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function serverConversationModels(): array
    {
        try {
            $response = Typesense::$plugin->getClient()->request('GET', '/conversations/models');
        } catch (Throwable) {
            return [];
        }

        return array_is_list($response) ? $response : array_values($response);
    }

    /**
     * Deletes a conversation model from the server by its id (fail-soft).
     *
     * @param string $id
     * @return void
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function deleteServerConversationModel(string $id): void
    {
        try {
            Typesense::$plugin->getClient()->request('DELETE', '/conversations/models/' . $id);
        } catch (Throwable $e) {
            Craft::error("Could not delete Typesense conversation model '{$id}': {$e->getMessage()}", 'typesense');
        }
    }

    /**
     * Creates the conversation-history collection a RAG model stores turns in, if
     * it does not already exist (fail-soft).
     *
     * @param string $name
     * @return void
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function ensureHistoryCollection(string $name): void
    {
        $client = Typesense::$plugin->getClient()->client();

        if ($client === null) {
            return;
        }

        try {
            $client->collections[$name]->retrieve();

            return;
        } catch (Throwable) {
            // Not present: create it below.
        }

        try {
            $client->collections->create([
                'name' => $name,
                'fields' => [
                    ['name' => 'conversation_id', 'type' => 'string'],
                    ['name' => 'model_id', 'type' => 'string'],
                    ['name' => 'timestamp', 'type' => 'int32'],
                    ['name' => 'role', 'type' => 'string', 'index' => false],
                    ['name' => 'message', 'type' => 'string', 'index' => false],
                ],
            ]);
        } catch (Throwable $e) {
            Craft::error("Could not create conversation history collection '{$name}': {$e->getMessage()}", 'typesense');
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * Hydrates a provider model from a stored project-config array.
     *
     * @param string $handle
     * @param array<string, mixed> $config
     * @param bool $readOnly
     * @return AiProvider
     * @author CraftPulse
     */
    private function _providerFromConfig(string $handle, array $config, bool $readOnly): AiProvider
    {
        $provider = new AiProvider();
        $provider->handle = $handle;
        $provider->name = (string)($config['name'] ?? '');
        $provider->kind = (string)($config['kind'] ?? self::KIND_EMBEDDING);
        $provider->type = (string)($config['type'] ?? '');
        $provider->endpoint = (string)($config['endpoint'] ?? '');
        $provider->credentials = is_array($config['credentials'] ?? null) ? array_map('strval', $config['credentials']) : [];
        $provider->enabled = (bool)($config['enabled'] ?? true);
        $provider->readOnly = $readOnly;

        return $provider;
    }

    /**
     * Hydrates a conversation model instance from a stored project-config array.
     *
     * @param string $handle
     * @param array<string, mixed> $config
     * @param bool $readOnly
     * @return ConversationModel
     * @author CraftPulse
     */
    private function _modelFromConfig(string $handle, array $config, bool $readOnly): ConversationModel
    {
        $model = new ConversationModel();
        $model->handle = $handle;
        $model->name = (string)($config['name'] ?? '');
        $model->providerHandle = (string)($config['providerHandle'] ?? '');
        $model->modelName = (string)($config['modelName'] ?? '');
        $model->systemPrompt = (string)($config['systemPrompt'] ?? '');
        $model->historyCollection = (string)($config['historyCollection'] ?? '');
        $model->ttl = (int)($config['ttl'] ?? 0);
        $model->maxBytes = (int)($config['maxBytes'] ?? 0);
        $model->readOnly = $readOnly;

        return $model;
    }

    /**
     * Normalizes a config-file declaration (already a model instance, or an array)
     * into the given model class, applying the array-key handle when the array
     * omits one.
     *
     * @param mixed $declaration
     * @param string $key
     * @param class-string<AiProvider|ConversationModel> $class
     * @return AiProvider|ConversationModel|null
     * @author CraftPulse
     */
    private function _normalizeDeclaration(mixed $declaration, string $key, string $class): AiProvider|ConversationModel|null
    {
        if ($declaration instanceof $class) {
            if ($declaration->handle === '') {
                $declaration->handle = $key;
            }

            return $declaration;
        }

        if (!is_array($declaration)) {
            return null;
        }

        $handle = (string)($declaration['handle'] ?? $key);

        return $class === AiProvider::class
            ? $this->_providerFromConfig($handle, $declaration, true)
            : $this->_modelFromConfig($handle, $declaration, true);
    }

    /**
     * @return Settings
     * @author CraftPulse
     */
    private function _settings(): Settings
    {
        /** @var Settings $settings */
        $settings = Typesense::$plugin->getSettings();

        return $settings;
    }
}
