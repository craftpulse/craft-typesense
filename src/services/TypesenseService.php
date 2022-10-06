<?php
/**
 * Typesense plugin for Craft CMS 4.x
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
     * @throws \craft\errors\MissingComponentException
     */
    public function client(): ?TypesenseClient
    {
        $client = null;

        try {
            if (Typesense::$plugin->getSettings()->serverType === 'server' && App::parseEnv(Typesense::$plugin->getSettings()->apiKey)) {
                $client = new TypesenseClient([
                    'api_key' => App::parseEnv(Typesense::$plugin->getSettings()->apiKey),
                    'nodes' => [
                        [
                            'host' => App::parseEnv(Typesense::$plugin->getSettings()->server),
                            'port' => App::parseEnv(Typesense::$plugin->getSettings()->port),
                            'protocol' => App::parseEnv(Typesense::$plugin->getSettings()->protocol),
                        ],
                    ],
                    'connection_timeout_seconds' => 2,
                ]);
            } elseif (Typesense::$plugin->getSettings()->serverType === 'cluster' && App::parseEnv(Typesense::$plugin->getSettings()->apiKey)) {
                $client = new TypesenseClient([
                    'api_key' => App::parseEnv(Typesense::$plugin->getSettings()->apiKey),
                    'nearest_node' => $this->_createNearestNodes(), // This is the special Nearest Node hostname that you'll see in the Typesense Cloud dashboard if you turn on Search Delivery Network
                    'nodes' => $this->_createNodes(),
                    'connection_timeout_seconds' => 2,
                ]);
            } else {
                if (Craft::$app->getRequest()->getIsConsoleRequest()) {
                    Craft::$app->getSession()->setNotice(Craft::t('typesense', 'Please provide your typesense API key in the settings to get started'));
                }

                Craft::error(Craft::t('typesense', 'Please provide your typesense API key in the settings to get started'));
            }
        } catch (\Exception $exception) {
            if (Craft::$app->getRequest()->getIsConsoleRequest()) {
                Craft::$app->getSession()->setNotice(Craft::t('typesense', 'There was an error with the Typesense Client Connection, check the logs'));
            }

            Craft::error($exception->getMessage(), __METHOD__);
        }

        return $client;
    }

    private function _createNearestNodes(): ?array
    {
        $nearest = App::parseEnv(Typesense::$plugin->getSettings()->nearestNode);

        if ($nearest) {
            return [
                'host' => $nearest,
                'port' => App::parseEnv(Typesense::$plugin->getSettings()->clusterPort),
                'protocol' => 'https',
            ];
        }

        return null;
    }

    /**
     * @param Settings $settings
     */
    private function _createNodes(): array
    {
        $typesenseNodes = explode(';', App::parseEnv(Typesense::$plugin->getSettings()->cluster));
        $nodes = [];

        foreach ($typesenseNodes as $node) {
            $nodes[] = [
                'host' => $node,
                'port' => App::parseEnv(Typesense::$plugin->getSettings()->clusterPort),
                'protocol' => 'https', //App::parseEnv($this::$settings->protocol),
            ];
        }

        return $nodes;
    }
}
