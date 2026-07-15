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

use Craft;
use craft\base\Component;
use craft\helpers\App;
use craft\helpers\DateTimeHelper;
use craftpulse\typesense\models\KeyProfile;
use craftpulse\typesense\models\Settings;
use craftpulse\typesense\Typesense;
use Throwable;
use yii\base\InvalidConfigException;

/**
 * API key service. Two responsibilities across the Free/Pro boundary:
 *
 * FREE (the security primitive): derives narrow, expiring, filter-embedded search
 * keys from the search-only key entirely server-side (HMAC over the search-only
 * key, no round trip), so the front end never sees the admin key or an
 * unrestricted search key. Supports filter_by, expires_at, include_fields,
 * exclude_fields, and the only rate levers Typesense offers (limit_hits,
 * limit_multi_searches, cache_ttl). Named profiles (persisted to project config)
 * template those parameters for reuse from the derivation helper.
 *
 * PRO (the CP management layer): drives Typesense's keys API (list, create,
 * delete). A created key's full value is returned to the caller exactly once
 * (for the show-once modal) and is NEVER stored by the plugin: no database, no
 * project config, no logs. Listing returns only the value prefix, as Typesense
 * itself does after creation.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class Keys extends Component
{
    // Constants
    // =========================================================================

    /**
     * @var string The project-config key under which scoped-key profiles live.
     */
    public const CONFIG_KEY = 'typesense.keyProfiles';

    /**
     * @var array<int, string> The Typesense key action scopes offered in the UI.
     */
    public const ACTIONS = [
        'documents:search',
        'documents:get',
        'documents:*',
        'collections:get',
        'collections:list',
        'collections:*',
        'aliases:*',
        'synonyms:*',
        'overrides:*',
        'presets:*',
        'analytics:*',
        'analytics/events:create',
        'keys:*',
        'operations/*',
        'metrics.json:list',
        '*',
    ];

    // Public Methods
    // =========================================================================

    /**
     * Lists the server's API keys. Typesense returns only the value prefix (not
     * the full value) after creation, so the list is safe to render. Fail-soft.
     *
     * @return array<int, array<string, mixed>>
     * @throws InvalidConfigException
     * @author CraftPulse
     */
    public function all(): array
    {
        $client = Typesense::$plugin->getClient()->client();

        if ($client === null) {
            return [];
        }

        try {
            return array_values($client->keys->retrieve()['keys'] ?? []);
        } catch (Throwable $e) {
            Craft::error("Could not list Typesense keys: {$e->getMessage()}", 'typesense');

            return [];
        }
    }

    /**
     * Creates an API key on the server and returns the creation response, which
     * includes the full `value` EXACTLY ONCE. The caller must surface it in the
     * show-once modal and discard it; the plugin never stores it.
     *
     * @param array<string, mixed> $schema description, actions, collections, expires_at
     * @return array<string, mixed>|null null on failure
     * @throws InvalidConfigException
     * @author CraftPulse
     */
    public function create(array $schema): ?array
    {
        $client = Typesense::$plugin->getClient()->client();

        if ($client === null) {
            return null;
        }

        try {
            return $client->keys->create($schema);
        } catch (Throwable $e) {
            Craft::error("Could not create a Typesense key: {$e->getMessage()}", 'typesense');

            return null;
        }
    }

    /**
     * Deletes an API key by its server id. Fail-soft.
     *
     * @param int $id
     * @return bool
     * @throws InvalidConfigException
     * @author CraftPulse
     */
    public function delete(int $id): bool
    {
        $client = Typesense::$plugin->getClient()->client();

        if ($client === null) {
            return false;
        }

        try {
            $client->keys[$id]->delete();

            return true;
        } catch (Throwable $e) {
            Craft::error("Could not delete Typesense key {$id}: {$e->getMessage()}", 'typesense');

            return false;
        }
    }

    /**
     * Deletes a scoped-key profile from project config.
     *
     * @param string $handle
     * @return void
     * @throws \yii\base\ErrorException
     * @throws \yii\base\Exception
     * @throws \yii\base\NotSupportedException
     * @throws \yii\web\ServerErrorHttpException
     * @author CraftPulse
     */
    public function deleteProfile(string $handle): void
    {
        Craft::$app->getProjectConfig()->remove(
            self::CONFIG_KEY . '.' . $handle,
            "Delete Typesense key profile \u{201C}{$handle}\u{201D}",
        );
    }

    /**
     * Derives a scoped search key from the configured search-only key. When a
     * profile handle is given, the profile's template parameters are resolved
     * server-side and take precedence over caller parameters (the locked filter
     * cannot be widened from a template call).
     *
     * @param array<string, mixed> $parameters
     * @param string|null $searchKey Overrides the configured search-only key.
     * @param string|null $profile A profile handle to resolve and apply.
     * @return string
     * @throws InvalidConfigException when no search-only key is available.
     * @author CraftPulse
     */
    public function generateScopedSearchKey(array $parameters, ?string $searchKey = null, ?string $profile = null): string
    {
        $searchKey ??= (string)App::parseEnv($this->_settings()->searchOnlyApiKey);

        if ($searchKey === '') {
            throw new InvalidConfigException('No search-only API key is configured for scoped key derivation.');
        }

        if ($profile !== null) {
            $resolved = $this->resolveProfileParameters($profile);

            if ($resolved === null) {
                throw new InvalidConfigException("Unknown Typesense scoped-key profile \"{$profile}\".");
            }

            $parameters = $resolved + $parameters;
        }

        return $this->_derive($searchKey, $parameters);
    }

    /**
     * Returns a scoped-key profile by handle, or null.
     *
     * @param string $handle
     * @return KeyProfile|null
     * @author CraftPulse
     */
    public function getProfile(string $handle): ?KeyProfile
    {
        $config = Craft::$app->getProjectConfig()->get(self::CONFIG_KEY . '.' . $handle);

        return is_array($config) ? $this->_profileFromConfig($handle, $config) : null;
    }

    /**
     * Returns every scoped-key profile, keyed by handle.
     *
     * @return array<string, KeyProfile>
     * @author CraftPulse
     */
    public function getProfiles(): array
    {
        $config = Craft::$app->getProjectConfig()->get(self::CONFIG_KEY);
        $profiles = [];

        if (is_array($config)) {
            foreach ($config as $handle => $data) {
                if (is_array($data)) {
                    $profiles[(string)$handle] = $this->_profileFromConfig((string)$handle, $data);
                }
            }
        }

        return $profiles;
    }

    /**
     * Resolves a profile into scoped-key parameters, computing `expires_at` from
     * the profile's expiry window at call time. Returns null for an unknown
     * profile.
     *
     * @param string $handle
     * @return array<string, mixed>|null
     * @author CraftPulse
     */
    public function resolveProfileParameters(string $handle): ?array
    {
        $profile = $this->getProfile($handle);

        if ($profile === null) {
            return null;
        }

        $parameters = [];

        if ($profile->filterBy !== '') {
            $parameters['filter_by'] = $profile->filterBy;
        }

        if ($profile->includeFields !== '') {
            $parameters['include_fields'] = $profile->includeFields;
        }

        if ($profile->excludeFields !== '') {
            $parameters['exclude_fields'] = $profile->excludeFields;
        }

        if ($profile->expiresIn > 0) {
            $parameters['expires_at'] = DateTimeHelper::currentTimeStamp() + $profile->expiresIn;
        }

        if ($profile->limitHits > 0) {
            $parameters['limit_hits'] = $profile->limitHits;
        }

        return $parameters;
    }

    /**
     * Validates a profile and writes it to project config.
     *
     * @param KeyProfile $profile
     * @return bool false when the profile fails validation
     * @throws \yii\base\ErrorException
     * @throws \yii\base\Exception
     * @throws \yii\base\NotSupportedException
     * @throws \yii\web\ServerErrorHttpException
     * @author CraftPulse
     */
    public function saveProfile(KeyProfile $profile): bool
    {
        if (!$profile->validate()) {
            return false;
        }

        Craft::$app->getProjectConfig()->set(
            self::CONFIG_KEY . '.' . $profile->handle,
            $profile->getConfig(),
            "Save Typesense key profile \u{201C}{$profile->handle}\u{201D}",
        );

        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * Hydrates a profile model from a stored project-config array.
     *
     * @param string $handle
     * @param array<string, mixed> $config
     * @return KeyProfile
     * @author CraftPulse
     */
    private function _profileFromConfig(string $handle, array $config): KeyProfile
    {
        $profile = new KeyProfile();
        $profile->handle = $handle;
        $profile->name = (string)($config['name'] ?? '');
        $profile->filterBy = (string)($config['filterBy'] ?? '');
        $profile->includeFields = (string)($config['includeFields'] ?? '');
        $profile->excludeFields = (string)($config['excludeFields'] ?? '');
        $profile->expiresIn = (int)($config['expiresIn'] ?? 0);
        $profile->limitHits = (int)($config['limitHits'] ?? 0);

        return $profile;
    }

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
