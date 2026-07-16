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

use Craft;
use craft\web\View;
use craftpulse\typesense\Typesense;

/**
 * Resolves and renders the atomic front-end search components with a per-file
 * override, adapted from Formie's Form::renderTemplate (verbb/formie
 * src/elements/Form.php): a configured site template directory
 * (`frontendTemplatesDir`) is checked first per component, falling back to the
 * plugin default under the `_typesense` root when the file is not overridden.
 * Deliberately simplified from Formie: a single plugin-setting directory rather
 * than a per-form template element, per the estate's fewer-moving-parts rule.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class FrontendTemplates
{
    // Constants
    // =========================================================================

    /**
     * @var string The plugin's default front-end template root (mapped to
     * src/templates/frontend).
     */
    public const DEFAULT_ROOT = '_typesense';

    // Static Methods
    // =========================================================================

    /**
     * Renders an atomic component: the file from the configured override
     * directory when it exists there, otherwise the plugin default.
     *
     * @param string $name the component name (for example `results`, `_facet-item`)
     * @param array<string, mixed> $variables
     * @return string
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     * @throws \yii\base\Exception
     * @author CraftPulse
     */
    public static function render(string $name, array $variables = []): string
    {
        $view = Craft::$app->getView();
        $override = self::_overridePath($name);

        if ($override !== null) {
            return $view->renderTemplate($override, $variables, View::TEMPLATE_MODE_SITE);
        }

        return $view->renderTemplate(self::DEFAULT_ROOT . '/' . $name, $variables, View::TEMPLATE_MODE_SITE);
    }

    /**
     * Renders a morph region and enforces the SSE contract: the region must
     * carry its required id (the Datastar patch target). If an override dropped
     * it, this re-renders the plugin default for that region and logs a loud
     * warning, so the morph keeps working instead of silently failing.
     *
     * @param string $name the region component name (results, facets, pagination)
     * @param string $requiredId the required element id, without the leading `#`
     * @param array<string, mixed> $variables
     * @return string
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     * @throws \yii\base\Exception
     * @author CraftPulse
     */
    public static function renderRegion(string $name, string $requiredId, array $variables = []): string
    {
        $html = self::render($name, $variables);

        if (str_contains($html, 'id="' . $requiredId . '"')) {
            return $html;
        }

        Craft::warning(
            "The Typesense search override \"{$name}\" is missing the required id=\"{$requiredId}\". "
            . 'Datastar cannot morph a region without it, so the plugin default is rendered instead. '
            . 'Keep the id on the top-level element when overriding this region.',
            __METHOD__,
        );

        return Craft::$app->getView()->renderTemplate(self::DEFAULT_ROOT . '/' . $name, $variables, View::TEMPLATE_MODE_SITE);
    }

    /**
     * Renders a result card for a document, switching on its union `_elementType`
     * discriminator to a per-member card (`_result-card--<type>`) when one exists
     * (as an override or a shipped default), and falling back to the shared
     * `_result-card` otherwise. Regular-collection documents (no `_elementType`)
     * always use the shared card.
     *
     * @param array<string, mixed> $doc
     * @param array<string, mixed> $variables
     * @return string
     * @throws \Twig\Error\LoaderError
     * @throws \Twig\Error\RuntimeError
     * @throws \Twig\Error\SyntaxError
     * @throws \yii\base\Exception
     * @author CraftPulse
     */
    public static function renderCard(array $doc, array $variables = []): string
    {
        $type = trim((string)($doc['_elementType'] ?? ''));

        if ($type !== '') {
            $name = '_result-card--' . (string)preg_replace('/[^a-zA-Z0-9_\-]/', '', $type);

            if (self::_exists($name)) {
                return self::render($name, $variables);
            }
        }

        return self::render('_result-card', $variables);
    }

    // Private Methods
    // =========================================================================

    /**
     * Whether a component template exists, as a configured override or a shipped
     * plugin default.
     *
     * @param string $name
     * @return bool
     * @author CraftPulse
     */
    private static function _exists(string $name): bool
    {
        if (self::_overridePath($name) !== null) {
            return true;
        }

        return Craft::$app->getView()->doesTemplateExist(self::DEFAULT_ROOT . '/' . $name, View::TEMPLATE_MODE_SITE);
    }

    /**
     * Returns the override template path for a component when the configured
     * directory holds it, or null to fall back to the plugin default.
     *
     * @param string $name
     * @return string|null
     * @author CraftPulse
     */
    private static function _overridePath(string $name): ?string
    {
        /** @var \craftpulse\typesense\models\Settings $settings */
        $settings = Typesense::$plugin->getSettings();
        $dir = trim($settings->frontendTemplatesDir, '/ ');

        if ($dir === '') {
            return null;
        }

        $path = $dir . '/' . $name;

        return Craft::$app->getView()->doesTemplateExist($path, View::TEMPLATE_MODE_SITE) ? $path : null;
    }
}
