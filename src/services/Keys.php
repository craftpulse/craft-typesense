<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\services;

use craft\base\Component;
use craft\helpers\App;
use craftpulse\typesense\models\Settings;
use craftpulse\typesense\Typesense;
use yii\base\InvalidConfigException;

/**
 * Scoped search key service (the Free security primitive).
 *
 * Derives narrow, expiring, filter-embedded search keys from the search-only key
 * entirely client-side (HMAC over the search-only key, no server round trip), so
 * the front end never sees the admin key or an unrestricted search key. Supports
 * the embeddable parameters filter_by, expires_at, include_fields, exclude_fields,
 * and the only rate-limit levers Typesense offers: limit_hits,
 * limit_multi_searches, and cache_ttl.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class Keys extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Derives a scoped search key from the configured search-only key.
     *
     * @param array<string, mixed> $parameters
     * @param string|null $searchKey Overrides the configured search-only key.
     * @return string
     * @throws InvalidConfigException when no search-only key is available.
     * @author CraftPulse
     */
    public function generateScopedSearchKey(array $parameters, ?string $searchKey = null): string
    {
        $searchKey ??= (string)App::parseEnv($this->_settings()->searchOnlyApiKey);

        if ($searchKey === '') {
            throw new InvalidConfigException('No search-only API key is configured for scoped key derivation.');
        }

        return $this->_derive($searchKey, $parameters);
    }

    // Private Methods
    // =========================================================================

    /**
     * @return Settings
     * @author CraftPulse
     */
    private function _settings(): Settings
    {
        /** @var Settings $settings */
        $settings = Typesense::$plugin->getSettings();

        return $settings;
    }

    /**
     * Derives the scoped key using Typesense's HMAC scheme (mirrors typesense-php
     * so derived keys validate on the server).
     *
     * @param string $searchKey
     * @param array<string, mixed> $parameters
     * @return string
     * @author CraftPulse
     */
    private function _derive(string $searchKey, array $parameters): string
    {
        $paramString = json_encode($parameters, JSON_THROW_ON_ERROR);
        $digest = base64_encode(hash_hmac(
            'sha256',
            mb_convert_encoding($paramString, 'UTF-8', 'ISO-8859-1'),
            mb_convert_encoding($searchKey, 'UTF-8', 'ISO-8859-1'),
            true,
        ));
        $keyPrefix = substr($searchKey, 0, 4);
        $rawScopedKey = sprintf(
            '%s%s%s',
            mb_convert_encoding($digest, 'ISO-8859-1', 'UTF-8'),
            $keyPrefix,
            $paramString,
        );

        return base64_encode(mb_convert_encoding($rawScopedKey, 'UTF-8', 'ISO-8859-1'));
    }
}
