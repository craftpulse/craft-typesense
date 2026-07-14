<?php

namespace craftpulse\typesense\base;

use craftpulse\typesense\assetbundles\typesense\TypesenseAsset;
use craftpulse\typesense\services\Client;
use craftpulse\typesense\services\Collections;
use craftpulse\typesense\services\CollectionService;
use craftpulse\typesense\services\Documents;
use craftpulse\typesense\services\Schema;
use craftpulse\typesense\services\Sync;
use craftpulse\typesense\services\SynonymService;
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

    public function getCollections(): CollectionService
    {
        return $this->get('collections');
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
            'collections' => CollectionService::class,
            'collectionRegistry' => Collections::class,
            'schema' => Schema::class,
            'documents' => Documents::class,
            'sync' => Sync::class,
            'synonyms' => SynonymService::class,
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
