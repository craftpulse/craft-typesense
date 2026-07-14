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
use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\events\RegisterCollectionsEvent;
use craftpulse\typesense\models\Settings;
use craftpulse\typesense\Typesense;

/**
 * Collections registry.
 *
 * Merges the fluent-config collections (authored in config/typesense.php) with
 * collections registered by other plugins (EVENT_REGISTER_COLLECTIONS) into one
 * runtime map keyed by logical name. On a name collision the config file wins and
 * the name is flagged as overridden, so the CP can render the "overridden by the
 * config file" notice. Collection names are resolved to their Typesense targets
 * through the client's prefix resolver, the single source of truth for the
 * environment prefix.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class Collections extends Component
{
    // Constants
    // =========================================================================

    /**
     * @event RegisterCollectionsEvent The event for registering collection definitions.
     */
    public const EVENT_REGISTER_COLLECTIONS = 'registerCollections';

    // Public Methods
    // =========================================================================

    /**
     * Returns the merged collection map, keyed by logical name. Config-file
     * collections take precedence over event-registered ones.
     *
     * @return array<string, Collection>
     * @author CraftPulse
     */
    public function getAll(): array
    {
        $collections = [];

        foreach ($this->_eventCollections() as $collection) {
            $collections[$collection->getName()] = $collection;
        }

        // Config-file collections win over event-registered ones.
        foreach ($this->_configCollections() as $collection) {
            $collections[$collection->getName()] = $collection;
        }

        return $collections;
    }

    /**
     * Returns a collection by logical name, or null.
     *
     * @param string $name
     * @return Collection|null
     * @author CraftPulse
     */
    public function get(string $name): ?Collection
    {
        return $this->getAll()[$name] ?? null;
    }

    /**
     * Returns the logical names of collections where a config-file collection
     * displaced an event-registered one (the overridden-by-config notice state).
     *
     * @return array<int, string>
     * @author CraftPulse
     */
    public function getOverriddenNames(): array
    {
        $eventNames = [];

        foreach ($this->_eventCollections() as $collection) {
            $eventNames[$collection->getName()] = true;
        }

        $overridden = [];

        foreach ($this->_configCollections() as $collection) {
            if (isset($eventNames[$collection->getName()])) {
                $overridden[] = $collection->getName();
            }
        }

        return $overridden;
    }

    /**
     * Resolves a collection's Typesense target name, applying the environment
     * prefix via the client's resolver.
     *
     * @param Collection|string $collection
     * @return string
     * @author CraftPulse
     */
    public function resolveName(Collection|string $collection): string
    {
        $name = $collection instanceof Collection ? $collection->getName() : $collection;

        return Typesense::$plugin->getClient()->prefixedCollectionName($name);
    }

    /**
     * Returns the Typesense create-collection payload for a collection, with the
     * name resolved to its prefixed target.
     *
     * @param Collection $collection
     * @return array<string, mixed>
     * @author CraftPulse
     */
    public function getCreateSchema(Collection $collection): array
    {
        $schema = $collection->toSchema();
        $schema['name'] = $this->resolveName($collection);

        return $schema;
    }

    // Private Methods
    // =========================================================================

    /**
     * The fluent-config collections declared in the settings (config file),
     * filtered to Collection builders.
     *
     * @return array<int, Collection>
     * @author CraftPulse
     */
    private function _configCollections(): array
    {
        /** @var Settings $settings */
        $settings = Typesense::$plugin->getSettings();

        return array_values(array_filter(
            $settings->collections,
            static fn(mixed $collection): bool => $collection instanceof Collection,
        ));
    }

    /**
     * The collections registered by other plugins via EVENT_REGISTER_COLLECTIONS.
     *
     * @return array<int, Collection>
     * @author CraftPulse
     */
    private function _eventCollections(): array
    {
        $event = new RegisterCollectionsEvent();

        if ($this->hasEventHandlers(self::EVENT_REGISTER_COLLECTIONS)) {
            $this->trigger(self::EVENT_REGISTER_COLLECTIONS, $event);
        }

        return array_values(array_filter(
            $event->collections,
            static fn(mixed $collection): bool => $collection instanceof Collection,
        ));
    }
}
