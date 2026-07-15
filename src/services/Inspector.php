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
use craft\base\ElementInterface;
use craft\db\Query;
use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\db\Table;
use craftpulse\typesense\Typesense;
use Throwable;

/**
 * The element indexing inspector: the single source of truth behind both the
 * `typesense/inspect` console command and the control-panel inspector sidebar.
 *
 * For one element it answers "why is, or is not, this element in search": which
 * collections it belongs to, whether it is in an active status, the document it
 * would produce (or the reason it produces none), the document currently live on
 * the server, and when the collection last synced. Global blockers (a suspended
 * or unconfigured sync) are reported once at the top.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class Inspector extends Component
{
    // Constants
    // =========================================================================

    /**
     * @var string The element is not a member of the collection's query.
     */
    public const STATE_NOT_MEMBER = 'notMember';

    /**
     * @var string The element matches but is not in an active status.
     */
    public const STATE_INACTIVE = 'inactive';

    /**
     * @var string The element matches and is active but produces no document.
     */
    public const STATE_NO_DOCUMENT = 'noDocument';

    /**
     * @var string The element is indexed (or would be on the next sync).
     */
    public const STATE_INDEXED = 'indexed';

    // Private Properties
    // =========================================================================

    /**
     * @var array<string, array<string, mixed>> Request-scoped inspection cache,
     * keyed by "{elementId}-{siteId}", so several callers in one request (both
     * sidebars, the console command) inspect an element only once.
     */
    private array $_inspections = [];

    // Public Methods
    // =========================================================================

    /**
     * Inspects an element's indexing state across every collection it could
     * belong to.
     *
     * @param ElementInterface $element
     * @param int|null $siteId
     * @return array<string, mixed>
     * @author CraftPulse
     */
    public function inspectElement(ElementInterface $element, ?int $siteId = null): array
    {
        $siteId ??= (int)$element->siteId;
        $cacheKey = $element->id . '-' . $siteId;

        if (isset($this->_inspections[$cacheKey])) {
            return $this->_inspections[$cacheKey];
        }

        $registry = Typesense::$plugin->getCollectionRegistry();

        $collections = [];

        foreach ($registry->getAll() as $collection) {
            $type = $collection->getElementType();

            if (!$element instanceof $type) {
                continue;
            }

            $collections[] = $this->_inspectCollection($collection, $element, $siteId);
        }

        return $this->_inspections[$cacheKey] = [
            'elementId' => (int)$element->id,
            'siteId' => $siteId,
            'status' => (string)$element->getStatus(),
            'configured' => Typesense::$plugin->getClient()->isConfigured(),
            'suspended' => Typesense::$plugin->getSync()->isSuspended(),
            'collections' => $collections,
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * Inspects one collection for an element.
     *
     * @param Collection $collection
     * @param ElementInterface $element
     * @param int $siteId
     * @return array<string, mixed>
     * @author CraftPulse
     */
    private function _inspectCollection(Collection $collection, ElementInterface $element, int $siteId): array
    {
        $sync = Typesense::$plugin->getSync();
        $documents = Typesense::$plugin->getDocuments();
        $name = $collection->getName();

        $base = [
            'name' => $name,
            'documents' => [],
            'liveDocument' => null,
            'lastSyncedAt' => $this->_lastSyncedAt($name, $siteId),
        ];

        if (!$sync->matchesCollection($collection, $element)) {
            return $base + [
                'state' => self::STATE_NOT_MEMBER,
                'reason' => Craft::t('typesense', 'Not a member of this collection’s query.'),
            ];
        }

        if (!$documents->isActive($collection, $element)) {
            return $base + [
                'state' => self::STATE_INACTIVE,
                'reason' => Craft::t('typesense', 'A member, but not in an active status (it would be removed).'),
            ];
        }

        $result = $documents->build($collection, $element, $siteId);

        if ($result->isEmpty()) {
            return $base + [
                'state' => self::STATE_NO_DOCUMENT,
                'reason' => Craft::t('typesense', 'Builds no document (for example an empty required value, a zero-coordinate geopoint, or a transform that returned nothing).'),
            ];
        }

        $base['documents'] = $result->documents;
        $base['liveDocument'] = $this->_liveDocument($collection, $siteId, (string)($result->documents[0]['id'] ?? ''));

        return $base + [
            'state' => self::STATE_INDEXED,
            'reason' => Craft::t('typesense', 'Indexed ({count} document(s)).', ['count' => count($result->documents)]),
        ];
    }

    /**
     * The collection's last sync time for a site, or null.
     *
     * @param string $collectionHandle
     * @param int $siteId
     * @return string|null
     * @author CraftPulse
     */
    private function _lastSyncedAt(string $collectionHandle, int $siteId): ?string
    {
        $value = (new Query())
            ->select(['lastSyncedAt'])
            ->from(Table::SYNC_STATE)
            ->where(['collectionHandle' => $collectionHandle, 'siteId' => $siteId])
            ->scalar();

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Fetches the document currently live on the server, or null (fail-soft).
     *
     * @param Collection $collection
     * @param int $siteId
     * @param string $documentId
     * @return array<string, mixed>|null
     * @author CraftPulse
     */
    private function _liveDocument(Collection $collection, int $siteId, string $documentId): ?array
    {
        if ($documentId === '') {
            return null;
        }

        $client = Typesense::$plugin->getClient()->client();

        if ($client === null) {
            return null;
        }

        $target = Typesense::$plugin->getCollectionRegistry()->resolveName($collection, $siteId);

        try {
            return $client->collections[$target]->documents[$documentId]->retrieve();
        } catch (Throwable) {
            return null;
        }
    }
}
