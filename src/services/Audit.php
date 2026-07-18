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
use craftpulse\auditkit\audit\AuditEvent;
use craftpulse\auditkit\audit\AuditEventType;
use craftpulse\auditkit\AuditKit;
use craftpulse\auditkit\events\RegisterAuditEventsEvent;
use craftpulse\auditkit\services\Bus;
use yii\base\Component;

/**
 * Audit is Typesense's emitter into the craft-audit-kit bus. It registers one
 * {@see AuditEventType} per auditable Typesense surface (the fail-closed,
 * scalar-only, no-PII privacy contract) and dispatches native {@see AuditEvent}s
 * describing security- and governance-relevant actions: API-key and scoped-key
 * lifecycle, AI-provider and conversation-model credential changes, collection /
 * mapping / schema / config writes, index flush / sync / suspend / resume, the
 * alias deploy swap, curation / synonym / dictionary / relevance changes, and
 * snapshot / cache / settings operations.
 *
 * Emitting is a no-op when Audit Kit is not installed (the plugin resolves the
 * bus defensively), and a no-op when Audit Kit is installed but no recorder
 * (such as Ledger) has registered a sink. Every emission therefore ships safely
 * regardless of what is downstream.
 *
 * KEY AND CREDENTIAL VALUES NEVER ENTER details. An API key's `value`, a derived
 * scoped key, and a provider's resolved credentials are secrets; only key ids,
 * key/profile/provider/model handles, and scope descriptors are recorded. The
 * per-event {@see AuditEventType} allowlist is the codified enforcement of that
 * rule downstream, and every call site here passes only the allowed scalar keys.
 *
 * Emission lands at the seam that fires exactly once per user action. Where a CP
 * controller and a console command converge on one leaf service method (API
 * keys, AI providers, suspend / resume via the toggle, the alias clone), the
 * emission is at the service, so console operations emit too (with a null actor).
 * Where a service method is internally reused by other service methods (the Sync
 * service's flush / syncAll fan out into syncCollection; curation and synonym
 * upserts are called by seed and pin helpers), emission is at each entry point
 * instead, to avoid double or per-row emission. An instance is available via
 * `Typesense::$plugin->getAudit()`.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class Audit extends Component
{
    // Constants
    // =========================================================================

    /**
     * @var string The emitting plugin's handle, stamped on every event.
     */
    public const EMITTER = 'typesense';

    /**
     * @var string The coarse grouping every Typesense event lands in.
     */
    public const CATEGORY_SYSTEM = 'system';

    /**
     * @var string A Typesense API key was created on the server.
     */
    public const EVENT_KEY_CREATED = 'typesense.key.created';

    /**
     * @var string A Typesense API key was deleted from the server.
     */
    public const EVENT_KEY_DELETED = 'typesense.key.deleted';

    /**
     * @var string A scoped search key was derived (administrative console tool).
     */
    public const EVENT_KEY_SCOPED_GENERATED = 'typesense.key.scoped_generated';

    /**
     * @var string A scoped-key profile was saved to project config.
     */
    public const EVENT_KEY_PROFILE_SAVED = 'typesense.key.profile_saved';

    /**
     * @var string A scoped-key profile was deleted from project config.
     */
    public const EVENT_KEY_PROFILE_DELETED = 'typesense.key.profile_deleted';

    /**
     * @var string An AI provider (with credential references) was saved.
     */
    public const EVENT_AI_PROVIDER_SAVED = 'typesense.ai_provider.saved';

    /**
     * @var string An AI provider was deleted.
     */
    public const EVENT_AI_PROVIDER_DELETED = 'typesense.ai_provider.deleted';

    /**
     * @var string A conversation model was saved to project config.
     */
    public const EVENT_AI_MODEL_SAVED = 'typesense.ai_model.saved';

    /**
     * @var string A conversation model was deleted from project config.
     */
    public const EVENT_AI_MODEL_DELETED = 'typesense.ai_model.deleted';

    /**
     * @var string A conversation model was pushed to the Typesense server.
     */
    public const EVENT_AI_MODEL_PUSHED = 'typesense.ai_model.pushed';

    /**
     * @var string A control-panel collection definition was saved.
     */
    public const EVENT_COLLECTION_SAVED = 'typesense.collection.saved';

    /**
     * @var string A control-panel collection definition was deleted.
     */
    public const EVENT_COLLECTION_DELETED = 'typesense.collection.deleted';

    /**
     * @var string A zero-downtime rebuild was queued for a collection.
     */
    public const EVENT_COLLECTION_REBUILT = 'typesense.collection.rebuilt';

    /**
     * @var string A collection's field-layout mapping was saved.
     */
    public const EVENT_MAPPING_SAVED = 'typesense.mapping.saved';

    /**
     * @var string A schema apply was queued for the registered collections.
     */
    public const EVENT_SCHEMA_APPLIED = 'typesense.schema.applied';

    /**
     * @var string Legacy index config was migrated into the new schema.
     */
    public const EVENT_CONFIG_APPLIED = 'typesense.config.applied';

    /**
     * @var string An index flush (delete and re-sync) was triggered.
     */
    public const EVENT_INDEX_FLUSHED = 'typesense.index.flushed';

    /**
     * @var string A full index sync was triggered.
     */
    public const EVENT_INDEX_SYNCED = 'typesense.index.synced';

    /**
     * @var string Sync was suspended, globally or for one collection.
     */
    public const EVENT_INDEX_SUSPENDED = 'typesense.index.suspended';

    /**
     * @var string Sync was resumed, globally or for one collection.
     */
    public const EVENT_INDEX_RESUMED = 'typesense.index.resumed';

    /**
     * @var string An alias was cloned (the deploy swap).
     */
    public const EVENT_ALIAS_CLONED = 'typesense.alias.cloned';

    /**
     * @var string A curation rule was saved for a collection.
     */
    public const EVENT_CURATION_SAVED = 'typesense.curation.saved';

    /**
     * @var string A curation rule was deleted from a collection.
     */
    public const EVENT_CURATION_DELETED = 'typesense.curation.deleted';

    /**
     * @var string A synonym was saved for a collection.
     */
    public const EVENT_SYNONYMS_SAVED = 'typesense.synonyms.saved';

    /**
     * @var string A synonym was deleted from a collection.
     */
    public const EVENT_SYNONYMS_DELETED = 'typesense.synonyms.deleted';

    /**
     * @var string A dictionary (stopwords or stemming) was saved.
     */
    public const EVENT_DICTIONARY_SAVED = 'typesense.dictionary.saved';

    /**
     * @var string A dictionary's stopwords were deleted.
     */
    public const EVENT_DICTIONARY_DELETED = 'typesense.dictionary.deleted';

    /**
     * @var string A collection's relevance or vector settings were saved.
     */
    public const EVENT_RELEVANCE_SAVED = 'typesense.relevance.saved';

    /**
     * @var string A server snapshot was started.
     */
    public const EVENT_SNAPSHOT_CREATED = 'typesense.snapshot.created';

    /**
     * @var string The server's query cache was cleared.
     */
    public const EVENT_CACHE_CLEARED = 'typesense.cache.cleared';

    /**
     * @var string The plugin settings were saved.
     */
    public const EVENT_SETTINGS_SAVED = 'typesense.settings.saved';

    // Static Properties
    // =========================================================================

    /**
     * @var array<string, array{label: string, keys: string[]}>|null The event-type
     * definition map, keyed by event name: the human-readable label and the
     * scalar detail keys a recorder may persist. Built lazily by [[_definitions()]].
     */
    private static ?array $_definitions = null;

    // Public Methods
    // =========================================================================

    /**
     * Contributes Typesense's {@see AuditEventType} definitions to the kit's
     * event-type registry. Wired to
     * {@see \craftpulse\auditkit\services\EventTypes::EVENT_REGISTER_AUDIT_EVENTS}.
     *
     * @param RegisterAuditEventsEvent $event
     * @return void
     * @author CraftPulse
     */
    public function registerEventTypes(RegisterAuditEventsEvent $event): void
    {
        foreach (self::_definitions() as $name => $definition) {
            $event->eventTypes[] = new AuditEventType(
                name: $name,
                category: self::CATEGORY_SYSTEM,
                label: $definition['label'],
                allowedDetailKeys: $definition['keys'],
            );
        }
    }

    /**
     * Records one Typesense audit event on the kit bus. The event lands in the
     * `system` category under the `typesense` emitter, stamped with the acting
     * user (null on console and self/system requests). A no-op when Audit Kit is
     * not installed or no recorder has registered a sink.
     *
     * Callers pass only the scalar detail keys the event's allowlist permits;
     * key and credential values must never be passed.
     *
     * @param string $name one of the `EVENT_*` constants
     * @param array<string, scalar|null> $details scalar-only, non-PII, no secrets
     * @param string $outcome one of {@see AuditEvent}'s `OUTCOME_*` constants
     * @return void
     * @author CraftPulse
     */
    public function record(
        string $name,
        array $details = [],
        string $outcome = AuditEvent::OUTCOME_SUCCESS,
    ): void {
        $bus = $this->_bus();

        if ($bus === null) {
            return;
        }

        $bus->record(new AuditEvent(
            name: $name,
            category: self::CATEGORY_SYSTEM,
            emitter: self::EMITTER,
            outcome: $outcome,
            actorId: $this->resolveActorId(),
            details: $details,
        ));
    }

    /**
     * Resolves the acting user's id, or null for a console, queue, or otherwise
     * unauthenticated (system) request. Console and queue requests never carry a
     * web identity, so their emissions are correctly attributed to the system.
     *
     * @return int|null
     * @author CraftPulse
     */
    public function resolveActorId(): ?int
    {
        if (Craft::$app->getRequest()->getIsConsoleRequest()) {
            return null;
        }

        return Craft::$app->getUser()->getIdentity()?->id;
    }

    // Private Methods
    // =========================================================================

    /**
     * Resolves the Audit Kit dispatch bus, or null when Audit Kit is not
     * installed as a Craft plugin. Resolving through the plugins service (rather
     * than the `AuditKit::$plugin` static) keeps the emitter safe in projects
     * where the Composer package is present but the plugin is not installed.
     *
     * @return Bus|null
     * @author CraftPulse
     */
    private function _bus(): ?Bus
    {
        $plugin = Craft::$app->getPlugins()->getPlugin('audit-kit');

        return $plugin instanceof AuditKit ? $plugin->getBus() : null;
    }

    /**
     * The event-type definition map: each event name to its label and the scalar
     * detail keys a recorder may persist. No key holds a secret: only ids,
     * handles, scope descriptors, and coarse scalars appear.
     *
     * @return array<string, array{label: string, keys: string[]}>
     * @author CraftPulse
     */
    private static function _definitions(): array
    {
        if (self::$_definitions !== null) {
            return self::$_definitions;
        }

        return self::$_definitions = [
            self::EVENT_KEY_CREATED => ['label' => 'API key created', 'keys' => ['keyId', 'label', 'actions', 'collections']],
            self::EVENT_KEY_DELETED => ['label' => 'API key deleted', 'keys' => ['keyId']],
            self::EVENT_KEY_SCOPED_GENERATED => ['label' => 'Scoped search key generated', 'keys' => ['parameterCount']],
            self::EVENT_KEY_PROFILE_SAVED => ['label' => 'Scoped-key profile saved', 'keys' => ['handle']],
            self::EVENT_KEY_PROFILE_DELETED => ['label' => 'Scoped-key profile deleted', 'keys' => ['handle']],
            self::EVENT_AI_PROVIDER_SAVED => ['label' => 'AI provider saved', 'keys' => ['handle', 'type', 'kind']],
            self::EVENT_AI_PROVIDER_DELETED => ['label' => 'AI provider deleted', 'keys' => ['handle']],
            self::EVENT_AI_MODEL_SAVED => ['label' => 'Conversation model saved', 'keys' => ['handle', 'providerHandle', 'modelName']],
            self::EVENT_AI_MODEL_DELETED => ['label' => 'Conversation model deleted', 'keys' => ['handle']],
            self::EVENT_AI_MODEL_PUSHED => ['label' => 'Conversation model pushed', 'keys' => ['handle', 'providerHandle', 'modelName']],
            self::EVENT_COLLECTION_SAVED => ['label' => 'Collection saved', 'keys' => ['name', 'uid']],
            self::EVENT_COLLECTION_DELETED => ['label' => 'Collection deleted', 'keys' => ['name', 'uid']],
            self::EVENT_COLLECTION_REBUILT => ['label' => 'Collection rebuild queued', 'keys' => ['collection', 'queued']],
            self::EVENT_MAPPING_SAVED => ['label' => 'Mapping saved', 'keys' => ['uid']],
            self::EVENT_SCHEMA_APPLIED => ['label' => 'Schema applied', 'keys' => ['queued']],
            self::EVENT_CONFIG_APPLIED => ['label' => 'Legacy config migrated', 'keys' => ['collections']],
            self::EVENT_INDEX_FLUSHED => ['label' => 'Index flushed', 'keys' => ['scope']],
            self::EVENT_INDEX_SYNCED => ['label' => 'Index synced', 'keys' => ['scope']],
            self::EVENT_INDEX_SUSPENDED => ['label' => 'Sync suspended', 'keys' => ['collection']],
            self::EVENT_INDEX_RESUMED => ['label' => 'Sync resumed', 'keys' => ['collection']],
            self::EVENT_ALIAS_CLONED => ['label' => 'Alias cloned', 'keys' => ['source', 'target']],
            self::EVENT_CURATION_SAVED => ['label' => 'Curation saved', 'keys' => ['collection']],
            self::EVENT_CURATION_DELETED => ['label' => 'Curation deleted', 'keys' => ['collection']],
            self::EVENT_SYNONYMS_SAVED => ['label' => 'Synonym saved', 'keys' => ['collection']],
            self::EVENT_SYNONYMS_DELETED => ['label' => 'Synonym deleted', 'keys' => ['collection']],
            self::EVENT_DICTIONARY_SAVED => ['label' => 'Dictionary saved', 'keys' => ['collection']],
            self::EVENT_DICTIONARY_DELETED => ['label' => 'Dictionary deleted', 'keys' => ['collection']],
            self::EVENT_RELEVANCE_SAVED => ['label' => 'Relevance saved', 'keys' => ['uid']],
            self::EVENT_SNAPSHOT_CREATED => ['label' => 'Snapshot created', 'keys' => []],
            self::EVENT_CACHE_CLEARED => ['label' => 'Cache cleared', 'keys' => []],
            self::EVENT_SETTINGS_SAVED => ['label' => 'Settings saved', 'keys' => []],
        ];
    }
}
