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

use craft\base\ElementInterface;
use craft\base\FieldLayoutProviderInterface;
use craft\base\Model;
use craft\elements\Entry;
use craft\helpers\StringHelper;
use craft\models\FieldLayout;
use craftpulse\typesense\enums\MultisiteStrategy;

/**
 * A control-panel-managed collection definition, persisted to project config
 * (keyed by UID) and compiled into a runtime collection by the generated
 * transformer. It holds what the cockpit authors: the logical name, the element
 * type and source it indexes, the multisite strategy, per-field mappings (keyed
 * by field UID, authored in the mapping UI), and per-collection metadata such as
 * natural-language field descriptions.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class CollectionDefinition extends Model implements FieldLayoutProviderInterface
{
    // Private Properties
    // =========================================================================

    /**
     * @var FieldLayout|null The mapping field layout (the fields to index, each
     * carrying its Typesense mapping settings), lazily created.
     */
    private ?FieldLayout $_fieldLayout = null;

    // Public Properties
    // =========================================================================

    /**
     * @var string|null The project-config UID.
     */
    public ?string $uid = null;

    /**
     * @var string|null The mapping field layout's UID.
     */
    public ?string $fieldLayoutUid = null;

    /**
     * @var string The logical collection name (the registry key), which is also
     * the Typesense index name. Locked once the collection is created (its
     * immutability is the index-rename story). This is the handle-position field.
     */
    public string $name = '';

    /**
     * @var string The friendly display name shown throughout the control panel
     * (the title-position field). Free-form and editable any time; falls back to
     * the index name when unset (older configs, or a collection created before
     * this field existed).
     */
    public string $displayName = '';

    /**
     * @var class-string<ElementInterface> The element type the collection indexes.
     */
    public string $elementType = Entry::class;

    /**
     * @var string|null The element source the collection indexes (a section,
     * category group, volume, or user group handle), or null for every source
     * of the element type.
     */
    public ?string $source = null;

    /**
     * @var string The multisite strategy (a MultisiteStrategy value).
     */
    public string $multisite = MultisiteStrategy::SharedWithSiteFilter->value;

    /**
     * @var bool Whether the collection is enabled for syncing.
     */
    public bool $enabled = true;

    /**
     * @var bool Whether the anonymous front-end search endpoint may query this
     * collection. Off by default.
     */
    public bool $searchable = false;

    /**
     * @var array<string, array<string, mixed>> Per-field mappings, keyed by
     * field UID (authored in the mapping UI).
     */
    public array $mappings = [];

    /**
     * @var array<string, mixed> Per-collection metadata (for example natural
     * language field descriptions).
     */
    public array $metadata = [];

    /**
     * @var array<string, mixed> Auto-embedding config authored on the Vector / AI
     * section: `enabled`, `builtIn` (the built-in vs provider branch), `model` (a
     * ts/* id, or the remote model name), `providerHandle` (the embedding-kind AI
     * provider supplying credentials, for the remote branch), `from` (source field
     * handles, or the asset image field for CLIP), `dims` (remote model output
     * dimensions), and the optional `indexingPrefix` / `queryPrefix`.
     */
    public array $embedding = [];

    /**
     * @var array<string, mixed> Conversational ask config authored on the Vector /
     * AI section: `enabled` and `modelHandle` (the conversation model instance the
     * collection answers questions with). Off by default; every question is an LLM
     * call (cost), so the author opts in deliberately.
     */
    public array $conversation = [];

    /**
     * @var array<string, mixed> Relevance tuning authored in the presets editor:
     * `boostRules` (additive rules compiled to an indexed `boost_score`),
     * `textMatchBuckets`, `grouping` (`field`, `limit`), `mmr` (`enabled`,
     * `lambda`), and `searchPreset` (raw Typesense preset parameters).
     */
    public array $relevance = [];

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @return array<int, mixed>
     */
    public function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['name', 'elementType', 'multisite'], 'required'];
        $rules[] = [['name'], 'match', 'pattern' => '/^[a-zA-Z0-9_\-]+$/'];
        // displayName is not hard-required: it falls back to the index name (see
        // getDisplayName()), so a programmatic save without it is graceful. The
        // edit form marks the Name field required at the UI level.
        $rules[] = [['displayName'], 'string'];
        $rules[] = [['multisite'], 'in', 'range' => array_map(static fn(MultisiteStrategy $s): string => $s->value, MultisiteStrategy::cases())];
        $rules[] = [['enabled', 'searchable'], 'boolean'];
        $rules[] = [['mappings', 'metadata', 'relevance', 'embedding', 'conversation'], 'safe'];
        $rules[] = [['fieldLayoutUid'], 'safe'];

        return $rules;
    }

    /**
     * Returns the project-config representation (no UID, which is the key).
     *
     * @return array<string, mixed>
     * @author CraftPulse
     */
    public function getConfig(): array
    {
        $config = [
            'name' => $this->name,
            'displayName' => $this->getDisplayName(),
            'elementType' => $this->elementType,
            'source' => $this->source,
            'multisite' => $this->multisite,
            'enabled' => $this->enabled,
            'searchable' => $this->searchable,
            'metadata' => $this->metadata,
            'relevance' => $this->relevance,
            'embedding' => $this->embedding,
            'conversation' => $this->conversation,
        ];

        $layout = $this->getFieldLayout();

        if ($layoutConfig = $layout->getConfig()) {
            $config['fieldLayout'] = $layoutConfig;
            $config['fieldLayoutUid'] = $layout->uid;
        }

        return $config;
    }

    /**
     * @inheritdoc
     */
    public function getFieldLayout(): FieldLayout
    {
        if ($this->_fieldLayout === null) {
            $this->fieldLayoutUid ??= StringHelper::UUID();
            $this->_fieldLayout = new FieldLayout();
            $this->_fieldLayout->type = $this->elementType;
            $this->_fieldLayout->uid = $this->fieldLayoutUid;
            $this->_fieldLayout->provider = $this;
        }

        return $this->_fieldLayout;
    }

    /**
     * The friendly display name, falling back to the index name when unset (an
     * older config, or a collection created before the field existed).
     *
     * @return string
     * @author CraftPulse
     */
    public function getDisplayName(): string
    {
        return $this->displayName !== '' ? $this->displayName : $this->name;
    }

    /**
     * @inheritdoc
     */
    public function getHandle(): ?string
    {
        return $this->name !== '' ? $this->name : null;
    }

    /**
     * Sets the mapping field layout and binds it to this definition as its
     * provider so the palette events can scope to it.
     *
     * @param FieldLayout $fieldLayout
     * @return void
     * @author CraftPulse
     */
    public function setFieldLayout(FieldLayout $fieldLayout): void
    {
        $fieldLayout->type = $this->elementType;
        $fieldLayout->provider = $this;
        $this->fieldLayoutUid = $fieldLayout->uid ?? null;
        $this->_fieldLayout = $fieldLayout;
    }
}
