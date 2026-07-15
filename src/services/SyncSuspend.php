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
use craft\db\Query;
use craft\helpers\Db;
use craftpulse\typesense\db\Table;

/**
 * The runtime sync-suspend state, held in the database (not project config) so
 * it toggles freely even when allowAdminChanges is disabled. Suspend is an
 * operational state, not a configuration choice, and must never churn project
 * config across environments.
 *
 * A row keyed by the global sentinel suspends all sync; a row keyed by a
 * collection name suspends only that collection. The sync engine treats an
 * element as suspended when the global switch is on OR its collection is
 * individually suspended.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class SyncSuspend extends Component
{
    // Constants
    // =========================================================================

    /**
     * @var string The reserved collection key for the global suspend switch.
     */
    public const GLOBAL_KEY = '*';

    // Public Methods
    // =========================================================================

    /**
     * Whether a collection is individually suspended (ignoring the global
     * switch).
     *
     * @param string $collection
     * @return bool
     * @author CraftPulse
     */
    public function isCollectionSuspended(string $collection): bool
    {
        return in_array($collection, $this->suspendedCollections(), true);
    }

    /**
     * Whether all sync is globally suspended.
     *
     * @return bool
     * @author CraftPulse
     */
    public function isGloballySuspended(): bool
    {
        return $this->_has(self::GLOBAL_KEY);
    }

    /**
     * Whether sync is suspended for a collection: the global switch is on, or the
     * collection is individually suspended. With no collection, reports the
     * global switch only.
     *
     * @param string|null $collection
     * @return bool
     * @author CraftPulse
     */
    public function isSuspended(?string $collection = null): bool
    {
        if ($this->isGloballySuspended()) {
            return true;
        }

        return $collection !== null && $this->isCollectionSuspended($collection);
    }

    /**
     * Resumes sync globally (no collection) or for one collection.
     *
     * @param string|null $collection
     * @return void
     * @author CraftPulse
     */
    public function resume(?string $collection = null): void
    {
        Db::delete(Table::SYNC_SUSPEND, ['collection' => $collection ?? self::GLOBAL_KEY]);
    }

    /**
     * Suspends sync globally (no collection) or for one collection. Idempotent.
     *
     * @param string|null $collection
     * @return void
     * @author CraftPulse
     */
    public function suspend(?string $collection = null): void
    {
        $key = $collection ?? self::GLOBAL_KEY;

        if ($this->_has($key)) {
            return;
        }

        Db::insert(Table::SYNC_SUSPEND, ['collection' => $key]);
    }

    /**
     * The individually suspended collection names (excluding the global switch).
     *
     * @return array<int, string>
     * @author CraftPulse
     */
    public function suspendedCollections(): array
    {
        return (new Query())
            ->select(['collection'])
            ->from(Table::SYNC_SUSPEND)
            ->where(['not', ['collection' => self::GLOBAL_KEY]])
            ->column();
    }

    /**
     * Flips the suspend state globally (no collection) or for one collection, and
     * returns the new state (true = now suspended).
     *
     * @param string|null $collection
     * @return bool
     * @author CraftPulse
     */
    public function toggle(?string $collection = null): bool
    {
        $key = $collection ?? self::GLOBAL_KEY;

        if ($this->_has($key)) {
            $this->resume($collection);

            return false;
        }

        $this->suspend($collection);

        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * Whether a suspend row exists for a key.
     *
     * @param string $key
     * @return bool
     * @author CraftPulse
     */
    private function _has(string $key): bool
    {
        return (new Query())
            ->from(Table::SYNC_SUSPEND)
            ->where(['collection' => $key])
            ->exists();
    }
}
