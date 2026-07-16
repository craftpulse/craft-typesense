<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The front-end template override resolver (Fix 19B): a configured site template
 * directory overrides the plugin's atomic search components file-by-file, and a
 * region override that drops its required morph id degrades gracefully to the
 * plugin default with a warning. Uses a scratch override directory written under
 * the site templates path.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use Craft;
use craftpulse\typesense\helpers\FrontendTemplates;
use craftpulse\typesense\Typesense;

function withScratchOverrides(array $files, callable $test): void
{
    // A unique directory per call: Twig caches compiled templates by path, so
    // reusing one path with different content across tests would serve a stale
    // class. A fresh path each time keeps every scratch override isolated.
    $slug = 'ts-scratch-' . bin2hex(random_bytes(4));
    $dir = Craft::$app->getPath()->getSiteTemplatesPath() . '/' . $slug;

    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }

    foreach ($files as $name => $contents) {
        file_put_contents($dir . '/' . $name, $contents);
    }

    /** @var \craftpulse\typesense\models\Settings $settings */
    $settings = Typesense::$plugin->getSettings();
    $original = $settings->frontendTemplatesDir;
    $settings->frontendTemplatesDir = $slug;

    try {
        $test();
    } finally {
        $settings->frontendTemplatesDir = $original;

        foreach (array_keys($files) as $name) {
            @unlink($dir . '/' . $name);
        }

        @rmdir($dir);
    }
}

it('falls back to the plugin default when no override directory is configured', function() {
    /** @var \craftpulse\typesense\models\Settings $settings */
    $settings = Typesense::$plugin->getSettings();
    $originalDir = $settings->frontendTemplatesDir;
    $settings->frontendTemplatesDir = '';

    try {
        $html = FrontendTemplates::render('_facet-item', [
            'value' => 'brand-x',
            'count' => 3,
            'id' => 'f1',
            'selected' => false,
            'endpoint' => '',
            'theme' => [],
        ]);

        expect($html)->toContain('ts-facets__item')
            ->and($html)->toContain('brand-x')
            // The default template, not the demo pill override.
            ->and($html)->not->toContain('ts-demo-pill');
    } finally {
        $settings->frontendTemplatesDir = $originalDir;
    }
});

it('renders an atom override (_facet-item) from the configured directory', function() {
    withScratchOverrides([
        '_facet-item.twig' => '<li class="ts-custom-facet-item-marker">{{ value }} ({{ count }})</li>',
    ], function() {
        $html = FrontendTemplates::render('_facet-item', [
            'value' => 'brand-x',
            'count' => 3,
            'id' => 'f1',
            'selected' => false,
            'endpoint' => '',
            'theme' => [],
        ]);

        expect($html)->toContain('ts-custom-facet-item-marker')
            ->and($html)->toContain('brand-x')
            ->and($html)->not->toContain('ts-facets__item');
    });
});

it('renders a valid region override that keeps its required id', function() {
    withScratchOverrides([
        'facets.twig' => '<div id="ts-facets" class="ts-custom-facets-marker">custom</div>',
    ], function() {
        $html = FrontendTemplates::renderRegion('facets', 'ts-facets', [
            'result' => [],
            'options' => [],
            'endpoint' => '',
            'theme' => [],
        ]);

        expect($html)->toContain('id="ts-facets"')
            ->and($html)->toContain('ts-custom-facets-marker');
    });
});

it('graceful-degrades to the plugin default when a region override drops the required id', function() {
    withScratchOverrides([
        'facets.twig' => '<div class="broken-no-id-facets">oops, no id</div>',
    ], function() {
        $html = FrontendTemplates::renderRegion('facets', 'ts-facets', [
            'result' => [],
            'options' => [],
            'endpoint' => '',
            'theme' => [],
        ]);

        // The broken override is discarded; the plugin default (with the id) is
        // rendered instead, so the morph target survives.
        expect($html)->toContain('id="ts-facets"')
            ->and($html)->not->toContain('broken-no-id-facets');
    });
});

it('switches a union document to its per-member card, and falls back to the shared card', function() {
    withScratchOverrides([
        '_result-card--news.twig' => '<li class="news-card">{{ doc.title }}</li>',
    ], function() {
        // A union doc whose _elementType has a per-member card uses it.
        $news = FrontendTemplates::renderCard(['_elementType' => 'news', 'title' => 'A headline', 'id' => '1'], [
            'doc' => ['_elementType' => 'news', 'title' => 'A headline', 'id' => '1'],
            'href' => '#',
            'clickUrl' => '#',
            'csrfToken' => '',
            'theme' => [],
        ]);
        expect($news)->toContain('news-card')
            ->and($news)->toContain('A headline');

        // A member without a per-member card falls back to the shared card.
        $blocks = FrontendTemplates::renderCard(['_elementType' => 'blocks', 'title' => 'A block', 'id' => '2'], [
            'doc' => ['_elementType' => 'blocks', 'title' => 'A block', 'id' => '2'],
            'href' => '#',
            'clickUrl' => '#',
            'csrfToken' => '',
            'theme' => [],
        ]);
        expect($blocks)->not->toContain('news-card')
            ->and($blocks)->toContain('ts-results__item')
            ->and($blocks)->toContain('A block');
    });
});
