<?php
/**
 * Typesense plugin for Craft CMS 3.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://percipio.london
 * @copyright Copyright (c) 2021 percipiolondon
 */

namespace percipiolondon\typesense\models;

use percipiolondon\typesense\Typesense;

use Craft;
use craft\base\Model;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\validators\ArrayValidator;

use yii\behaviors\AttributeTypecastBehavior;

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
     */
    public const TYPESENSE_SERVER = 'server';

    /**
     * @const int
     */
    public const TYPESENSE_CLUSTER = 'cluster';

    /**
     * @const int
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
     * @var string|null The API port which the Typesense cluster listens to.
     */
    public ?string $clusterPort = '8108';

    /**
     * @var string|null The API endpoint where Typesense connects to.
     */
    public ?string $server = '0.0.0.0';

    /**
     * @var string|null The API port which Typesense listens to.
     */
    public ?string $port = '8108';

    /**
     * @var string The Admin API key.
     */
    public string $apiKey = '';

    /**
     * @var string|null The search-only API key.
     */
    public ?string $searchOnlyApiKey;

    /**
     * @var array Provide an array of collections that needs to be added.
     */
    public array $collections = [];

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defineBehaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => ['apiKey', 'cluster', 'clusterPort', 'port', 'searchOnlyApiKey', 'server'],
            ],
        ];
    }

    /**
     * @inheritdoc
     */
    public function defineRules(): array
    {
        return [
            [['apiKey', 'cluster', 'clusterPort', 'pluginName', 'port', 'searchOnlyApiKey', 'server'] , 'string'],
            [['apiKey', 'serverType'] , 'required'],
            [['serverType'], 'in', 'range' => [
                self::TYPESENSE_SERVER,
                self::TYPESENSE_CLUSTER,
                self::TYPESENSE_CLOUD,
            ]],
            [['cluster', 'clusterPort'], 'required', 'when' => function($model) {
                return $model->serverType === self::TYPESENSE_CLUSTER;
            }],
            [['port', 'server'], 'required', 'when' => function($model) {
                return $model->serverType === self::TYPESENSE_SERVER;
            }],
        ];
    }
}
