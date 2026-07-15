<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\assetbundles\playground;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * The asset bundle for the Pro search playground and document browser. Plain
 * JavaScript and CSS (no build step): the JS uses the ambient Garnish, Craft,
 * and jQuery globals, so it depends on CpAsset.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class PlaygroundAsset extends AssetBundle
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
        $this->js = ['js/playground.js'];
        $this->css = ['css/playground.css'];

        parent::init();
    }
}
