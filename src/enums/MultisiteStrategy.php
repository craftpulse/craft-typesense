<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\enums;

/**
 * How a collection maps onto Craft's sites.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
enum MultisiteStrategy: string
{
    // Cases
    // =========================================================================

    /**
     * One Typesense collection per Craft site (name is suffixed with the site handle).
     */
    case CollectionPerSite = 'collectionPerSite';

    /**
     * One shared collection carrying a site_id field that searches filter on.
     */
    case SharedWithSiteFilter = 'sharedWithSiteFilter';
}
