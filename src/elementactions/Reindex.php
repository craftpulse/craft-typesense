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
use craftpulse\typesense\Typesense;

/**
 * Bulk element-index action that re-indexes the selected elements in Typesense.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class Reindex extends ElementAction
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function getTriggerLabel(): string
    {
        return Craft::t('typesense', 'Reindex in Typesense');
    }

    /**
     * @inheritdoc
     */
    public function performAction(ElementQueryInterface $query): bool
    {
        $sync = Typesense::$plugin->getSync();

        foreach ($query->all() as $element) {
            $sync->handleSave($element);
        }

        $sync->flushPending();
        $this->setMessage(Craft::t('typesense', 'Reindex queued.'));

        return true;
    }
}
