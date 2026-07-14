<?php

namespace craftpulse\typesense\base;

use craftpulse\typesense\assetbundles\typesense\TypesenseAsset;
use craftpulse\typesense\services\CollectionService;
use craftpulse\typesense\services\SynonymService;
use craftpulse\typesense\services\TypesenseService;
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

    public function getClient(): TypesenseService
    {
        return $this->get('client');
    }

    public function getCollections(): CollectionService
    {
        return $this->get('collections');
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
            'synonyms' => SynonymService::class,
            'client' => TypesenseService::class,
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
