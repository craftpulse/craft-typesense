<?php

namespace craftpulse\typesense\base;

use craftpulse\typesense\assetbundles\typesense\TypesenseAsset;
use craftpulse\typesense\services\Client;
use craftpulse\typesense\services\Collections;
use craftpulse\typesense\services\Compatibility;
use craftpulse\typesense\services\ConfigGenerator;
use craftpulse\typesense\services\Curation;
use craftpulse\typesense\services\Dictionaries;
use craftpulse\typesense\services\Documents;
use craftpulse\typesense\services\Drift;
use craftpulse\typesense\services\Keys;
use craftpulse\typesense\services\LegacyConfig;
use craftpulse\typesense\services\Notifications;
use craftpulse\typesense\services\Presets;
use craftpulse\typesense\services\Schema;
use craftpulse\typesense\services\Search;
use craftpulse\typesense\services\Sync;
use craftpulse\typesense\services\Synonyms;
use craftpulse\typesense\Typesense;
use nystudio107\pluginvite\services\VitePluginService;

trait PluginTrait
{
    public static Typesense $plugin;

    // Public Methods
    // =========================================================================
    public function getTypesense(): Typesense
    {
        return $this->get('typesense');
    }

    public function getClient(): Client
    {
        return $this->get('client');
    }

    public function getCollectionRegistry(): Collections
    {
        return $this->get('collectionRegistry');
    }

    public function getSchema(): Schema
    {
        return $this->get('schema');
    }

    public function getDocuments(): Documents
    {
        return $this->get('documents');
    }

    public function getSync(): Sync
    {
        return $this->get('sync');
    }

    public function getLegacyConfig(): LegacyConfig
    {
        return $this->get('legacyConfig');
    }

    public function getConfigGenerator(): ConfigGenerator
    {
        return $this->get('configGenerator');
    }

    public function getCompatibility(): Compatibility
    {
        return $this->get('compatibility');
    }

    public function getKeys(): Keys
    {
        return $this->get('keys');
    }

    public function getDrift(): Drift
    {
        return $this->get('drift');
    }

    public function getNotifications(): Notifications
    {
        return $this->get('notifications');
    }

    public function getSynonyms(): Synonyms
    {
        return $this->get('synonyms');
    }

    public function getCuration(): Curation
    {
        return $this->get('curation');
    }

    public function getPresets(): Presets
    {
        return $this->get('presets');
    }

    public function getDictionaries(): Dictionaries
    {
        return $this->get('dictionaries');
    }

    public function getSearch(): Search
    {
        return $this->get('search');
    }

    public function getVite(): VitePluginService
    {
        return $this->get('vite');
    }

    // Private Methods
    // =========================================================================

    private function _registerComponents(): void
    {
        $this->setComponents([
            'typesense' => Typesense::class,
            'collectionRegistry' => Collections::class,
            'schema' => Schema::class,
            'documents' => Documents::class,
            'sync' => Sync::class,
            'legacyConfig' => LegacyConfig::class,
            'configGenerator' => ConfigGenerator::class,
            'compatibility' => Compatibility::class,
            'keys' => Keys::class,
            'drift' => Drift::class,
            'notifications' => Notifications::class,
            'synonyms' => Synonyms::class,
            'curation' => Curation::class,
            'presets' => Presets::class,
            'dictionaries' => Dictionaries::class,
            'search' => Search::class,
            'client' => Client::class,
            // Register the vite service
            'vite' => [
                'class' => VitePluginService::class,
                'assetClass' => TypesenseAsset::class,
                'useDevServer' => true,
                'devServerPublic' => 'http://localhost:3001',
                'serverPublic' => 'http://localhost:8001',
                'errorEntry' => '/src/js/typesense.ts',
                'devServerInternal' => 'http://craft-typesense-buildchain:3001',
                'checkDevServer' => true,
            ],
        ]);
    }
}
