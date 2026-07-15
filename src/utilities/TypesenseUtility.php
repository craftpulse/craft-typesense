<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\utilities;

use Craft;
use craft\base\Utility;
use craft\db\Query;
use craftpulse\typesense\controllers\OpsController;
use craftpulse\typesense\db\Table;
use craftpulse\typesense\services\Drift;
use craftpulse\typesense\Typesense;
use Throwable;

/**
 * Typesense control-panel utility: server status, collections overview, drift
 * report, and operational sync/flush/suspend controls. Read-only in Free (it
 * surfaces state and queues operational jobs; it never edits configuration).
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class TypesenseUtility extends Utility
{
    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('typesense', 'Typesense');
    }

    /**
     * @inheritdoc
     */
    public static function id(): string
    {
        return 'typesense';
    }

    /**
     * @inheritdoc
     */
    public static function icon(): ?string
    {
        // The absolute path to the plugin's brand icon, resolved from this file's
        // location rather than a plugin alias (the plugin's registered alias uses
        // the legacy namespace, so an alias lookup returned an unresolved path and
        // the sidebar showed an empty placeholder).
        return dirname(__DIR__) . '/icon.svg';
    }

    /**
     * @inheritdoc
     */
    public static function contentHtml(): string
    {
        $plugin = Typesense::$plugin;
        $user = Craft::$app->getUser();

        return Craft::$app->getView()->renderTemplate('typesense/_components/utilities/typesense', [
            'status' => $plugin->getClient()->getStatus(),
            'collections' => self::_collectionRows(),
            'suspended' => $plugin->getSyncSuspend()->isGloballySuspended(),
            'canManage' => $user->checkPermission(OpsController::PERMISSION_MANAGE_OPS),
            'ops' => self::_ops(),
            // Ops actions (sync, flush, suspend, and the Pro index-lifecycle ops)
            // are all gated by the dedicated ops permission.
            'canRunOps' => $plugin->getIsPro() && $user->checkPermission(OpsController::PERMISSION_MANAGE_OPS),
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Builds the ops readout from the server metrics (memory, disk), fail-soft.
     *
     * @return array<string, mixed>
     * @author CraftPulse
     */
    private static function _ops(): array
    {
        $metrics = Typesense::$plugin->getClient()->metrics();

        if ($metrics === []) {
            return [];
        }

        return [
            'memoryUsedBytes' => (int)($metrics['system_memory_used_bytes'] ?? 0),
            'memoryTotalBytes' => (int)($metrics['system_memory_total_bytes'] ?? 0),
            'diskUsedBytes' => (int)($metrics['system_disk_used_bytes'] ?? 0),
            'diskTotalBytes' => (int)($metrics['system_disk_total_bytes'] ?? 0),
            'cpuActivePercentage' => (string)($metrics['system_cpu_active_percentage'] ?? ''),
        ];
    }

    /**
     * Builds the collections overview rows: name, document count, and drift status.
     *
     * @return array<int, array<string, mixed>>
     * @author CraftPulse
     */
    private static function _collectionRows(): array
    {
        $plugin = Typesense::$plugin;
        $client = $plugin->getClient()->client();
        $findings = [];

        foreach ($plugin->getDrift()->diff() as $finding) {
            $findings[$finding['target']] = $finding['status'];
        }

        // The most recent sync timestamp per collection, from the sync-state
        // table (one query, keyed by collection handle across its sites).
        $lastSync = [];

        foreach ((new Query())->select(['collectionHandle', 'lastSyncedAt'])->from(Table::SYNC_STATE)->all() as $state) {
            $handle = (string)$state['collectionHandle'];
            $syncedAt = $state['lastSyncedAt'] ?? null;

            if ($syncedAt !== null && ($lastSync[$handle] ?? '') < $syncedAt) {
                $lastSync[$handle] = $syncedAt;
            }
        }

        $rows = [];

        foreach ($plugin->getCollectionRegistry()->getAll() as $collection) {
            $target = $plugin->getCollectionRegistry()->resolveName(
                $collection,
                Craft::$app->getSites()->getPrimarySite()->id,
            );
            $documents = null;

            if ($client !== null) {
                try {
                    $documents = (int)$client->collections[$target]->retrieve()['num_documents'];
                } catch (Throwable) {
                    $documents = null;
                }
            }

            $rows[] = [
                'name' => $collection->getName(),
                'target' => $target,
                'strategy' => $collection->getMultisiteStrategy()->value,
                'documents' => $documents,
                'drift' => $findings[$target] ?? Drift::STATUS_MISSING,
                'suspended' => $plugin->getSyncSuspend()->isCollectionSuspended($collection->getName()),
                'lastSync' => $lastSync[$collection->getName()] ?? null,
            ];
        }

        return $rows;
    }
}
