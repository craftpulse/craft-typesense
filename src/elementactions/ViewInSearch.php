<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\elementactions;

use Craft;
use craft\base\ElementAction;
use craft\elements\db\ElementQueryInterface;

/**
 * Element-index action that surfaces where the selected element lives in search.
 *
 * In Free this degrades to guidance (the Typesense utility and the
 * `typesense/inspect` console command). When the Pro search playground exists
 * (P8b) this is the seam to deep-link into it; the upgrade replaces the message
 * with a redirect to the playground filtered to the element.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class ViewInSearch extends ElementAction
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function getTriggerLabel(): string
    {
        return Craft::t('typesense', 'View in Typesense search');
    }

    /**
     * @inheritdoc
     */
    public function performAction(ElementQueryInterface $query): bool
    {
        $ids = $query->ids();
        $hint = count($ids) === 1
            ? Craft::t('typesense', 'Run `craft typesense/inspect {id}` to see this element in search, or open the Typesense utility.', ['id' => $ids[0] ?? 0])
            : Craft::t('typesense', 'Open the Typesense utility to inspect these elements in search.');

        $this->setMessage($hint);

        return true;
    }
}
