<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Compiles every control-panel template through Twig (tokenize + parse + compile,
 * no rendering) so a pure compile-time SyntaxError in any template fails the
 * suite. This is a deliberately data-free, request-free check: nothing renders,
 * so the SEOmatic deprecation cascade never fires, and no CP session is needed.
 * It exists because a template SyntaxError (illegal `??` on a filtered
 * expression) reached a smoke review; this closes that coverage gap.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\web\View;

it('compiles every plugin control-panel template without a syntax error', function() {
    $view = Craft::$app->getView();
    $originalMode = $view->getTemplateMode();
    $view->setTemplateMode(View::TEMPLATE_MODE_CP);
    $twig = $view->getTwig();

    // The plugin's CP templates live under src/templates and are rooted at the
    // `typesense` handle, so `src/templates/relevance/_edit.twig` loads as
    // `typesense/relevance/_edit`.
    $root = dirname(__DIR__, 2) . '/src/templates';
    $files = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

    foreach ($iterator as $file) {
        if ($file->getExtension() === 'twig') {
            $files[] = $file->getPathname();
        }
    }

    expect($files)->not->toBeEmpty();

    $failures = [];

    foreach ($files as $path) {
        $name = 'typesense/' . str_replace('.twig', '', substr($path, strlen($root) + 1));

        try {
            // load() tokenizes, parses, and compiles the template class (no
            // render), surfacing any SyntaxError.
            $twig->load($name);
        } catch (\Twig\Error\SyntaxError $e) {
            $failures[] = "{$name}: {$e->getMessage()}";
        }
    }

    $view->setTemplateMode($originalMode);

    expect($failures)->toBe([]);
});
