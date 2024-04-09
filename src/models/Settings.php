<?php
/**
 * Typesense plugin for Craft CMS 4.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://percipio.london
 * @copyright Copyright (c) 2021 percipiolondon
 */

namespace percipiolondon\typesense\models;

use craft\base\Model;
use craft\behaviors\EnvAttributeParserBehavior;

/**
 * Typesense Settings Model
 *
 * @author    percipiolondon
 * @package   Typesense
 * @since     1.0.0
 */
class Settings extends Model
{
    // Public Properties
    // =========================================================================
    /**
     * @const int
     * @var string
     */
    public const TYPESENSE_SERVER = 'server';

    /**
     * @const int
     * @var string
     */
    public const TYPESENSE_CLUSTER = 'cluster';

    /**
     * @const int
     * @var string
     */
    public const TYPESENSE_CLOUD = 'cloud';

    /**
     * @var string The public-facing name of the plugin
     */
    public string $pluginName = 'Typesense';

    /**
     * @var string which type of Typesense connection needs to be used.
     *
     * - `self::TYPESENSE_SERVER`: Use a single server connection
     * - `self::TYPESENSE_CLUSTER`: Use a cluster server connection
     * - `self::TYPESENSE_CLOUD`: Use the Typesense cloud connection
     */
    public string $serverType = self::TYPESENSE_SERVER;

    /**
     * @var string|null The API cluster endpoint where Typesense connects to.
     */
    public ?string $cluster = '0.0.0.0;0.0.0.1;0.0.0.2';

    /**
     * @var string|null The API cluster endpoint where Typesense connects to.
     */
    public ?string $nearestNode = null;

    /**
     * @var string|null The API port which the Typesense cluster listens to.
     */
    public ?string $clusterPort = '443';

    /**
     * @var string|null The API endpoint where Typesense connects to.
     */
    public ?string $server = '0.0.0.0';

    /**
     * @var string|null The API port which Typesense listens to.
     */
    public ?string $port = '443';

    /**
     * @var string|null The API port which Typesense listens to.
     */
    public ?string $protocol = 'http';

    /**
     * @var string|null The Admin API key.
     */
    public ?string $apiKey = null;

    /**
     * @var string|null The search-only API key.
     */
    public ?string $searchOnlyApiKey = null;

    /**
     * @var array Provide an array of collections that needs to be added.
     */
    public array $collections = [];
    

    // Public Methods
    // =========================================================================
    public function getCollections(): array {
        return $this->collections;
    }

    public function getPluginName(): string {
        return $this->pluginName;
    }

    public function serverType(): string {
        return $this->serverType;
    }

    public function getCluster(): string {
        return $this->cluster;
    }

    public function getNearestNode(): string {
        return $this->nearestNode;
    }

    public function getClusterPort(): string {
        return $this->clusterPort;
    }

    public function getServer(): string {
        return $this->server;
    }

    public function getPort(): string {
        return $this->port;
    }

    public function getProtocol(): string {
        return $this->protocol;
    }

    public function getApiKey(): string {
        return $this->apiKey;
    }

    public function getSearchOnlyApiKey(): string {
        return $this->searchOnlyApiKey;
    }


    /**
     * @inheritdoc
     */
    protected function defineBehaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => ['apiKey', 'cluster', 'nearestNode', 'clusterPort', 'port', 'protocol', 'searchOnlyApiKey', 'server'],
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return [
            [['apiKey', 'cluster', 'clusterPort', 'nearestNode', 'pluginName', 'port', 'protocol', 'searchOnlyApiKey', 'server'], 'string'],
            [['apiKey', 'serverType'], 'required'],
            [['serverType'], 'in', 'range' => [
                self::TYPESENSE_SERVER,
                self::TYPESENSE_CLUSTER,
                self::TYPESENSE_CLOUD,
            ]],
            [['cluster', 'clusterPort'], 'required', 'when' => fn($model) => $model->serverType === self::TYPESENSE_CLUSTER],
            [['port', 'server'], 'required', 'when' => fn($model) => $model->serverType === self::TYPESENSE_SERVER],
        ];
    }
}
