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
    $originalTheme = $settings->frontendThemeConfig;
    $originalDir = $settings->frontendTemplatesDir;
    // Clear any override directory so this exercises the default facet-item
    // template (the one that reads the theme config); an override is free to
    // hardcode its own classes and ignore the theme, which is a separate concern.
    $settings->frontendTemplatesDir = '';
    $settings->frontendThemeConfig = ['facetItem' => ['class' => 'ts-test-themed-facet']];

    // SearchFormTag::render() reads the CURRENT ambient request's `q` param
    // (Request::getParam('q', '')) to prefill the no-JS form - unlike every
    // other test in this suite that exercises it, this one never issues its
    // own `$this->get()`/`$this->post()` call (which is what actually
    // replaces craft-pest's request component - see the craft-pest skill's
    // craft-state.md, "Service caches go stale when craft-pest swaps
    // components"), so it is reading whatever request an unrelated, earlier
    // test in the suite happened to leave in place. A `q` value there other
    // than empty makes the rendered facet list legitimately empty (Typesense
    // has no matches for an arbitrary leaked query string), which looks like
    // a missing-theme failure but is really a stale-request one. Reset the
    // ambient query params before rendering and restore them after, so this
    // test's own zero-query intent ("what does the fresh, no-JS form look
    // like") holds regardless of test order.
    $request = Craft::$app->getRequest();
    $originalQueryParams = $request->getQueryParams();
    $request->setQueryParams([]);

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
        $request->setQueryParams($originalQueryParams);
        $settings->frontendThemeConfig = $originalTheme;
        $settings->frontendTemplatesDir = $originalDir;
    }
});
