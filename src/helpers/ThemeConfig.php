<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\helpers;

use craft\helpers\Html;

/**
 * Merges and applies the front-end theme config, the class/attribute override
 * layer for the atomic search components (adapted from Formie's themeConfig, see
 * verbb/formie src/elements/Form.php). The config is a map keyed by component
 * (`facetItem`, `resultCard`, ...); each entry may carry a `class` string, an
 * `attributes` map, and a `resetClass` flag. A top-level `resetClasses` flag
 * strips the structural default classes globally. Structural classes live in the
 * templates (the defaults passed here); cosmetic classes come from the config,
 * mirroring Formie's structural-vs-cosmetic split.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class ThemeConfig
{
    // Static Methods
    // =========================================================================

    /**
     * Merges the plugin-global theme config with the per-render overrides
     * (per-render wins), the way Formie layers plugin settings under template
     * render options.
     *
     * @param array<string, mixed> $global the plugin-global config
     * @param array<string, mixed> $perRender the per-render override config
     * @return array<string, mixed>
     * @author CraftPulse
     */
    public static function merge(array $global, array $perRender): array
    {
        return array_replace_recursive($global, $perRender);
    }

    /**
     * Renders the HTML attribute string for a component: the structural default
     * classes merged with the config's cosmetic classes (unless reset), plus any
     * configured attributes. Returns a leading-space attribute string ready to
     * drop into a tag, escaped by craft\helpers\Html.
     *
     * @param array<string, mixed> $theme the merged theme config
     * @param string $key the component key (for example `facetItem`)
     * @param string $defaultClasses the template's structural default classes
     * @return string
     * @author CraftPulse
     */
    public static function attributes(array $theme, string $key, string $defaultClasses = ''): string
    {
        $item = is_array($theme[$key] ?? null) ? $theme[$key] : [];
        $reset = ($theme['resetClasses'] ?? false) === true || ($item['resetClass'] ?? false) === true;

        $classes = trim(($reset ? '' : $defaultClasses) . ' ' . (string)($item['class'] ?? ''));
        $attributes = is_array($item['attributes'] ?? null) ? $item['attributes'] : [];

        if ($classes !== '') {
            $attributes = ['class' => $classes] + $attributes;
        }

        return Html::renderTagAttributes($attributes);
    }
}
