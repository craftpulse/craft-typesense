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

use craft\base\Component;
use craft\helpers\App;
use craftpulse\typesense\builders\Field;

/**
 * Embedding-model catalog and auto-embedding config builder.
 *
 * Knows the built-in ts/* models (ONNX, run on the server's CPU, no key) and the
 * remote providers (OpenAI, Azure, Google, GCP, Cloudflare, and OpenAI-compatible
 * custom endpoints), builds the Typesense `embed` model config from a stored
 * choice (resolving credentials from environment variables, never storing raw
 * keys), and validates a remote config's shape without ever making a live call.
 *
 * Changing a field's embedding config re-embeds every document (embeddings are
 * generated server-side at index time), so callers surface that cost honestly.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class Embeddings extends Component
{
    // Constants
    // =========================================================================

    /**
     * @var array<string, array{label: string, dims: int, kind: string}> The
     * built-in ts/* models: local ONNX inference, no API key.
     */
    public const BUILTIN_MODELS = [
        'ts/all-MiniLM-L12-v2' => ['label' => 'MiniLM L12 v2 (English, fast)', 'dims' => 384, 'kind' => 'text'],
        'ts/e5-small' => ['label' => 'E5 small (multilingual)', 'dims' => 384, 'kind' => 'text'],
        'ts/paraphrase-multilingual-MiniLM-L12-v2' => ['label' => 'Multilingual paraphrase MiniLM', 'dims' => 384, 'kind' => 'text'],
        'ts/clip-vit-b-p32' => ['label' => 'CLIP ViT-B/32 (image + text)', 'dims' => 512, 'kind' => 'image'],
    ];

    /**
     * @var array<string, array{label: string, keys: array<int, string>}> The
     * remote providers and the model-config keys each requires (beyond
     * model_name). `api_key` values are environment-variable references.
     */
    public const PROVIDERS = [
        'openai' => ['label' => 'OpenAI', 'keys' => ['api_key']],
        'azure' => ['label' => 'Azure OpenAI', 'keys' => ['api_key', 'url']],
        'google' => ['label' => 'Google AI', 'keys' => ['api_key']],
        'gcp' => ['label' => 'GCP Vertex AI', 'keys' => ['access_token', 'refresh_token', 'client_id', 'client_secret', 'project_id']],
        'cloudflare' => ['label' => 'Cloudflare Workers AI', 'keys' => ['api_key', 'url']],
        'custom' => ['label' => 'OpenAI-compatible (custom)', 'keys' => ['api_key', 'openai_url']],
    ];

    // Public Methods
    // =========================================================================

    /**
     * Builds the auto-embedding vector field for a collection from a stored
     * embedding config: `model` (a catalog id or `provider/name`), `from` (the
     * source field handles), and, for remote providers, credential env-var
     * references under `config`.
     *
     * @param string $name The vector field name.
     * @param array<string, mixed> $embedding
     * @return Field|null null when the config is incomplete.
     * @author CraftPulse
     */
    public function embedField(string $name, array $embedding): ?Field
    {
        $model = trim((string)($embedding['model'] ?? ''));
        $from = array_values(array_filter(array_map('strval', (array)($embedding['from'] ?? []))));

        if ($model === '' || $from === []) {
            return null;
        }

        return Field::vector($name, $this->dimsFor($model))
            ->optional()
            ->embedFrom($from)
            ->model($model, $this->_resolveConfig($embedding));
    }

    /**
     * The output dimensions for a model: the built-in catalog value, else the
     * stored `dims` override, else a safe default.
     *
     * @param string $model
     * @param int $fallback
     * @return int
     * @author CraftPulse
     */
    public function dimsFor(string $model, int $fallback = 384): int
    {
        return self::BUILTIN_MODELS[$model]['dims'] ?? $fallback;
    }

    /**
     * Whether a model is a built-in (ts/*) model.
     *
     * @param string $model
     * @return bool
     * @author CraftPulse
     */
    public function isBuiltIn(string $model): bool
    {
        return str_starts_with($model, 'ts/');
    }

    /**
     * Whether a model produces image embeddings (CLIP).
     *
     * @param string $model
     * @return bool
     * @author CraftPulse
     */
    public function isImageModel(string $model): bool
    {
        return (self::BUILTIN_MODELS[$model]['kind'] ?? '') === 'image';
    }

    /**
     * Validates an embedding config's shape without any live call: a model is
     * required, and a remote model must name a known provider and supply every
     * credential key that provider needs (as a non-empty env-resolved value).
     *
     * @param array<string, mixed> $embedding
     * @return array<int, string> The validation errors (empty when valid).
     * @author CraftPulse
     */
    public function validateModelConfig(array $embedding): array
    {
        $errors = [];
        $model = trim((string)($embedding['model'] ?? ''));

        if ($model === '') {
            return ['A model must be chosen.'];
        }

        if ($this->isBuiltIn($model)) {
            return $errors;
        }

        $provider = explode('/', $model, 2)[0];

        if (!isset(self::PROVIDERS[$provider])) {
            return ["Unknown embedding provider \"{$provider}\"."];
        }

        $config = is_array($embedding['config'] ?? null) ? $embedding['config'] : [];

        foreach (self::PROVIDERS[$provider]['keys'] as $key) {
            $raw = trim((string)($config[$key] ?? ''));

            if ($raw === '') {
                $errors[] = "The {$provider} provider requires \"{$key}\".";

                continue;
            }

            if ((string)App::parseEnv($raw) === '') {
                $errors[] = "The environment variable for \"{$key}\" resolves to an empty value.";
            }
        }

        return $errors;
    }

    // Private Methods
    // =========================================================================

    /**
     * Resolves a remote model's credential config, turning stored environment
     * references into their values. Built-in models carry no config.
     *
     * @param array<string, mixed> $embedding
     * @return array<string, mixed>
     * @author CraftPulse
     */
    private function _resolveConfig(array $embedding): array
    {
        $model = trim((string)($embedding['model'] ?? ''));

        if ($this->isBuiltIn($model)) {
            return [];
        }

        $provider = explode('/', $model, 2)[0];
        $config = is_array($embedding['config'] ?? null) ? $embedding['config'] : [];
        $resolved = [];

        foreach (self::PROVIDERS[$provider]['keys'] ?? [] as $key) {
            $raw = trim((string)($config[$key] ?? ''));

            if ($raw !== '') {
                $resolved[$key] = App::parseEnv($raw);
            }
        }

        return $resolved;
    }
}
