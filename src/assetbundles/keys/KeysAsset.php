<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\assetbundles\keys;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * The asset bundle for the Pro keys manager's show-once modal. Plain JavaScript
 * (no build step): it uses the ambient Garnish, Craft, and jQuery globals the
 * control panel already provides, so it depends on CpAsset (which pulls in
 * GarnishAsset).
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class KeysAsset extends AssetBundle
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
        $this->js = ['js/keys.js'];

        parent::init();
    }
}
