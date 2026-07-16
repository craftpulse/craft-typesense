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
use craftpulse\typesense\models\AiProvider;
use craftpulse\typesense\Typesense;

/**
 * Embedding-model catalog and auto-embedding config builder.
 *
 * Knows the built-in ts/* models (ONNX, run on the server's CPU, no key), and
 * builds the Typesense `embed` model config from a stored choice: either a
 * built-in model, or a remote model whose credentials come from a referenced
 * embedding-kind AI provider (resolved from environment variables at build time,
 * never storing raw keys). It validates a config's shape without ever making a
 * live call.
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

    // Public Methods
    // =========================================================================

    /**
     * Builds the auto-embedding vector field for a collection from a stored
     * embedding config: `builtIn` (the built-in vs provider branch), `model` (a
     * ts/* id, or the remote model name), `providerHandle` (the embedding-kind AI
     * provider supplying credentials, for the remote branch), `from` (the source
     * field handles), `dims` (the remote model output dimensions), and the
     * optional `indexingPrefix` / `queryPrefix`.
     *
     * @param string $name The vector field name.
     * @param array<string, mixed> $embedding
     * @return Field|null null when the config is incomplete.
     * @author CraftPulse
     */
    public function embedField(string $name, array $embedding): ?Field
    {
        $from = array_values(array_filter(array_map('strval', (array)($embedding['from'] ?? []))));
        $model = trim((string)($embedding['model'] ?? ''));

        if ($from === [] || $model === '') {
            return null;
        }

        if ($this->_isBuiltInBranch($embedding)) {
            if (!$this->isBuiltIn($model)) {
                return null;
            }

            return Field::vector($name, $this->dimsFor($model))
                ->optional()
                ->embedFrom($from)
                ->model($model, $this->_prefixes($embedding));
        }

        $provider = $this->_provider($embedding);

        if ($provider === null) {
            return null;
        }

        $dims = (int)($embedding['dims'] ?? 0);

        return Field::vector($name, $dims > 0 ? $dims : $this->dimsFor($model))
            ->optional()
            ->embedFrom($from)
            ->model($model, array_merge($this->_prefixes($embedding), $this->_providerConfig($provider)));
    }

    /**
     * The output dimensions for a model: the built-in catalog value, else a safe
     * default the remote branch overrides with its stored `dims`.
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
     * required; a built-in model must be a known ts/* id; a remote model must
     * reference an embedding-kind provider whose own credentials validate.
     *
     * @param array<string, mixed> $embedding
     * @return array<int, string> The validation errors (empty when valid).
     * @author CraftPulse
     */
    public function validateModelConfig(array $embedding): array
    {
        $model = trim((string)($embedding['model'] ?? ''));

        if ($model === '') {
            return ['A model must be chosen.'];
        }

        if ($this->_isBuiltInBranch($embedding)) {
            return $this->isBuiltIn($model) ? [] : ["\"{$model}\" is not a built-in model."];
        }

        $handle = trim((string)($embedding['providerHandle'] ?? ''));
        $provider = $this->_provider($embedding);

        if ($provider === null) {
            return ["Unknown AI provider \"{$handle}\"."];
        }

        if ($provider->kind !== AiProviders::KIND_EMBEDDING) {
            return ["The provider \"{$handle}\" is not an embedding provider."];
        }

        return Typesense::$plugin->getAiProviders()->validateProvider($provider);
    }

    // Private Methods
    // =========================================================================

    /**
     * Whether the config uses the built-in branch (default), rather than a remote
     * provider.
     *
     * @param array<string, mixed> $embedding
     * @return bool
     * @author CraftPulse
     */
    private function _isBuiltInBranch(array $embedding): bool
    {
        return (bool)($embedding['builtIn'] ?? true);
    }

    /**
     * The referenced embedding-kind AI provider, or null.
     *
     * @param array<string, mixed> $embedding
     * @return AiProvider|null
     * @author CraftPulse
     */
    private function _provider(array $embedding): ?AiProvider
    {
        $handle = trim((string)($embedding['providerHandle'] ?? ''));

        if ($handle === '') {
            return null;
        }

        $provider = Typesense::$plugin->getAiProviders()->getProvider($handle);

        return $provider?->kind === AiProviders::KIND_EMBEDDING ? $provider : null;
    }

    /**
     * Resolves a provider's credentials and endpoint into the Typesense
     * `model_config` keys, turning stored environment references into values. The
     * endpoint maps to `openai_url` for the OpenAI-compatible type and `url`
     * otherwise.
     *
     * @param AiProvider $provider
     * @return array<string, mixed>
     * @author CraftPulse
     */
    private function _providerConfig(AiProvider $provider): array
    {
        $config = Typesense::$plugin->getAiProviders()->resolveCredentials($provider);

        if ($provider->endpoint !== '') {
            $config[$provider->type === 'custom' ? 'openai_url' : 'url'] = App::parseEnv($provider->endpoint);
        }

        return $config;
    }

    /**
     * The optional indexing/query embedding prefixes, as `model_config` keys.
     *
     * @param array<string, mixed> $embedding
     * @return array<string, mixed>
     * @author CraftPulse
     */
    private function _prefixes(array $embedding): array
    {
        // Prefixes carry meaningful trailing spaces (for example E5's "query: "),
        // so keep the raw value and only skip it when it is blank.
        $prefixes = [];
        $indexing = (string)($embedding['indexingPrefix'] ?? '');
        $query = (string)($embedding['queryPrefix'] ?? '');

        if (trim($indexing) !== '') {
            $prefixes['indexing_prefix'] = $indexing;
        }

        if (trim($query) !== '') {
            $prefixes['query_prefix'] = $query;
        }

        return $prefixes;
    }
}
