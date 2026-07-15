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
 * Synonyms service, dual-shape behind one interface.
 *
 * On v28/v29 synonyms are per-collection (via the SDK). On v30.2+ they are global
 * synonym sets (via raw HTTP, since the bundled SDK predates them); the search
 * path passes `synonym_sets=<name>`. The shape is chosen from ServerCapabilities,
 * so callers see identical behavior on both. managedBy resolves from the settings
 * default, overridable per collection in fluent config (config wins, surfacing the
 * override-notice state); config-managed synonyms are seeded, CP-managed are left
 * to the control panel.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class Synonyms extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Resolves who owns a collection's synonyms: the per-collection override, or
     * the settings default.
     *
     * @param Collection $collection
     * @return string
     * @author CraftPulse
     */
    public function getManagedBy(Collection $collection): string
    {
        return $collection->getSynonymsManagedBy() ?? $this->_settings()->synonymsManagedBy;
    }

    /**
     * Whether the collection explicitly overrides the managedBy default in config
     * (the "overridden by the config file" notice state).
     *
     * @param Collection $collection
     * @return bool
     * @author CraftPulse
     */
    public function isConfigOverridden(Collection $collection): bool
    {
        return $collection->getSynonymsManagedBy() !== null;
    }

    /**
     * Seeds a collection's config-declared synonyms, when it is config-managed.
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

        $definitions = $collection->getSynonymDefinitions();

        if ($definitions === []) {
            return;
        }

        if ($this->_useSets()) {
            $items = [];

            foreach ($definitions as $index => $definition) {
                $id = $definition['id'] ?? ($collection->getName() . '-' . $index);
                unset($definition['id']);
                $items[] = ['id' => (string)$id] + $definition;
            }

            $this->_setRequest('PUT', $this->_setName($collection), ['items' => $items]);

            return;
        }

        foreach ($definitions as $index => $definition) {
            $id = $definition['id'] ?? ($collection->getName() . '-' . $index);
            unset($definition['id']);
            $this->upsert($collection, (string)$id, $definition);
        }
    }

    /**
     * Upserts one synonym for a collection (dual-shape).
     *
     * @param Collection $collection
     * @param string $id
     * @param array<string, mixed> $data
     * @return void
     * @author CraftPulse
     */
    public function upsert(Collection $collection, string $id, array $data): void
    {
        if ($this->_useSets()) {
            // A per-item PUT does not auto-create the set on 30.2, so upsert
            // reads the whole set, replaces the target item, and writes it back,
            // which also creates the set when it is absent.
            $items = array_values(array_filter(
                $this->all($collection),
                static fn(array $item): bool => (string)($item['id'] ?? '') !== $id,
            ));
            $items[] = $data + ['id' => $id];

            $this->_setRequest('PUT', $this->_setName($collection), ['items' => $items]);

            return;
        }

        $client = Typesense::$plugin->getClient()->client();

        if ($client === null) {
            return;
        }

        try {
            $client->collections[$this->_target($collection)]->synonyms->upsert($id, $data);
        } catch (Throwable $e) {
            Craft::error("Could not upsert synonym {$id} on {$collection->getName()}: {$e->getMessage()}", 'typesense');
        }
    }

    /**
     * Deletes one synonym from a collection (dual-shape).
     *
     * @param Collection $collection
     * @param string $id
     * @return void
     * @author CraftPulse
     */
    public function deleteOne(Collection $collection, string $id): void
    {
        if ($this->_useSets()) {
            $this->_setRequest('DELETE', $this->_setName($collection) . '/items/' . $id);

            return;
        }

        $client = Typesense::$plugin->getClient()->client();

        if ($client === null) {
            return;
        }

        try {
            $client->collections[$this->_target($collection)]->synonyms[$id]->delete();
        } catch (Throwable) {
            // already gone
        }
    }

    /**
     * Lists a collection's synonyms (dual-shape), returned as a normalized list.
     *
     * @param Collection $collection
     * @return array<int, array<string, mixed>>
     * @author CraftPulse
     */
    public function all(Collection $collection): array
    {
        if ($this->_useSets()) {
            $response = $this->_setRequest('GET', $this->_setName($collection));

            return $response['items'] ?? [];
        }

        $client = Typesense::$plugin->getClient()->client();

        if ($client === null) {
            return [];
        }

        try {
            return $client->collections[$this->_target($collection)]->synonyms->retrieve()['synonyms'] ?? [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Deletes all of a collection's synonyms (dual-shape).
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

        foreach ($this->all($collection) as $synonym) {
            if (isset($synonym['id'])) {
                try {
                    $client->collections[$this->_target($collection)]->synonyms[(string)$synonym['id']]->delete();
                } catch (Throwable) {
                    // already gone
                }
            }
        }
    }

    /**
     * The synonym_sets set name for a collection (v30.2+), for the search param.
     *
     * @param Collection $collection
     * @return string
     * @author CraftPulse
     */
    public function setName(Collection $collection): string
    {
        return $this->_setName($collection);
    }

    /**
     * Whether the set shape (v30.2+) is in use.
     *
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
        return Typesense::$plugin->getClient()->getServerCapabilities()?->synonymSets() ?? false;
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
        return $this->_target($collection) . '_synonyms';
    }

    /**
     * Makes a raw HTTP request to the synonym_sets API (v30.2+).
     *
     * @param string $method
     * @param string $path
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>
     * @author CraftPulse
     */
    private function _setRequest(string $method, string $path, ?array $body = null): array
    {
        return Typesense::$plugin->getClient()->request($method, '/synonym_sets/' . $path, $body);
    }
}
