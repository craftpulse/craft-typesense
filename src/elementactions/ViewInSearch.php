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
use craft\helpers\UrlHelper;
use craftpulse\typesense\Typesense;

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

        // Pro: deep-link into the search playground for the element's collection,
        // prefiltered to the element. The playground reads the collection from
        // the route and the elementId filter from the query string.
        if (Typesense::$plugin->getIsPro() && count($ids) === 1) {
            $deepLink = $this->_playgroundUrl((int)$ids[0]);

            if ($deepLink !== null) {
                $this->setMessage(Craft::t('typesense', 'Open the search playground for this element: {url}', ['url' => $deepLink]));

                return true;
            }
        }

        // Free (and Pro fallback when no collection matches): guidance.
        $hint = count($ids) === 1
            ? Craft::t('typesense', 'Run `craft typesense/inspect {id}` to see this element in search, or open the Typesense utility.', ['id' => $ids[0] ?? 0])
            : Craft::t('typesense', 'Open the Typesense utility to inspect these elements in search.');

        $this->setMessage($hint);

        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * The playground deep link for an element: the first collection the element
     * belongs to, prefiltered to its id, or null when it matches none.
     *
     * @param int $elementId
     * @return string|null
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    private function _playgroundUrl(int $elementId): ?string
    {
        /** @phpstan-ignore-next-line the element type is not known ahead of time */
        $element = Craft::$app->getElements()->getElementById($elementId);

        if ($element === null) {
            return null;
        }

        $sync = Typesense::$plugin->getSync();

        foreach (Typesense::$plugin->getCollectionRegistry()->getAll() as $name => $collection) {
            if ($sync->matchesCollection($collection, $element)) {
                return UrlHelper::cpUrl('typesense/playground/' . $name, [
                    'filterBy' => 'elementId:=' . $elementId,
                ]);
            }
        }

        return null;
    }
}
