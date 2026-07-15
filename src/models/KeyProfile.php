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
 * A named scoped-key profile: a reusable, per-site or per-group filter template
 * persisted to project config and referenced by the Free derivation helper
 * (`craft.typesense.scopedSearchKey(profile: 'handle')`). The profile holds the
 * embeddable scoped-key parameters (the locked filter, field restrictions, an
 * expiry window, and the rate levers); the plugin resolves it into a derived key
 * server-side, so the front end never sees the search-only key.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class KeyProfile extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var string The profile handle (the project-config key and the reference
     * used by the derivation helper).
     */
    public string $handle = '';

    /**
     * @var string A human label for the control panel.
     */
    public string $name = '';

    /**
     * @var string The filter the derived key is locked to (the browser cannot
     * widen it).
     */
    public string $filterBy = '';

    /**
     * @var string A comma-separated allow-list of returnable fields, or empty.
     */
    public string $includeFields = '';

    /**
     * @var string A comma-separated deny-list of returnable fields, or empty.
     */
    public string $excludeFields = '';

    /**
     * @var int Seconds from derivation until the key expires (0 for no expiry).
     */
    public int $expiresIn = 0;

    /**
     * @var int A per-key cap on the number of hits (0 for no cap).
     */
    public int $limitHits = 0;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @return array<int, mixed>
     */
    public function defineRules(): array
    {
        $rules = parent::defineRules();
        $rules[] = [['handle', 'name'], 'required'];
        $rules[] = [['handle'], 'match', 'pattern' => '/^[a-zA-Z][a-zA-Z0-9_\-]*$/'];
        $rules[] = [['expiresIn', 'limitHits'], 'integer', 'min' => 0];
        $rules[] = [['filterBy', 'includeFields', 'excludeFields'], 'safe'];

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
            'filterBy' => $this->filterBy,
            'includeFields' => $this->includeFields,
            'excludeFields' => $this->excludeFields,
            'expiresIn' => $this->expiresIn,
            'limitHits' => $this->limitHits,
        ];
    }
}
