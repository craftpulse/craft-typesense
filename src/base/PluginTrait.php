<?php

namespace percipiolondon\typesense\base;

use nystudio107\pluginvite\services\VitePluginService;
use percipiolondon\typesense\assetbundles\typesense\TypesenseAsset;
use percipiolondon\typesense\services\CollectionService;
use percipiolondon\typesense\services\TypesenseService;
use percipiolondon\typesense\Typesense;

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
