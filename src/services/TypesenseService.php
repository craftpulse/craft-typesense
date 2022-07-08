<?php
/**
 * Typesense plugin for Craft CMS 3.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://percipio.london
 * @copyright Copyright (c) 2021 percipiolondon
 */

namespace percipiolondon\typesense\services;

use Craft;
use craft\base\Component;
use craft\helpers\App;
use craft\helpers\StringHelper;

use percipiolondon\typesense\Typesense;
use Typesense\Client as TypesenseClient;

/**
 * TypesenseService Service
 *
 * All of your plugin’s business logic should go in services, including saving data,
 * retrieving data, etc. They provide APIs that your controllers, template variables,
 * and other plugins can interact with.
 *
 * https://craftcms.com/docs/plugins/services
 *
 * @author    percipiolondon
 * @package   Typesense
 * @since     1.0.0
 */
class TypesenseService extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * @return TypesenseClient|null
     * @throws \craft\errors\MissingComponentException
     */
    public function client(): TypesenseClient
    {
        $client = null;

        try {
            if (Typesense::$settings->serverType === 'server' && App::parseEnv(Typesense::$settings->apiKey)) {
                $client = new TypesenseClient([
                    'api_key' => App::parseEnv(Typesense::$settings->apiKey),
                    'nodes' => [
                        [
                            'host' => App::parseEnv(Typesense::$settings->server),
                            'port' => App::parseEnv(Typesense::$settings->port),
                            'protocol' => App::parseEnv(Typesense::$settings->protocol),
                        ],
                    ],
                    'connection_timeout_seconds' => 2,
                ]);
            } else if (Typesense::$settings->serverType === 'cluster' && App::parseEnv(Typesense::$settings->apiKey)) {
                $client = new TypesenseClient([
                    'api_key' => App::parseEnv(Typesense::$settings->apiKey),
                    'nearest_node' => $this->_createNearestNodes(), // This is the special Nearest Node hostname that you'll see in the Typesense Cloud dashboard if you turn on Search Delivery Network
                    'nodes' => $this->_createNodes(),
                    'connection_timeout_seconds' => 2,
                ]);
            } else {
                if(Craft::$app->getRequest()->getIsConsoleRequest()) {
                    Craft::$app->getSession()->setNotice(Craft::t('typesense', 'Please provide your typesense API key in the settings to get started'));
                }
                Craft::error($e->getMessage(), Craft::t('typesense', 'Please provide your typesense API key in the settings to get started'));
            }

        } catch (\Exception $e) {
            if(Craft::$app->getRequest()->getIsConsoleRequest()) {
                Craft::$app->getSession()->setNotice(Craft::t('typesense', 'There was an error with the Typesense Client Connection, check the logs'));
            }
            Craft::error($e->getMessage(), __METHOD__);
        }

        return $client;
    }

    /**
     * @return array|null
     */
    private function _createNearestNodes(): ?array
    {
        $nearest = App::parseEnv(Typesense::$settings->nearestNode);

        if ($nearest) {
            return [
                'host' => $nearest,
                'port' => App::parseEnv(Typesense::$settings->clusterPort),
                'protocol' => 'https'
            ];
        }

        return null;
    }

    /**
     * @param Settings $settings
     * @return array
     */
    private function _createNodes(): array
    {
        $typesenseNodes = explode(';', App::parseEnv(Typesense::$settings->cluster));
        $nodes = [];

        foreach ($typesenseNodes as $node) {
            $nodes[] = [
                'host' => $node,
                'port' => App::parseEnv(Typesense::$settings->clusterPort),
                'protocol' => 'https', //App::parseEnv($this::$settings->protocol),
            ];
        }

        return $nodes;
    }
}
