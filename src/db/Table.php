<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\db;

/**
 * Database table names.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
abstract class Table
{
    // Constants
    // =========================================================================

    /**
     * @var string Vestigial 5.8.x table (never read or written). Dropped by the P4 update migration.
     */
    public const COLLECTIONS = '{{%typesense_collections}}';

    /**
     * @var string The synonyms table.
     */
    public const SYNONYMS = '{{%typesense_synonyms}}';

    /**
     * @var string Per collection and site sync cursor, checksum, and counts.
     */
    public const SYNC_STATE = '{{%typesense_sync_state}}';

    /**
     * @var string Element relation dependencies for re-index on related-element change.
     */
    public const SYNC_DEPENDENCIES = '{{%typesense_sync_dependencies}}';

    /**
     * @var string Element-to-curation-rule lookup, so the entry sidebar never
     * scans every override to find an element's pins.
     */
    public const CURATION_INDEX = '{{%typesense_curation_index}}';

    /**
     * @var string Runtime sync-suspend state (one row per suspended collection,
     * plus a sentinel row for the global suspend). Kept out of project config so
     * it toggles even when allowAdminChanges is disabled.
     */
    public const SYNC_SUSPEND = '{{%typesense_sync_suspend}}';
}
