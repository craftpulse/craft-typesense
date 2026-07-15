<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\assetbundles\mapping;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * The asset bundle for the Pro field mapping component. Plain JavaScript and
 * CSS (no build step): the JS uses the ambient Garnish, Craft, and jQuery
 * globals the control panel already provides, so it depends on CpAsset (which
 * pulls in GarnishAsset).
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class MappingAsset extends AssetBundle
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';
        $this->depends = [CpAsset::class];
        $this->js = ['js/mapping.js'];
        $this->css = ['css/mapping.css'];

        parent::init();
    }
}
