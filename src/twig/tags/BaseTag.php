<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\twig\tags;

use Craft;
use InvalidArgumentException;
use Twig\Markup;

/**
 * BaseTag is the base class for the fluent Twig render builders shipped under
 * `craft.typesense.*` for the front-end search experience. It provides the
 * chainable-setter / config-array dual-mode constructor and the Markup-wrapped
 * render path, the same builder shape the estate uses elsewhere (Warp's OTP
 * builders, Password Policy's form builders).
 *
 * Concrete tags implement [[_renderHtml()]] returning a plain HTML string.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
abstract class BaseTag
{
    // Protected Properties
    // =========================================================================

    /**
     * @var array<string, mixed> Raw config array, the backing store for the
     * fluent setters.
     */
    protected array $config = [];

    // Public Methods
    // =========================================================================

    /**
     * Accepts an initial configuration array. Each key must match a chainable
     * setter on the concrete subclass; an unknown key throws so consumers learn
     * about typos at render time instead of silently losing an option.
     *
     * @param array<string, mixed> $config
     * @throws InvalidArgumentException if a key matches no setter
     * @author CraftPulse
     */
    public function __construct(array $config = [])
    {
        foreach ($config as $key => $value) {
            if (!method_exists($this, $key)) {
                throw new InvalidArgumentException(sprintf('Unknown option "%s" for %s.', $key, static::class));
            }

            $this->$key($value);
        }
    }

    /**
     * Stringifies the rendered HTML for PHP-context composition.
     *
     * @return string raw HTML; the consumer must not re-escape it
     * @author CraftPulse
     */
    public function __toString(): string
    {
        return $this->_renderHtml();
    }

    /**
     * Renders the configured tag to HTML, wrapped in a `Markup` instance so
     * Twig does not double-escape it. Always call `{{ tag.render() }}` from Twig.
     *
     * @return Markup
     * @author CraftPulse
     */
    public function render(): Markup
    {
        return new Markup($this->_renderHtml(), Craft::$app->getView()->getTwig()->getCharset());
    }

    // Protected Methods
    // =========================================================================

    /**
     * Concrete subclasses implement this; [[render()]] wraps the result in a
     * Twig `Markup` so consumers never need `|raw`.
     *
     * @return string raw HTML
     * @author CraftPulse
     */
    abstract protected function _renderHtml(): string;
}
