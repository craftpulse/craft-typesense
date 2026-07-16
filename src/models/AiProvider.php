<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\models;

use craft\base\Model;
use craftpulse\typesense\services\AiProviders;

/**
 * A named AI provider: the reusable credential-and-endpoint record the embedding
 * and conversation subsystems draw on. A provider names a subsystem (its `kind`:
 * embedding or conversation), a provider `type` (OpenAI, Azure, Cloudflare, GCP
 * Vertex, an OpenAI-compatible endpoint, or the Anthropic gateway preset), an
 * optional `endpoint` URL, and per-type credentials held as environment-variable
 * references, never raw secrets. The references resolve to their values only at
 * the moment a config is sent to Typesense.
 *
 * Providers declared in `config/typesense.php` are read-only in the control panel
 * (presence-based ownership); providers authored in the control panel live in
 * project config keyed by handle.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class AiProvider extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var string The provider handle (the project-config key and the reference
     * used by conversation models and embedding configs).
     */
    public string $handle = '';

    /**
     * @var string A human label for the control panel.
     */
    public string $name = '';

    /**
     * @var string The subsystem the provider serves (an AiProviders::KIND_* value:
     * embedding or conversation).
     */
    public string $kind = AiProviders::KIND_EMBEDDING;

    /**
     * @var string The provider type (a key in the AiProviders type catalog for
     * this kind, for example openai, azure, cloudflare, gcp, custom, or the
     * anthropic-gateway conversation preset).
     */
    public string $type = '';

    /**
     * @var string The provider endpoint URL, for types that need one (Azure,
     * Cloudflare, vLLM, OpenAI-compatible, the Anthropic gateway). Empty otherwise.
     */
    public string $endpoint = '';

    /**
     * @var array<string, string> The per-type credentials, each an
     * environment-variable reference (for example `$OPENAI_API_KEY`), keyed by the
     * credential key the type declares. Never a raw secret.
     */
    public array $credentials = [];

    /**
     * @var bool Whether the provider is available for selection.
     */
    public bool $enabled = true;

    /**
     * @var bool Whether the provider is declared in `config/typesense.php` and so
     * read-only in the control panel (presence-based ownership). Transient: never
     * written to project config.
     */
    public bool $readOnly = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @return array<int, mixed>
     */
    public function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['handle', 'name', 'kind', 'type'], 'required'];
        $rules[] = [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_\-]*$/'];
        $rules[] = [['kind'], 'in', 'range' => array_keys(AiProviders::TYPES)];
        $rules[] = [['enabled'], 'boolean'];
        $rules[] = [['endpoint'], 'string'];
        $rules[] = [['credentials'], 'safe'];

        return $rules;
    }

    /**
     * Returns the project-config representation (no handle, which is the key, and
     * no transient read-only flag).
     *
     * @return array<string, mixed>
     * @author CraftPulse
     */
    public function getConfig(): array
    {
        return [
            'name' => $this->name,
            'kind' => $this->kind,
            'type' => $this->type,
            'endpoint' => $this->endpoint,
            'credentials' => $this->credentials,
            'enabled' => $this->enabled,
        ];
    }
}
