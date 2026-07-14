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
 * Server capability descriptor.
 *
 * Derived from the Typesense server version reported at handshake (GET /debug).
 * Every version-sensitive feature routes through this object so the CP and the
 * fluent config can gate on server capability exactly like edition gating
 * (hide-not-badge): unsupported features are hidden, never disabled or badged.
 *
 * Version support policy (ruled by the build plan):
 * - Floor is v28.0. Anything below is unsupported.
 * - v30.0 and v30.1 are REFUSED: both the per-collection and the *_sets synonym
 *   and curation API shapes return 404 on those Docker images, so the plugin
 *   cannot operate reliably. v30.2 restores the *_sets shapes.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class ServerCapabilities extends Model
{
    // Constants
    // =========================================================================

    /**
     * @var string The lowest Typesense server version the plugin supports.
     */
    public const FLOOR_VERSION = '28.0';

    /**
     * @var string `_text_match(buckets: N)` relevance bucketing (v28+).
     */
    public const VERSION_TEXT_MATCH_BUCKETS = '28.0';

    /**
     * @var string `rerank_hybrid_matches` hybrid search re-ranking (v28+).
     */
    public const VERSION_RERANK_HYBRID_MATCHES = '28.0';

    /**
     * @var string `analytics_tag` search analytics segmentation (v29+).
     */
    public const VERSION_ANALYTICS_TAGS = '29.0';

    /**
     * @var string Natural language search models (v29+).
     */
    public const VERSION_NL_SEARCH = '29.0';

    /**
     * @var string `mmr` result diversification (v30+).
     */
    public const VERSION_MMR = '30.0';

    /**
     * @var string Collection cloning via `src_name` (v30.1+).
     */
    public const VERSION_COLLECTION_CLONING = '30.1';

    /**
     * @var string Global synonym sets (`/synonym_sets`) (v30.2+).
     */
    public const VERSION_SYNONYM_SETS = '30.2';

    /**
     * @var string Global curation sets (`/curation_sets`) (v30.2+).
     */
    public const VERSION_CURATION_SETS = '30.2';

    /**
     * @var string Native personalization models (v30.2+, experimental upstream).
     */
    public const VERSION_PERSONALIZATION_MODELS = '30.2';

    // Public Properties
    // =========================================================================

    /**
     * @var string The raw server version string reported by GET /debug (e.g. "28.0").
     */
    public string $version = '';

    // Public Methods
    // =========================================================================

    /**
     * Returns whether the server version is at or above the given version.
     *
     * @param string $version
     * @return bool
     * @author CraftPulse
     */
    public function atLeast(string $version): bool
    {
        if ($this->version === '') {
            return false;
        }

        return version_compare($this->version, $version, '>=');
    }

    /**
     * Returns whether the server version is below the supported floor.
     *
     * @return bool
     * @author CraftPulse
     */
    public function isBelowFloor(): bool
    {
        if ($this->version === '') {
            return true;
        }

        return version_compare($this->version, self::FLOOR_VERSION, '<');
    }

    /**
     * Returns whether the server version is explicitly refused (v30.0 / v30.1).
     *
     * @return bool
     * @author CraftPulse
     */
    public function isRefused(): bool
    {
        [$major, $minor] = $this->_versionParts();

        return $major === 30 && ($minor === 0 || $minor === 1);
    }

    /**
     * Returns whether the plugin can operate against this server version.
     *
     * @return bool
     * @author CraftPulse
     */
    public function isSupported(): bool
    {
        return !$this->isBelowFloor() && !$this->isRefused();
    }

    /**
     * Returns a human-readable reason a version is unsupported, or null when supported.
     *
     * @return string|null
     * @author CraftPulse
     */
    public function getUnsupportedReason(): ?string
    {
        if ($this->isBelowFloor()) {
            return "Typesense server {$this->version} is below the supported floor (v" . self::FLOOR_VERSION . '). Upgrade the server.';
        }

        if ($this->isRefused()) {
            return "Typesense server {$this->version} is not supported: the synonym and curation API shapes return 404 on v30.0 and v30.1. Use v28.x, v29.x, or v30.2 and later.";
        }

        return null;
    }

    // Capability flags
    // =========================================================================

    /**
     * @return bool
     * @author CraftPulse
     */
    public function textMatchBuckets(): bool
    {
        return $this->atLeast(self::VERSION_TEXT_MATCH_BUCKETS);
    }

    /**
     * @return bool
     * @author CraftPulse
     */
    public function rerankHybridMatches(): bool
    {
        return $this->atLeast(self::VERSION_RERANK_HYBRID_MATCHES);
    }

    /**
     * @return bool
     * @author CraftPulse
     */
    public function analyticsTags(): bool
    {
        return $this->atLeast(self::VERSION_ANALYTICS_TAGS);
    }

    /**
     * @return bool
     * @author CraftPulse
     */
    public function nlSearch(): bool
    {
        return $this->atLeast(self::VERSION_NL_SEARCH);
    }

    /**
     * @return bool
     * @author CraftPulse
     */
    public function mmr(): bool
    {
        return $this->atLeast(self::VERSION_MMR);
    }

    /**
     * @return bool
     * @author CraftPulse
     */
    public function collectionCloning(): bool
    {
        return $this->atLeast(self::VERSION_COLLECTION_CLONING);
    }

    /**
     * @return bool
     * @author CraftPulse
     */
    public function synonymSets(): bool
    {
        return $this->atLeast(self::VERSION_SYNONYM_SETS);
    }

    /**
     * @return bool
     * @author CraftPulse
     */
    public function curationSets(): bool
    {
        return $this->atLeast(self::VERSION_CURATION_SETS);
    }

    /**
     * @return bool
     * @author CraftPulse
     */
    public function personalizationModels(): bool
    {
        return $this->atLeast(self::VERSION_PERSONALIZATION_MODELS);
    }

    /**
     * Returns the full capability flag map, keyed by flag name.
     *
     * @return array<string, bool>
     * @author CraftPulse
     */
    public function getFlags(): array
    {
        return [
            'textMatchBuckets' => $this->textMatchBuckets(),
            'rerankHybridMatches' => $this->rerankHybridMatches(),
            'analyticsTags' => $this->analyticsTags(),
            'nlSearch' => $this->nlSearch(),
            'mmr' => $this->mmr(),
            'collectionCloning' => $this->collectionCloning(),
            'synonymSets' => $this->synonymSets(),
            'curationSets' => $this->curationSets(),
            'personalizationModels' => $this->personalizationModels(),
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the [major, minor] integer parts of the version string.
     *
     * @return array{0: int, 1: int}
     * @author CraftPulse
     */
    private function _versionParts(): array
    {
        $parts = explode('.', $this->version);

        return [
            (int)($parts[0] ?? 0),
            (int)($parts[1] ?? 0),
        ];
    }
}
