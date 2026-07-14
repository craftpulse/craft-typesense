<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://percipio.london
 * @copyright Copyright (c) 2021 craftpulse
 */

namespace craftpulse\typesense\variables;

use Craft;

use craftpulse\typesense\Typesense;
use nystudio107\pluginvite\variables\ViteVariableInterface;

use nystudio107\pluginvite\variables\ViteVariableTrait;

/**
 * Typesense Variable
 *
 * Craft allows plugins to provide their own template variables, accessible from
 * the {{ craft }} global variable (e.g. {{ craft.typesense }}).
 *
 * https://craftcms.com/docs/plugins/variables
 *
 * @author    craftpulse
 * @package   Typesense
 * @since     1.0.0
 */
class TypesenseVariable implements ViteVariableInterface
{
    // Public Methods
    // =========================================================================

    /**
     * Whatever you want to output to a Twig template can go into a Variable method.
     * You can have as many variable functions as you want.  From any Twig template,
     * call it like this:
     *
     *     {{ craft.typesense.exampleVariable }}
     *
     * Or, if your variable requires parameters from Twig:
     *
     *     {{ craft.typesense.exampleVariable(twigValue) }}
     *
     * @param null $optional
     * @return string
     */

    use ViteVariableTrait;
}
