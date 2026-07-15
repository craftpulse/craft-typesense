<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\models;

use craft\base\Model;

/**
 * An A/B search experiment: a collection, two or more weighted variants, and the
 * composition each variant applies (a scoped-key profile, a search preset, and an
 * analytics tag). Persisted to project config keyed by handle. Traffic is split
 * by deriving a per-variant scoped key that embeds the variant's analytics tag,
 * so results are attributed to the variant server-side. The click-through and
 * no-result comparison is the analytics dashboard's job (a later phase).
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class Experiment extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var string The experiment handle (the project-config key).
     */
    public string $handle = '';

    /**
     * @var string A human label.
     */
    public string $name = '';

    /**
     * @var string The logical collection the experiment runs against.
     */
    public string $collection = '';

    /**
     * @var bool Whether the experiment is active.
     */
    public bool $enabled = true;

    /**
     * @var array<int, array<string, mixed>> The variants: each carries `handle`,
     * `name`, `weight`, and the composition (`profile`, `preset`, `analyticsTag`).
     */
    public array $variants = [];

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @return array<int, mixed>
     */
    public function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['handle', 'name', 'collection'], 'required'];
        $rules[] = [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_\-]*$/'];
        $rules[] = [['enabled'], 'boolean'];
        $rules[] = [['variants'], 'safe'];

        return $rules;
    }

    /**
     * Returns the project-config representation (no handle, which is the key).
     *
     * @return array<string, mixed>
     * @author CraftPulse
     */
    public function getConfig(): array
    {
        return [
            'name' => $this->name,
            'collection' => $this->collection,
            'enabled' => $this->enabled,
            'variants' => array_values($this->variants),
        ];
    }
}
