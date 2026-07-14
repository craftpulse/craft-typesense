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
use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\Typesense;

/**
 * Search-preset service.
 *
 * Mirrors a collection's declared search preset (typo tolerance, prefix,
 * drop_tokens, and so on) into a Typesense preset. The search layer always
 * passes the preset by name. Presets are global in Typesense with a single API
 * shape across supported versions.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class Presets extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * The Typesense preset name for a collection.
     *
     * @param Collection $collection
     * @return string
     * @author CraftPulse
     */
    public function presetName(Collection $collection): string
    {
        return Typesense::$plugin->getCollectionRegistry()->resolveName(
            $collection,
            Craft::$app->getSites()->getPrimarySite()->id,
        ) . '_preset';
    }

    /**
     * Mirrors the collection's declared preset into Typesense.
     *
     * @param Collection $collection
     * @return void
     * @author CraftPulse
     */
    public function seedFromConfig(Collection $collection): void
    {
        $preset = $collection->getPreset();

        if ($preset === null) {
            return;
        }

        Typesense::$plugin->getClient()->request('PUT', '/presets/' . $this->presetName($collection), [
            'value' => $preset,
        ]);
    }
}
