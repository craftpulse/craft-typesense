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

use Carbon\Carbon;
use Craft;
use craft\base\Component;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\elements\db\ElementQueryInterface;
use craft\events\ElementEvent;
use craft\events\MoveElementEvent;
use craft\helpers\Db;
use craft\helpers\ElementHelper;
use craft\helpers\StringHelper;
use craft\services\Elements;
use craft\services\Structures;
use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\db\Table;
use craftpulse\typesense\jobs\DeleteDocuments;
use craftpulse\typesense\jobs\IndexElements;
use craftpulse\typesense\jobs\ReconcileCollection;
use craftpulse\typesense\jobs\SyncCollection;
use craftpulse\typesense\models\Settings;
use craftpulse\typesense\Typesense;
use Throwable;
use Typesense\Exceptions\ObjectNotFound;
use yii\base\Application;
use yii\base\Event;

/**
 * The redone sync engine.
 *
 * Listens for element save/delete/move/restore, queues incremental upserts and
 * deletes batched by collection and site and debounced to the end of the
 * request, orchestrates memory-bounded full syncs, records and re-indexes
 * relation dependencies, reconciles indexed documents against the current query,
 * and honours the global suspend switch and queue-priority setting. All element
 * work runs through the registry's collections and the Documents transformer.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class Sync extends Component
{
    // Constants
    // =========================================================================

    /**
     * @var string The import action for full-document sync.
     */
    public const IMPORT_ACTION = 'upsert';

    // Private Properties
    // =========================================================================

    /**
     * @var array<string, array{handle: string, siteId: int, ids: array<int, int>}> Pending index batches.
     */
    private array $_pendingIndex = [];

    /**
     * @var array<string, array{handle: string, siteId: int, ids: array<int, int>}> Pending delete batches.
     */
    private array $_pendingDelete = [];

    /**
     * @var bool Whether the end-of-request flush has been registered.
     */
    private bool $_flushRegistered = false;

    // Public Methods: gating
    // =========================================================================

    /**
     * Whether sync should run: the client is configured and sync is not suspended.
     *
     * @return bool
     * @author CraftPulse
     */
    public function isEnabled(): bool
    {
        return Typesense::$plugin->getClient()->isConfigured() && !$this->isSuspended();
    }

    /**
     * Whether sync should run for a specific collection: sync is enabled globally
     * and the collection is not individually suspended. The batch and respawn jobs
     * gate on this (not just [[isEnabled()]]) so a per-collection suspend flipped
     * mid-chain is honored on the next spawn, matching the element-event path.
     *
     * @param string $collection The logical collection name.
     * @return bool
     * @author CraftPulse
     */
    public function isCollectionSyncable(string $collection): bool
    {
        return $this->isEnabled() && !Typesense::$plugin->getSyncSuspend()->isCollectionSuspended($collection);
    }

    /**
     * Whether sync is globally suspended.
     *
     * @return bool
     * @author CraftPulse
     */
    public function isSuspended(): bool
    {
        return Typesense::$plugin->getSyncSuspend()->isGloballySuspended();
    }

    /**
     * The queue priority applied to all sync jobs.
     *
     * @return int
     * @author CraftPulse
     */
    public function getQueuePriority(): int
    {
        return $this->_settings()->queuePriority;
    }

    // Public Methods: event handling
    // =========================================================================

    /**
     * Registers the element lifecycle listeners.
     *
     * @return void
     * @author CraftPulse
     */
    public function registerEventListeners(): void
    {
        Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, function(ElementEvent $event): void {
            $this->handleSave($event->element);
        });

        Event::on(Elements::class, Elements::EVENT_AFTER_RESTORE_ELEMENT, function(ElementEvent $event): void {
            $this->handleSave($event->element);
        });

        Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, function(ElementEvent $event): void {
            $this->handleDelete($event->element);
        });

        Event::on(Structures::class, Structures::EVENT_AFTER_MOVE_ELEMENT, function(MoveElementEvent $event): void {
            $this->handleSave($event->element);
        });
    }

    /**
     * Handles an element save/restore/move: index members, delete non-members,
     * and re-index dependents.
     *
     * @param ElementInterface $element
     * @return void
     * @author CraftPulse
     */
    public function handleSave(ElementInterface $element): void
    {
        if (!$this->isEnabled() || ElementHelper::isDraftOrRevision($element)) {
            return;
        }

        $siteId = (int)$element->siteId;
        $suspend = Typesense::$plugin->getSyncSuspend();

        foreach (Typesense::$plugin->getCollectionRegistry()->getAll() as $collection) {
            $type = $collection->getElementType();

            if (!$element instanceof $type) {
                continue;
            }

            if ($suspend->isCollectionSuspended($collection->getName())) {
                continue;
            }

            if ($this->matchesCollection($collection, $element)) {
                $this->_queueIndex($collection, $siteId, (int)$element->id);
            } else {
                $this->_queueDelete($collection, $siteId, (int)$element->id);
            }
        }

        $this->queueDependents($element);
    }

    /**
     * Handles an element delete: remove its documents from matching collections
     * and re-index dependents.
     *
     * @param ElementInterface $element
     * @return void
     * @author CraftPulse
     */
    public function handleDelete(ElementInterface $element): void
    {
        if (!$this->isEnabled() || ElementHelper::isDraftOrRevision($element)) {
            return;
        }

        $siteId = (int)$element->siteId;
        $suspend = Typesense::$plugin->getSyncSuspend();

        foreach (Typesense::$plugin->getCollectionRegistry()->getAll() as $collection) {
            $type = $collection->getElementType();

            if (!$element instanceof $type) {
                continue;
            }

            if ($suspend->isCollectionSuspended($collection->getName())) {
                continue;
            }

            $this->_queueDelete($collection, $siteId, (int)$element->id);
        }

        $this->queueDependents($element);
    }

    /**
     * Whether an element is a member of a collection's element query.
     *
     * @param Collection $collection
     * @param ElementInterface $element
     * @return bool
     * @author CraftPulse
     */
    public function matchesCollection(Collection $collection, ElementInterface $element): bool
    {
        foreach ($this->buildQueriesForSite($collection, (int)$element->siteId) as $query) {
            $query->id($element->id);

            if ($query->exists()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Flushes the pending index/delete batches into queue jobs. Registered to run
     * at the end of the request; also callable directly (tests, console).
     *
     * @return void
     * @author CraftPulse
     */
    public function flushPending(): void
    {
        $queue = Craft::$app->getQueue();

        foreach ($this->_pendingIndex as $batch) {
            $queue->priority($this->getQueuePriority())->push(new IndexElements([
                'collectionHandle' => $batch['handle'],
                'siteId' => $batch['siteId'],
                'elementIds' => array_values($batch['ids']),
            ]));
        }

        foreach ($this->_pendingDelete as $batch) {
            $queue->priority($this->getQueuePriority())->push(new DeleteDocuments([
                'collectionHandle' => $batch['handle'],
                'siteId' => $batch['siteId'],
                'elementIds' => array_values($batch['ids']),
            ]));
        }

        $this->_pendingIndex = [];
        $this->_pendingDelete = [];
    }

    // Public Methods: full sync + reconcile entry points
    // =========================================================================

    /**
     * Queues a full sync of every registered collection.
     *
     * @return void
     * @author CraftPulse
     */
    public function syncAll(): void
    {
        foreach (Typesense::$plugin->getCollectionRegistry()->getAll() as $collection) {
            $this->syncCollection($collection);
        }
    }

    /**
     * Queues a full sync of one collection, one site per job.
     *
     * @param Collection|string $collection
     * @param int|null $siteId
     * @return void
     * @author CraftPulse
     */
    public function syncCollection(Collection|string $collection, ?int $siteId = null): void
    {
        $collection = $this->_resolve($collection);

        if ($collection === null) {
            return;
        }

        $queue = Craft::$app->getQueue();

        foreach ($this->_siteIds($siteId) as $id) {
            $queue->priority($this->getQueuePriority())->push(new SyncCollection([
                'collectionHandle' => $collection->getName(),
                'siteId' => $id,
            ]));
        }
    }

    /**
     * Flushes every registered collection (delete and re-sync).
     *
     * @return void
     * @author CraftPulse
     */
    public function flushAll(): void
    {
        foreach (Typesense::$plugin->getCollectionRegistry()->getAll() as $collection) {
            $this->flush($collection);
        }
    }

    /**
     * Flushes one collection: deletes its target collection(s) then re-syncs,
     * rebuilding the schema and documents from scratch.
     *
     * @param Collection|string $collection
     * @param int|null $siteId
     * @return void
     * @author CraftPulse
     */
    public function flush(Collection|string $collection, ?int $siteId = null): void
    {
        $collection = $this->_resolve($collection);

        if ($collection === null) {
            return;
        }

        $client = Typesense::$plugin->getClient()->client();
        $registry = Typesense::$plugin->getCollectionRegistry();

        if ($client !== null) {
            foreach ($this->_siteIds($siteId) as $id) {
                try {
                    $client->collections[$registry->resolveName($collection, $id)]->delete();
                } catch (Throwable) {
                    // nothing to delete
                }
            }
        }

        $this->syncCollection($collection, $siteId);
    }

    /**
     * Queues reconciliation of one collection, one site per job.
     *
     * @param Collection|string $collection
     * @param int|null $siteId
     * @return void
     * @author CraftPulse
     */
    public function reconcile(Collection|string $collection, ?int $siteId = null): void
    {
        $collection = $this->_resolve($collection);

        if ($collection === null) {
            return;
        }

        $queue = Craft::$app->getQueue();

        foreach ($this->_siteIds($siteId) as $id) {
            $queue->priority($this->getQueuePriority())->push(new ReconcileCollection([
                'collectionHandle' => $collection->getName(),
                'siteId' => $id,
            ]));
        }
    }

    // Public Methods: job workers
    // =========================================================================

    /**
     * Resolves a collection by logical name.
     *
     * @param string $handle
     * @return Collection|null
     * @author CraftPulse
     */
    public function getCollection(string $handle): ?Collection
    {
        return Typesense::$plugin->getCollectionRegistry()->get($handle);
    }

    /**
     * Ensures the target Typesense collection exists, creating it if missing.
     *
     * @param Collection $collection
     * @param int $siteId
     * @return bool
     * @author CraftPulse
     */
    public function ensureCollectionExists(Collection $collection, int $siteId): bool
    {
        $client = Typesense::$plugin->getClient()->client();

        if ($client === null) {
            return false;
        }

        $registry = Typesense::$plugin->getCollectionRegistry();
        $target = $registry->resolveName($collection, $siteId);

        try {
            $client->collections[$target]->retrieve();

            return true;
        } catch (ObjectNotFound) {
            $client->collections->create($registry->getCreateSchema($collection, $siteId));
            $this->_seedManagedResources($collection);

            return true;
        } catch (Throwable $e) {
            Craft::error("Could not ensure collection '{$target}': {$e->getMessage()}", 'typesense');

            return false;
        }
    }

    /**
     * The number of elements a full sync will process for a collection and site.
     *
     * @param Collection $collection
     * @param int $siteId
     * @return int
     * @author CraftPulse
     */
    public function countForSync(Collection $collection, int $siteId): int
    {
        return count($this->_allElementIds($collection, $siteId));
    }

    /**
     * Processes one memory-bounded slice of a full sync. Returns the number of
     * elements processed (0 when the slice is past the end).
     *
     * @param Collection $collection
     * @param int $siteId
     * @param int $offset
     * @param int $limit
     * @return int
     * @author CraftPulse
     */
    public function syncSlice(Collection $collection, int $siteId, int $offset, int $limit): int
    {
        $ids = array_slice($this->_allElementIds($collection, $siteId), $offset, $limit);

        if ($ids === []) {
            return 0;
        }

        $this->indexElements($collection, $siteId, $ids);
        $this->_updateState($collection, $siteId, ['cursor' => $offset + count($ids)]);
        gc_collect_cycles();

        return count($ids);
    }

    /**
     * Finalizes a completed full sync: records state and queues reconciliation.
     *
     * @param Collection $collection
     * @param int $siteId
     * @return void
     * @author CraftPulse
     */
    public function finalizeSync(Collection $collection, int $siteId): void
    {
        $this->_updateState($collection, $siteId, [
            'cursor' => null,
            'lastSyncedAt' => Db::prepareDateForDb(Carbon::now()),
        ]);

        Craft::$app->getQueue()->priority($this->getQueuePriority())->push(new ReconcileCollection([
            'collectionHandle' => $collection->getName(),
            'siteId' => $siteId,
        ]));
    }

    /**
     * Indexes specific elements: upserts indexable elements, deletes the rest,
     * and records their relation dependencies.
     *
     * @param Collection $collection
     * @param int $siteId
     * @param array<int, int> $elementIds
     * @return void
     * @author CraftPulse
     */
    public function indexElements(Collection $collection, int $siteId, array $elementIds): void
    {
        $client = Typesense::$plugin->getClient()->client();

        if ($client === null || $elementIds === []) {
            return;
        }

        $documents = Typesense::$plugin->getDocuments();
        $type = $collection->getElementType();
        $elements = $type::find()->id($elementIds)->siteId($siteId)->status(null)->indexBy('id')->all();

        $upserts = [];
        $deletes = [];

        foreach ($elementIds as $id) {
            $element = $elements[$id] ?? null;

            if ($element === null) {
                $deletes[] = $id;
                continue;
            }

            $result = $documents->build($collection, $element, $siteId);

            if ($result->isEmpty()) {
                $deletes[] = $id;
                $this->_clearDependencies($collection->getName(), $siteId, $id);
                continue;
            }

            array_push($upserts, ...$result->documents);
            $this->recordDependencies($collection->getName(), $siteId, $id, $result->dependencies);
        }

        if ($upserts !== []) {
            $this->_import($collection, $siteId, $upserts);
        }

        if ($deletes !== []) {
            $this->deleteDocuments($collection, $siteId, $deletes);
        }
    }

    /**
     * Deletes the documents for specific elements from a collection and site.
     *
     * @param Collection $collection
     * @param int $siteId
     * @param array<int, int> $elementIds
     * @return void
     * @author CraftPulse
     */
    public function deleteDocuments(Collection $collection, int $siteId, array $elementIds): void
    {
        $client = Typesense::$plugin->getClient()->client();

        if ($client === null || $elementIds === []) {
            return;
        }

        $target = Typesense::$plugin->getCollectionRegistry()->resolveName($collection, $siteId);

        foreach ($elementIds as $id) {
            try {
                $client->collections[$target]->documents->delete([
                    'filter_by' => Documents::FIELD_ELEMENT_ID . ':=' . (int)$id,
                ]);
            } catch (Throwable $e) {
                Craft::error("Could not delete documents for element {$id} in '{$target}': {$e->getMessage()}", 'typesense');
            }

            $this->_clearDependencies($collection->getName(), $siteId, (int)$id);
        }
    }

    /**
     * Reconciles a collection and site: deletes documents whose source element is
     * no longer an active member of the collection's query.
     *
     * @param Collection $collection
     * @param int $siteId
     * @return void
     * @author CraftPulse
     */
    public function reconcileCollection(Collection $collection, int $siteId): void
    {
        $client = Typesense::$plugin->getClient()->client();

        if ($client === null) {
            return;
        }

        $target = Typesense::$plugin->getCollectionRegistry()->resolveName($collection, $siteId);
        $validIds = array_flip($this->_activeElementIds($collection, $siteId));

        try {
            $export = $client->collections[$target]->documents->export([
                'include_fields' => 'id,' . Documents::FIELD_ELEMENT_ID . ',' . Documents::FIELD_SITE_ID,
            ]);
        } catch (Throwable $e) {
            Craft::error("Could not export '{$target}' for reconciliation: {$e->getMessage()}", 'typesense');

            return;
        }

        foreach (array_filter(explode("\n", (string)$export)) as $line) {
            $doc = json_decode($line, true);

            if (!is_array($doc) || !isset($doc['id'], $doc[Documents::FIELD_ELEMENT_ID])) {
                continue;
            }

            // Only reconcile documents belonging to this site (shared collections
            // carry documents from every site).
            if (isset($doc[Documents::FIELD_SITE_ID]) && (int)$doc[Documents::FIELD_SITE_ID] !== $siteId) {
                continue;
            }

            if (!isset($validIds[(int)$doc[Documents::FIELD_ELEMENT_ID]])) {
                try {
                    $client->collections[$target]->documents[(string)$doc['id']]->delete();
                } catch (Throwable $e) {
                    Craft::error("Could not delete orphan document {$doc['id']} from '{$target}': {$e->getMessage()}", 'typesense');
                }
            }
        }
    }

    // Public Methods: dependencies
    // =========================================================================

    /**
     * Records a source element's relation dependencies, replacing any prior rows.
     *
     * @param string $handle
     * @param int $siteId
     * @param int $sourceId
     * @param array<int, int> $dependencyIds
     * @return void
     * @author CraftPulse
     */
    public function recordDependencies(string $handle, int $siteId, int $sourceId, array $dependencyIds): void
    {
        $this->_clearDependencies($handle, $siteId, $sourceId);

        $dependencyIds = array_values(array_unique(array_filter($dependencyIds)));

        if ($dependencyIds === []) {
            return;
        }

        $now = Db::prepareDateForDb(Carbon::now());
        $rows = array_map(static fn(int $depId): array => [
            $handle, $siteId, $sourceId, $depId, $now, $now, StringHelper::UUID(),
        ], $dependencyIds);

        Db::batchInsert(Table::SYNC_DEPENDENCIES, [
            'collectionHandle', 'siteId', 'sourceElementId', 'dependencyElementId', 'dateCreated', 'dateUpdated', 'uid',
        ], $rows);
    }

    /**
     * Queues re-index of every source element that depends on the given element.
     *
     * @param ElementInterface $element
     * @return void
     * @author CraftPulse
     */
    public function queueDependents(ElementInterface $element): void
    {
        $rows = (new Query())
            ->select(['collectionHandle', 'siteId', 'sourceElementId'])
            ->from(Table::SYNC_DEPENDENCIES)
            ->where(['dependencyElementId' => (int)$element->id])
            ->all();

        foreach ($rows as $row) {
            $collection = $this->getCollection($row['collectionHandle']);

            if ($collection !== null) {
                $this->_queueIndex($collection, (int)$row['siteId'], (int)$row['sourceElementId']);
            }
        }
    }

    // Public Methods: query helpers
    // =========================================================================

    /**
     * Builds the element queries for a collection scoped to a site.
     *
     * @param Collection $collection
     * @param int $siteId
     * @return array<int, ElementQueryInterface>
     * @author CraftPulse
     */
    public function buildQueriesForSite(Collection $collection, int $siteId): array
    {
        $type = $collection->getElementType();
        $queries = [];

        foreach ($collection->getElementQueries() as $callable) {
            $base = $type::find()->siteId($siteId)->status(null);
            $query = $callable($base);

            if ($query instanceof ElementQueryInterface) {
                $queries[] = $query;
            }
        }

        if ($queries === []) {
            $queries[] = $type::find()->siteId($siteId)->status(null);
        }

        // Force a deterministic id-ascending order. The full sync walks these
        // queries in offset-based slices (countForSync / syncSlice) and respawns
        // a continuation from a cursor offset, so without a stable order a
        // re-fetched slice could skip or duplicate elements across slices and
        // respawns. Membership (id-exists) and reconcile (set comparison) ignore
        // order, so forcing it here is safe for every consumer, and the
        // collection's own query order is for display, not paging.
        foreach ($queries as $query) {
            $query->orderBy(['elements.id' => SORT_ASC]);
        }

        return $queries;
    }

    // Private Methods
    // =========================================================================

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

    /**
     * @param Collection|string $collection
     * @return Collection|null
     * @author CraftPulse
     */
    private function _resolve(Collection|string $collection): ?Collection
    {
        return $collection instanceof Collection ? $collection : $this->getCollection($collection);
    }

    /**
     * Seeds the config-declared, config-managed search resources for a freshly
     * created collection: synonyms, curation rules, the search preset, and
     * stopwords. CP-managed synonyms and curation are left untouched.
     *
     * @param Collection $collection
     * @return void
     * @author CraftPulse
     */
    private function _seedManagedResources(Collection $collection): void
    {
        Typesense::$plugin->getSynonyms()->seedFromConfig($collection);
        Typesense::$plugin->getCuration()->seedFromConfig($collection);
        Typesense::$plugin->getPresets()->seedFromConfig($collection);
        Typesense::$plugin->getDictionaries()->seedStopwords($collection);
    }

    /**
     * The site IDs to operate on (a single site or all sites).
     *
     * @param int|null $siteId
     * @return array<int, int>
     * @author CraftPulse
     */
    private function _siteIds(?int $siteId): array
    {
        return $siteId !== null ? [$siteId] : Craft::$app->getSites()->getAllSiteIds();
    }

    /**
     * All element IDs a collection's queries return for a site (any status).
     *
     * @param Collection $collection
     * @param int $siteId
     * @return array<int, int>
     * @author CraftPulse
     */
    private function _allElementIds(Collection $collection, int $siteId): array
    {
        $ids = [];

        foreach ($this->buildQueriesForSite($collection, $siteId) as $query) {
            $ids = array_merge($ids, $query->ids());
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * The element IDs that should currently be indexed (active members).
     *
     * @param Collection $collection
     * @param int $siteId
     * @return array<int, int>
     * @author CraftPulse
     */
    private function _activeElementIds(Collection $collection, int $siteId): array
    {
        $statuses = $collection->getActiveStatuses() ?? Documents::DEFAULT_ACTIVE_STATUSES;
        $ids = [];

        foreach ($this->buildQueriesForSite($collection, $siteId) as $query) {
            $ids = array_merge($ids, $query->status($statuses)->ids());
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Imports a batch of documents (upsert).
     *
     * @param Collection $collection
     * @param int $siteId
     * @param array<int, array<string, mixed>> $documents
     * @return void
     * @author CraftPulse
     */
    private function _import(Collection $collection, int $siteId, array $documents): void
    {
        $client = Typesense::$plugin->getClient()->client();

        if ($client === null) {
            return;
        }

        $target = Typesense::$plugin->getCollectionRegistry()->resolveName($collection, $siteId);

        try {
            $results = $client->collections[$target]->documents->import($documents, [
                'action' => self::IMPORT_ACTION,
                'dirty_values' => 'coerce_or_reject',
            ]);
        } catch (Throwable $e) {
            Craft::error("Could not import documents into '{$target}': {$e->getMessage()}", 'typesense');

            return;
        }

        if (!is_array($results)) {
            return;
        }

        foreach ($results as $result) {
            if (($result['success'] ?? true) === false) {
                Craft::error("Typesense import failure in '{$target}': " . ($result['error'] ?? 'unknown'), 'typesense');
            }
        }
    }

    /**
     * Queues an element for indexing, batched by collection and site.
     *
     * @param Collection $collection
     * @param int $siteId
     * @param int $elementId
     * @return void
     * @author CraftPulse
     */
    private function _queueIndex(Collection $collection, int $siteId, int $elementId): void
    {
        $key = $collection->getName() . ':' . $siteId;
        $this->_pendingIndex[$key] ??= ['handle' => $collection->getName(), 'siteId' => $siteId, 'ids' => []];
        $this->_pendingIndex[$key]['ids'][$elementId] = $elementId;
        $this->_registerFlush();
    }

    /**
     * Queues an element for deletion, batched by collection and site.
     *
     * @param Collection $collection
     * @param int $siteId
     * @param int $elementId
     * @return void
     * @author CraftPulse
     */
    private function _queueDelete(Collection $collection, int $siteId, int $elementId): void
    {
        $key = $collection->getName() . ':' . $siteId;
        $this->_pendingDelete[$key] ??= ['handle' => $collection->getName(), 'siteId' => $siteId, 'ids' => []];
        $this->_pendingDelete[$key]['ids'][$elementId] = $elementId;
        $this->_registerFlush();
    }

    /**
     * Registers the end-of-request flush once.
     *
     * @return void
     * @author CraftPulse
     */
    private function _registerFlush(): void
    {
        if ($this->_flushRegistered) {
            return;
        }

        Craft::$app->on(Application::EVENT_AFTER_REQUEST, function(): void {
            $this->flushPending();
        });
        $this->_flushRegistered = true;
    }

    /**
     * Clears a source element's dependency rows.
     *
     * @param string $handle
     * @param int $siteId
     * @param int $sourceId
     * @return void
     * @author CraftPulse
     */
    private function _clearDependencies(string $handle, int $siteId, int $sourceId): void
    {
        Db::delete(Table::SYNC_DEPENDENCIES, [
            'collectionHandle' => $handle,
            'siteId' => $siteId,
            'sourceElementId' => $sourceId,
        ]);
    }

    /**
     * Upserts the sync-state row for a collection and site.
     *
     * @param Collection $collection
     * @param int $siteId
     * @param array<string, mixed> $values
     * @return void
     * @author CraftPulse
     */
    private function _updateState(Collection $collection, int $siteId, array $values): void
    {
        $now = Db::prepareDateForDb(Carbon::now());
        $condition = ['collectionHandle' => $collection->getName(), 'siteId' => $siteId];
        $exists = (new Query())->from(Table::SYNC_STATE)->where($condition)->exists();

        if ($exists) {
            Db::update(Table::SYNC_STATE, array_merge($values, ['dateUpdated' => $now]), $condition);

            return;
        }

        Db::insert(Table::SYNC_STATE, array_merge($condition, $values, [
            'dateCreated' => $now,
            'dateUpdated' => $now,
            'uid' => StringHelper::UUID(),
        ]));
    }
}
