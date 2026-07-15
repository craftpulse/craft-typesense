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
use craftpulse\typesense\Typesense;
use Throwable;

/**
 * Experimental AI model configuration: conversational RAG models, and the
 * capability probes for the other experimental features the plan flags as shown,
 * honest, opt-in.
 *
 * These are experimental for concrete reasons the UI surfaces: conversational RAG
 * makes a per-search LLM call (cost), natural-language search can produce invalid
 * filter syntax that has to be retried, native personalization models are
 * undocumented upstream, and user-level BYO re-ranking needs an external model.
 * Credentials are read from environment variables, never stored raw, and no live
 * model call is ever made from validation.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class AiModels extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Lists the conversation (RAG) models on the server, fail-soft.
     *
     * @return array<int, array<string, mixed>>
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function conversationModels(): array
    {
        $response = Typesense::$plugin->getClient()->request('GET', '/conversations/models');

        return array_is_list($response) ? $response : array_values($response);
    }

    /**
     * Creates the conversation-history collection a RAG model stores turns in,
     * if it does not already exist.
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

    /**
     * Deletes a conversation model (fail-soft).
     *
     * @param string $id
     * @return void
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function deleteConversationModel(string $id): void
    {
        Typesense::$plugin->getClient()->request('DELETE', '/conversations/models/' . $id);
    }

    /**
     * The experimental features available on the connected server, each with its
     * honest reason. Version-gated features are omitted when unsupported (hide,
     * never badge).
     *
     * @return array<int, array{key: string, label: string, reason: string}>
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function experimentalFeatures(): array
    {
        $capabilities = Typesense::$plugin->getClient()->getServerCapabilities();
        $features = [
            ['key' => 'rag', 'label' => 'Conversational RAG', 'reason' => 'Makes a per-search call to an LLM provider, so each conversational query has a cost.'],
        ];

        if ($capabilities?->nlSearch() ?? false) {
            $features[] = ['key' => 'nlSearch', 'label' => 'Natural-language search', 'reason' => 'The model can produce invalid filter syntax that is retried, adding latency and occasional misses.'];
        }

        if ($capabilities?->personalizationModels() ?? false) {
            $features[] = ['key' => 'personalization', 'label' => 'Native personalization', 'reason' => 'Undocumented upstream and driven by analytics log rules; treat as unstable.'];
        }

        $features[] = ['key' => 'byoReranking', 'label' => 'User-level BYO re-ranking', 'reason' => 'Requires an external re-ranking model you host and operate.'];

        return $features;
    }

    /**
     * Creates or replaces a conversation (RAG) model, resolving the api_key from
     * an environment reference. Validates the shape first (no live call).
     *
     * @param array<string, mixed> $config id, model_name, api_key (env ref), history_collection, system_prompt, ...
     * @return array<string, mixed>|null null on validation failure or error.
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function upsertConversationModel(array $config): ?array
    {
        if ($this->validateConversationModel($config) !== []) {
            return null;
        }

        $config['api_key'] = (string)App::parseEnv((string)$config['api_key']);
        $this->ensureHistoryCollection((string)$config['history_collection']);

        $response = Typesense::$plugin->getClient()->request('POST', '/conversations/models', $config);

        return ($response['id'] ?? null) !== null ? $response : null;
    }

    /**
     * Validates a conversation-model config's shape and environment references,
     * never making a live call.
     *
     * @param array<string, mixed> $config
     * @return array<int, string> The validation errors (empty when valid).
     * @author CraftPulse
     */
    public function validateConversationModel(array $config): array
    {
        $errors = [];

        foreach (['id', 'model_name', 'history_collection'] as $key) {
            if (trim((string)($config[$key] ?? '')) === '') {
                $errors[] = "\"{$key}\" is required.";
            }
        }

        $apiKey = trim((string)($config['api_key'] ?? ''));

        if ($apiKey === '') {
            $errors[] = '"api_key" is required (as an environment reference).';
        } elseif ((string)App::parseEnv($apiKey) === '') {
            $errors[] = 'The environment variable for "api_key" resolves to an empty value.';
        }

        return $errors;
    }
}
