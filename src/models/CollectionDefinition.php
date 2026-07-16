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
    // Constants
    // =========================================================================

    /**
     * @var string A regular collection: one element source (the default). The
     * control-panel UI is unchanged for these.
     */
    public const COLLECTION_TYPE_REGULAR = 'regular';

    /**
     * @var string A union collection: many element-source members indexed into
     * one collection, distinguished by a reserved `_elementType` document field
     * (see Gap 1).
     */
    public const COLLECTION_TYPE_UNION = 'union';

    /**
     * @var string A section-shaped source (a section, category group, volume, tag
     * group, global set, or the users source): the collection indexes every entry
     * type of the source.
     */
    public const SOURCE_TYPE_SECTION = 'section';

    /**
     * @var string An entry-type-shaped source: the collection indexes a single
     * entry type directly, including a sectionless (nested Matrix / CKEditor)
     * entry type. See Gap 2.
     */
    public const SOURCE_TYPE_ENTRY_TYPE = 'entryType';

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
     * @var string The collection type (a COLLECTION_TYPE_* value): a regular
     * single-source collection (the default), or a union of many member sources.
     * Backfilled to `regular` on read for definitions saved before this existed.
     * Locked once the collection is saved (the schema shape differs fundamentally).
     */
    public string $collectionType = self::COLLECTION_TYPE_REGULAR;

    /**
     * @var class-string<ElementInterface> The element type the collection indexes.
     * For a union collection this is the first member's element type; the members
     * carry the full set (see [[members]] and [[getMembers()]]).
     */
    public string $elementType = Entry::class;

    /**
     * @var array<int, array<string, mixed>> The union member sources, each a map
     * of `handle` (unique, the `_elementType` discriminator value), `elementType`,
     * `source`, `sourceType`, and `fieldLayoutUid` (its own mapping layout). Empty
     * for a regular collection, whose single member is synthesized from the
     * top-level `elementType`/`source`/`sourceType`. See [[getMembers()]].
     */
    public array $members = [];

    /**
     * @var string|null The element source the collection indexes (a section,
     * category group, volume, or user group handle), or null for every source
     * of the element type. For an entry-type source (see [[sourceType]]) this is
     * the entry type handle.
     */
    public ?string $source = null;

    /**
     * @var string The source shape (a SOURCE_TYPE_* value): a section-shaped
     * source (the default, and the only shape for non-entry element types) or an
     * entry-type-shaped source that indexes a single, possibly nested, entry type.
     * Backfilled to `section` on read for definitions saved before this existed.
     */
    public string $sourceType = self::SOURCE_TYPE_SECTION;

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
        $rules[] = [['sourceType'], 'in', 'range' => [self::SOURCE_TYPE_SECTION, self::SOURCE_TYPE_ENTRY_TYPE]];
        $rules[] = [['collectionType'], 'in', 'range' => [self::COLLECTION_TYPE_REGULAR, self::COLLECTION_TYPE_UNION]];
        $rules[] = [['members'], 'safe'];
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
            'collectionType' => $this->collectionType,
            'elementType' => $this->elementType,
            'source' => $this->source,
            'sourceType' => $this->sourceType,
            'members' => $this->members,
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
     * Whether this is a union collection (many member sources into one collection).
     *
     * @return bool
     * @author CraftPulse
     */
    public function isUnion(): bool
    {
        return $this->collectionType === self::COLLECTION_TYPE_UNION;
    }

    /**
     * The collection's source members, each a descriptor of one element source to
     * index: `handle` (unique, the `_elementType` discriminator value),
     * `elementType`, `sourceType` (a SOURCE_TYPE_* value), `source` (the source
     * handle, or null for every source of the type), and `fieldLayoutUid` (the
     * member's own mapping layout, or null for a regular collection which uses the
     * top-level layout).
     *
     * A regular collection synthesizes a single member from its top-level
     * `elementType`/`source`/`sourceType`; a union collection returns its stored
     * members. The compiler, sync, and mapping layers read the source set only
     * through this accessor.
     *
     * @return array<int, array{handle: string, elementType: class-string<ElementInterface>, sourceType: string, source: string|null, fieldLayoutUid: string|null}>
     * @author CraftPulse
     */
    public function getMembers(): array
    {
        if ($this->isUnion() && $this->members !== []) {
            return array_values(array_map(function(array $member): array {
                /** @var class-string<ElementInterface> $elementType */
                $elementType = (string)($member['elementType'] ?? Entry::class);

                return [
                    'handle' => (string)($member['handle'] ?? ''),
                    'elementType' => $elementType,
                    'sourceType' => (string)($member['sourceType'] ?? self::SOURCE_TYPE_SECTION),
                    'source' => isset($member['source']) ? (string)$member['source'] : null,
                    'fieldLayoutUid' => isset($member['fieldLayoutUid']) ? (string)$member['fieldLayoutUid'] : null,
                ];
            }, $this->members));
        }

        return [[
            'handle' => $this->name !== '' ? $this->name : 'default',
            'elementType' => $this->elementType,
            'sourceType' => $this->sourceType,
            'source' => $this->source,
            'fieldLayoutUid' => $this->fieldLayoutUid,
        ]];
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
