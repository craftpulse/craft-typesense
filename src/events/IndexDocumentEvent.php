<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\events;

use craft\base\ElementInterface;
use craftpulse\typesense\builders\Collection;
use yii\base\Event;

/**
 * Event carrying a document about to be (or just) indexed, for last-mile
 * mutation and observability.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class IndexDocumentEvent extends Event
{
    // Public Properties
    // =========================================================================

    /**
     * @var ElementInterface The source element.
     */
    public ElementInterface $element;

    /**
     * @var Collection The target collection.
     */
    public Collection $collection;

    /**
     * @var int The site the document is built for.
     */
    public int $siteId;

    /**
     * @var array<string, mixed> The document (mutable).
     */
    public array $document = [];
}
