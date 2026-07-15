<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\base;

use craftpulse\typesense\services\Client;
use craftpulse\typesense\services\Collections;
use craftpulse\typesense\services\Compatibility;
use craftpulse\typesense\services\Compiler;
use craftpulse\typesense\services\ConfigGenerator;
use craftpulse\typesense\services\Curation;
use craftpulse\typesense\services\CurationIndex;
use craftpulse\typesense\services\Dictionaries;
use craftpulse\typesense\services\Documents;
use craftpulse\typesense\services\Drift;
use craftpulse\typesense\services\Inspector;
use craftpulse\typesense\services\Keys;
use craftpulse\typesense\services\LegacyConfig;
use craftpulse\typesense\services\ManagedCollections;
use craftpulse\typesense\services\MappingSources;
use craftpulse\typesense\services\Notifications;
use craftpulse\typesense\services\Presets;
use craftpulse\typesense\services\Schema;
use craftpulse\typesense\services\Search;
use craftpulse\typesense\services\Sync;
use craftpulse\typesense\services\Synonyms;
use craftpulse\typesense\Typesense;
use yii\base\InvalidConfigException;

/**
 * Wires the plugin's service components and exposes a typed getter for each.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
trait PluginTrait
{
    // Static Properties
    // =========================================================================

    /**
     * @var Typesense The plugin instance.
     */
    public static Typesense $plugin;

    // Public Methods
    // =========================================================================

    /**
     * @return Client
     * @throws InvalidConfigException
     * @author CraftPulse
     */
    public function getClient(): Client
    {
        /** @var Client $client */
        $client = $this->get('client');

        return $client;
    }

    /**
     * Backwards-compatible alias of [[getCollections()]].
     *
     * @return Collections
     * @throws InvalidConfigException
     * @author CraftPulse
     */
    public function getCollectionRegistry(): Collections
    {
        return $this->getCollections();
    }

    /**
     * The canonical collections registry accessor.
     *
     * @return Collections
     * @throws InvalidConfigException
     * @author CraftPulse
     */
    public function getCollections(): Collections
    {
        /** @var Collections $collections */
        $collections = $this->get('collections');

        return $collections;
    }

    /**
     * @return Compatibility
     * @throws InvalidConfigException
     * @author CraftPulse
     */
    public function getCompatibility(): Compatibility
    {
        /** @var Compatibility $compatibility */
        $compatibility = $this->get('compatibility');

        return $compatibility;
    }

    /**
     * @return Compiler
     * @throws InvalidConfigException
     * @author CraftPulse
     */
    public function getCompiler(): Compiler
    {
        /** @var Compiler $compiler */
        $compiler = $this->get('compiler');

        return $compiler;
    }

    /**
     * @return ConfigGenerator
     * @throws InvalidConfigException
     * @author CraftPulse
     */
    public function getConfigGenerator(): ConfigGenerator
    {
        /** @var ConfigGenerator $configGenerator */
        $configGenerator = $this->get('configGenerator');

        return $configGenerator;
    }

    /**
     * @return Curation
     * @throws InvalidConfigException
     * @author CraftPulse
     */
    public function getCuration(): Curation
    {
        /** @var Curation $curation */
        $curation = $this->get('curation');

        return $curation;
    }

    /**
     * @return CurationIndex
     * @throws InvalidConfigException
     * @author CraftPulse
     */
    public function getCurationIndex(): CurationIndex
    {
        /** @var CurationIndex $curationIndex */
        $curationIndex = $this->get('curationIndex');

        return $curationIndex;
    }

    /**
     * @return Dictionaries
     * @throws InvalidConfigException
     * @author CraftPulse
     */
    public function getDictionaries(): Dictionaries
    {
        /** @var Dictionaries $dictionaries */
        $dictionaries = $this->get('dictionaries');

        return $dictionaries;
    }

    /**
     * @return Documents
     * @throws InvalidConfigException
     * @author CraftPulse
     */
    public function getDocuments(): Documents
    {
        /** @var Documents $documents */
        $documents = $this->get('documents');

        return $documents;
    }

    /**
     * @return Drift
     * @throws InvalidConfigException
     * @author CraftPulse
     */
    public function getDrift(): Drift
    {
        /** @var Drift $drift */
        $drift = $this->get('drift');

        return $drift;
    }

    /**
     * @return Inspector
     * @throws InvalidConfigException
     * @author CraftPulse
     */
    public function getInspector(): Inspector
    {
        /** @var Inspector $inspector */
        $inspector = $this->get('inspector');

        return $inspector;
    }

    /**
     * @return Keys
     * @throws InvalidConfigException
     * @author CraftPulse
     */
    public function getKeys(): Keys
    {
        /** @var Keys $keys */
        $keys = $this->get('keys');

        return $keys;
    }

    /**
     * @return LegacyConfig
     * @throws InvalidConfigException
     * @author CraftPulse
     */
    public function getLegacyConfig(): LegacyConfig
    {
        /** @var LegacyConfig $legacyConfig */
        $legacyConfig = $this->get('legacyConfig');

        return $legacyConfig;
    }

    /**
     * @return ManagedCollections
     * @throws InvalidConfigException
     * @author CraftPulse
     */
    public function getManagedCollections(): ManagedCollections
    {
        /** @var ManagedCollections $managedCollections */
        $managedCollections = $this->get('managedCollections');

        return $managedCollections;
    }

    /**
     * @return MappingSources
     * @throws InvalidConfigException
     * @author CraftPulse
     */
    public function getMappingSources(): MappingSources
    {
        /** @var MappingSources $mappingSources */
        $mappingSources = $this->get('mappingSources');

        return $mappingSources;
    }

    /**
     * @return Notifications
     * @throws InvalidConfigException
     * @author CraftPulse
     */
    public function getNotifications(): Notifications
    {
        /** @var Notifications $notifications */
        $notifications = $this->get('notifications');

        return $notifications;
    }

    /**
     * @return Presets
     * @throws InvalidConfigException
     * @author CraftPulse
     */
    public function getPresets(): Presets
    {
        /** @var Presets $presets */
        $presets = $this->get('presets');

        return $presets;
    }

    /**
     * @return Schema
     * @throws InvalidConfigException
     * @author CraftPulse
     */
    public function getSchema(): Schema
    {
        /** @var Schema $schema */
        $schema = $this->get('schema');

        return $schema;
    }

    /**
     * @return Search
     * @throws InvalidConfigException
     * @author CraftPulse
     */
    public function getSearch(): Search
    {
        /** @var Search $search */
        $search = $this->get('search');

        return $search;
    }

    /**
     * @return Sync
     * @throws InvalidConfigException
     * @author CraftPulse
     */
    public function getSync(): Sync
    {
        /** @var Sync $sync */
        $sync = $this->get('sync');

        return $sync;
    }

    /**
     * @return Synonyms
     * @throws InvalidConfigException
     * @author CraftPulse
     */
    public function getSynonyms(): Synonyms
    {
        /** @var Synonyms $synonyms */
        $synonyms = $this->get('synonyms');

        return $synonyms;
    }

    /**
     * @return Typesense
     * @throws InvalidConfigException
     * @author CraftPulse
     */
    public function getTypesense(): Typesense
    {
        /** @var Typesense $typesense */
        $typesense = $this->get('typesense');

        return $typesense;
    }

    // Private Methods
    // =========================================================================

    /**
     * Registers the plugin's service components.
     *
     * @return void
     * @author CraftPulse
     */
    private function _registerComponents(): void
    {
        $this->setComponents([
            'typesense' => Typesense::class,
            'collections' => Collections::class,
            'compiler' => Compiler::class,
            'schema' => Schema::class,
            'documents' => Documents::class,
            'sync' => Sync::class,
            'legacyConfig' => LegacyConfig::class,
            'configGenerator' => ConfigGenerator::class,
            'compatibility' => Compatibility::class,
            'keys' => Keys::class,
            'drift' => Drift::class,
            'inspector' => Inspector::class,
            'managedCollections' => ManagedCollections::class,
            'mappingSources' => MappingSources::class,
            'notifications' => Notifications::class,
            'synonyms' => Synonyms::class,
            'curation' => Curation::class,
            'curationIndex' => CurationIndex::class,
            'presets' => Presets::class,
            'dictionaries' => Dictionaries::class,
            'search' => Search::class,
            'client' => Client::class,
        ]);
    }
}
