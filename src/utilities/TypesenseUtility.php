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
use craftpulse\typesense\controllers\CollectionsController;
use craftpulse\typesense\controllers\SettingsController;
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
        return Craft::getAlias('@craftpulse/typesense/icon.svg');
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
            'canManage' => $user->checkPermission(SettingsController::PERMISSION_MANAGE_SETTINGS),
            'ops' => self::_ops(),
            // Ops actions are Pro and gated by the index-management permission;
            // reading metrics stays Free (visible to any utility viewer).
            'canRunOps' => $plugin->getIsPro() && $user->checkPermission(CollectionsController::PERMISSION_MANAGE_COLLECTIONS),
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
            ];
        }

        return $rows;
    }
}
