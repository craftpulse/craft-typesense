<?php
/**
 * Typesense plugin for Craft CMS 4.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://percipio.london
 * @copyright Copyright (c) 2021 percipiolondon
 */

namespace percipiolondon\typesense\utilities;

use Craft;
use craft\base\Utility;

use percipiolondon\typesense\assetbundles\typesenseutility\TypesenseUtilityAsset;
use percipiolondon\typesense\Typesense;

/**
 * Typesense Utility
 *
 * Utility is the base class for classes representing Control Panel utilities.
 *
 * https://craftcms.com/docs/plugins/utilities
 *
 * @author    percipiolondon
 * @package   Typesense
 * @since     1.0.0
 */
class TypesenseUtility extends Utility
{
    // Static
    // =========================================================================

    /**
     * Returns the display name of this utility.
     *
     * @return string The display name of this utility.
     */
    public static function displayName(): string
    {
        return Craft::t('typesense', 'TypesenseUtility');
    }

    /**
     * Returns the utility’s unique identifier.
     *
     * The ID should be in `kebab-case`, as it will be visible in the URL (`admin/utilities/the-handle`).
     */
    public static function id(): string
    {
        return 'typesense-typesense-utility';
    }

    /**
     * Returns the path to the utility's SVG icon.
     *
     * @return string|null The path to the utility SVG icon
     */
    public static function iconPath()
    {
        return Craft::getAlias("@percipiolondon/typesense/assetbundles/typesenseutility/dist/img/TypesenseUtility-icon.svg");
    }

    /**
     * Returns the number that should be shown in the utility’s nav item badge.
     *
     * If `0` is returned, no badge will be shown
     */
    public static function badgeCount(): int
    {
        return 0;
    }

    /**
     * Returns the utility's content HTML.
     */
    public static function contentHtml(): string
    {
        Craft::$app->getView()->registerAssetBundle(TypesenseUtilityAsset::class);
        return Craft::$app->getView()->renderTemplate('typesense/_components/utilities/TypesenseUtility_content');
    }
}
