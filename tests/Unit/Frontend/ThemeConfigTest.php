<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The front-end theme config (Fix 19B): the class/attribute override layer for
 * the atomic search components. Pins the structural-vs-cosmetic merge, the
 * per-component and global reset escape hatches, arbitrary attributes, and that
 * a configured theme actually reaches the rendered search components.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\typesense\helpers\ThemeConfig;
use craftpulse\typesense\twig\tags\SearchFormTag;
use craftpulse\typesense\Typesense;

it('keeps the structural default classes when no config is set', function() {
    expect(ThemeConfig::attributes([], 'facetItem', 'ts-facets__item'))
        ->toBe(' class="ts-facets__item"');
});

it('merges cosmetic config classes onto the structural defaults', function() {
    $theme = ['facetItem' => ['class' => 'chip chip--rounded']];

    expect(ThemeConfig::attributes($theme, 'facetItem', 'ts-facets__item'))
        ->toContain('ts-facets__item')
        ->and(ThemeConfig::attributes($theme, 'facetItem', 'ts-facets__item'))
        ->toContain('chip chip--rounded');
});

it('drops the structural defaults with a per-component resetClass', function() {
    $theme = ['facetItem' => ['class' => 'chip', 'resetClass' => true]];

    expect(ThemeConfig::attributes($theme, 'facetItem', 'ts-facets__item'))
        ->toBe(' class="chip"');
});

it('drops the structural defaults everywhere with a global resetClasses', function() {
    $theme = ['resetClasses' => true, 'facetItem' => ['class' => 'chip']];

    expect(ThemeConfig::attributes($theme, 'facetItem', 'ts-facets__item'))
        ->toBe(' class="chip"');
});

it('renders configured attributes alongside the classes', function() {
    $theme = ['facetItem' => ['attributes' => ['data-facet' => 'brand']]];
    $attrs = ThemeConfig::attributes($theme, 'facetItem', 'ts-facets__item');

    expect($attrs)->toContain('class="ts-facets__item"')
        ->and($attrs)->toContain('data-facet="brand"');
});

it('merges the plugin-global config under the per-render config', function() {
    $merged = ThemeConfig::merge(
        ['facetItem' => ['class' => 'global']],
        ['facetItem' => ['class' => 'per-render'], 'resultCard' => ['class' => 'card']],
    );

    expect($merged['facetItem']['class'])->toBe('per-render')
        ->and($merged['resultCard']['class'])->toBe('card');
});

it('applies the configured theme to the rendered search components', function() {
    /** @var \craftpulse\typesense\models\Settings $settings */
    $settings = Typesense::$plugin->getSettings();
    $original = $settings->frontendThemeConfig;
    $settings->frontendThemeConfig = ['facetItem' => ['class' => 'ts-test-themed-facet']];

    try {
        $html = (string)(new SearchFormTag([
            'collection' => 'heroes',
            'queryBy' => 'title,slug',
            'facetBy' => 'slug',
            'perPage' => 5,
        ]))->render();

        expect($html)->toContain('ts-test-themed-facet')
            ->and($html)->toContain('ts-facets__item');
    } finally {
        $settings->frontendThemeConfig = $original;
    }
});
