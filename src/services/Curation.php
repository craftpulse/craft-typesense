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
use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\models\Settings;
use craftpulse\typesense\Typesense;
use Throwable;

/**
 * Curation service, dual-shape behind one interface.
 *
 * On v28/v29 curation is per-collection overrides (via the SDK). On v30.2+ it is
 * global curation sets (via raw HTTP). The shape is chosen from ServerCapabilities,
 * so callers see identical behavior. managedBy resolves from the settings default,
 * overridable per collection (config wins); config-managed rules are seeded, CP-
 * managed rules are left to the control panel (the manager UI arrives in P9).
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class Curation extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * @param Collection $collection
     * @return string
     * @author CraftPulse
     */
    public function getManagedBy(Collection $collection): string
    {
        return $collection->getCurationManagedBy() ?? $this->_settings()->curationManagedBy;
    }

    /**
     * @param Collection $collection
     * @return bool
     * @author CraftPulse
     */
    public function isConfigOverridden(Collection $collection): bool
    {
        return $collection->getCurationManagedBy() !== null;
    }

    /**
     * Seeds a collection's config-declared curation rules, when config-managed.
     *
     * @param Collection $collection
     * @return void
     * @author CraftPulse
     */
    public function seedFromConfig(Collection $collection): void
    {
        if ($this->getManagedBy($collection) !== Settings::MANAGED_BY_CONFIG) {
            return;
        }

        $rules = $collection->getCurationRules();

        if ($rules === []) {
            return;
        }

        if ($this->_useSets()) {
            $items = [];

            foreach ($rules as $index => $rule) {
                $id = $rule['id'] ?? ($collection->getName() . '-' . $index);
                unset($rule['id']);
                $items[] = ['id' => (string)$id] + $rule;
            }

            $this->_setRequest('PUT', $this->_setName($collection), ['items' => $items]);

            return;
        }

        foreach ($rules as $index => $rule) {
            $id = $rule['id'] ?? ($collection->getName() . '-' . $index);
            unset($rule['id']);
            $this->upsert($collection, (string)$id, $rule);
        }
    }

    /**
     * Upserts one curation rule (dual-shape).
     *
     * @param Collection $collection
     * @param string $id
     * @param array<string, mixed> $rule
     * @return void
     * @author CraftPulse
     */
    public function upsert(Collection $collection, string $id, array $rule): void
    {
        if ($this->_useSets()) {
            $this->_setRequest('PUT', $this->_setName($collection) . '/items/' . $id, $rule + ['id' => $id]);

            return;
        }

        $client = Typesense::$plugin->getClient()->client();

        if ($client === null) {
            return;
        }

        try {
            $client->collections[$this->_target($collection)]->overrides->upsert($id, $rule);
        } catch (Throwable $e) {
            Craft::error("Could not upsert curation {$id} on {$collection->getName()}: {$e->getMessage()}", 'typesense');
        }
    }

    /**
     * Lists a collection's curation rules (dual-shape).
     *
     * @param Collection $collection
     * @return array<int, array<string, mixed>>
     * @author CraftPulse
     */
    public function all(Collection $collection): array
    {
        if ($this->_useSets()) {
            return $this->_setRequest('GET', $this->_setName($collection))['items'] ?? [];
        }

        $client = Typesense::$plugin->getClient()->client();

        if ($client === null) {
            return [];
        }

        try {
            return $client->collections[$this->_target($collection)]->overrides->retrieve()['overrides'] ?? [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Deletes all of a collection's curation rules (dual-shape).
     *
     * @param Collection $collection
     * @return void
     * @author CraftPulse
     */
    public function clear(Collection $collection): void
    {
        if ($this->_useSets()) {
            $this->_setRequest('DELETE', $this->_setName($collection));

            return;
        }

        $client = Typesense::$plugin->getClient()->client();

        if ($client === null) {
            return;
        }

        foreach ($this->all($collection) as $rule) {
            if (isset($rule['id'])) {
                try {
                    $client->collections[$this->_target($collection)]->overrides[(string)$rule['id']]->delete();
                } catch (Throwable) {
                    // already gone
                }
            }
        }
    }

    /**
     * @return bool
     * @author CraftPulse
     */
    public function usesSets(): bool
    {
        return $this->_useSets();
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
     * @return bool
     * @author CraftPulse
     */
    private function _useSets(): bool
    {
        return Typesense::$plugin->getClient()->getServerCapabilities()?->curationSets() ?? false;
    }

    /**
     * @param Collection $collection
     * @return string
     * @author CraftPulse
     */
    private function _target(Collection $collection): string
    {
        return Typesense::$plugin->getCollectionRegistry()->resolveName(
            $collection,
            Craft::$app->getSites()->getPrimarySite()->id,
        );
    }

    /**
     * @param Collection $collection
     * @return string
     * @author CraftPulse
     */
    private function _setName(Collection $collection): string
    {
        return $this->_target($collection) . '_curation';
    }

    /**
     * Makes a raw HTTP request to the curation_sets API (v30.2+).
     *
     * @param string $method
     * @param string $path
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     * @author CraftPulse
     */
    private function _setRequest(string $method, string $path, ?array $body = null): array
    {
        return Typesense::$plugin->getClient()->request($method, '/curation_sets/' . $path, $body);
    }
}
