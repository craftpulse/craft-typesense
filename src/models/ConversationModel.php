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

/**
 * A named conversation (RAG) model instance: a reusable answer configuration a
 * searchable collection opts into with `->ask('handle')`. It references a
 * conversation-kind AI provider for its credentials, names the LLM model, and
 * holds the system prompt, the Typesense history collection turns are stored in,
 * the conversation time-to-live, and the answer byte budget.
 *
 * The instance is the plugin's authoring record; it is pushed to Typesense's
 * `/conversations/models` API (its handle is the server model id) with the
 * provider's credentials resolved from their environment references at push time.
 *
 * Instances declared in `config/typesense.php` are read-only in the control panel
 * (presence-based ownership); instances authored in the control panel live in
 * project config keyed by handle.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class ConversationModel extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var string The instance handle (the project-config key, the Typesense
     * server model id, and the reference used by `Collection->ask()`).
     */
    public string $handle = '';

    /**
     * @var string A human label for the control panel.
     */
    public string $name = '';

    /**
     * @var string The conversation-kind AI provider handle supplying the
     * credentials and endpoint.
     */
    public string $providerHandle = '';

    /**
     * @var string The LLM model name passed to the provider (for example
     * gpt-4o-mini).
     */
    public string $modelName = '';

    /**
     * @var string The system prompt prepended to every conversation.
     */
    public string $systemPrompt = '';

    /**
     * @var string The Typesense collection conversation turns are stored in.
     */
    public string $historyCollection = '';

    /**
     * @var int The conversation time-to-live, in seconds (0 uses the server
     * default).
     */
    public int $ttl = 0;

    /**
     * @var int The maximum answer size, in bytes (0 uses the server default).
     */
    public int $maxBytes = 0;

    /**
     * @var bool Whether the instance is declared in `config/typesense.php` and so
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
        $rules[] = [['handle', 'name', 'providerHandle', 'modelName', 'historyCollection'], 'required'];
        $rules[] = [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_\-]*$/'];
        $rules[] = [['ttl', 'maxBytes'], 'integer', 'min' => 0];
        $rules[] = [['systemPrompt'], 'safe'];

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
            'providerHandle' => $this->providerHandle,
            'modelName' => $this->modelName,
            'systemPrompt' => $this->systemPrompt,
            'historyCollection' => $this->historyCollection,
            'ttl' => $this->ttl,
            'maxBytes' => $this->maxBytes,
        ];
    }
}
