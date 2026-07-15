<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\models;

use Craft;
use craft\base\Model;
use craft\behaviors\EnvAttributeParserBehavior;

/**
 * Typesense settings model.
 *
 * Backs the plugin's project-config settings: connection (single node, cluster,
 * or Typesense Cloud), client resilience, queue options, per-feature managedBy
 * defaults, analytics opt-in, the global sync-suspend switch, and the collection
 * name prefix. Connection and numeric values support environment variables via
 * EnvAttributeParserBehavior and resolve through craft\helpers\App::parseEnv().
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class Settings extends Model
{
    // Constants
    // =========================================================================

    /**
     * @var string Single-node connection.
     */
    public const SERVER_TYPE_SINGLE = 'single';

    /**
     * @var string Self-managed multi-node cluster connection.
     */
    public const SERVER_TYPE_CLUSTER = 'cluster';

    /**
     * @var string Typesense Cloud connection.
     */
    public const SERVER_TYPE_CLOUD = 'cloud';

    /**
     * @var string A feature's state is owned by the config file (seed and enforce).
     */
    public const MANAGED_BY_CONFIG = 'config';

    /**
     * @var string A feature's state is owned by the control panel (seed then CP owns).
     */
    public const MANAGED_BY_CP = 'cp';

    // Public Properties
    // =========================================================================

    /**
     * @var string The public-facing name of the plugin.
     */
    public string $pluginName = 'Typesense';

    /**
     * @var string The connection shape: single node, cluster, or Typesense Cloud.
     */
    public string $serverType = self::SERVER_TYPE_SINGLE;

    /**
     * @var string|null The single-node host.
     */
    public ?string $server = null;

    /**
     * @var string|null The single-node port.
     */
    public ?string $port = '8108';

    /**
     * @var string|null The single-node protocol.
     */
    public ?string $protocol = 'http';

    /**
     * @var string|null The semicolon-separated cluster/cloud node hosts.
     */
    public ?string $cluster = null;

    /**
     * @var string|null The cluster/cloud node port.
     */
    public ?string $clusterPort = '443';

    /**
     * @var string|null The Typesense Cloud nearest-node host (Search Delivery Network).
     */
    public ?string $nearestNode = null;

    /**
     * @var string|null The admin API key.
     */
    public ?string $apiKey = null;

    /**
     * @var string|null The search-only API key.
     */
    public ?string $searchOnlyApiKey = null;

    /**
     * @var string|null The connection timeout, in seconds.
     */
    public ?string $connectionTimeoutSeconds = '2';

    /**
     * @var string|null The interval between node health checks, in seconds.
     */
    public ?string $healthcheckIntervalSeconds = '60';

    /**
     * @var string|null The number of times a failed request is retried.
     */
    public ?string $numRetries = '3';

    /**
     * @var string|null The interval between retries, in seconds.
     */
    public ?string $retryIntervalSeconds = '1';

    /**
     * @var int The priority assigned to Typesense sync jobs pushed onto the queue.
     */
    public int $queuePriority = 1024;

    /**
     * @var string The default owner of synonym state.
     */
    public string $synonymsManagedBy = self::MANAGED_BY_CONFIG;

    /**
     * @var string The default owner of curation state.
     */
    public string $curationManagedBy = self::MANAGED_BY_CONFIG;

    /**
     * @var string The default owner of search-preset state.
     */
    public string $presetsManagedBy = self::MANAGED_BY_CONFIG;

    /**
     * @var bool Whether search analytics collection is opted into by default.
     */
    public bool $analyticsEnabled = false;

    /**
     * @var bool Whether to associate analytics events with a user id. Off by
     * default: no per-user data is collected unless the operator opts in.
     */
    public bool $analyticsUserIdEnabled = false;

    /**
     * @var int Retention guidance, in days, surfaced in the dashboard (0 means
     * no plugin-managed retention; Typesense keeps aggregates until overwritten).
     */
    public int $analyticsRetentionDays = 0;

    /**
     * @var bool Whether element sync is globally suspended (for bulk imports).
     */
    public bool $syncSuspended = false;

    /**
     * @var bool Whether to email an alert on repeated sync-job failures or an
     *           unreachable server.
     */
    public bool $syncAlertsEnabled = false;

    /**
     * @var string|null The address that sync-failure alerts are sent to.
     */
    public ?string $syncAlertEmail = null;

    /**
     * @var string|null A prefix prepended to every collection name (e.g. "staging_").
     */
    public ?string $collectionPrefix = null;

    /**
     * @var array<int, mixed> The collections declared in the config file (fluent config).
     */
    public array $collections = [];

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * Excludes `collections` from the serialised representation: on a fluent
     * config install it holds Collection builder objects that carry closures
     * (elementQuery, transform), which cannot be serialised into project config.
     * The config-file collections are never persisted; they always come from
     * `config/typesense.php`. Dropping the field here keeps `toArray()` (and thus
     * `savePluginSettings()`) safe on every install.
     *
     * @return array<int|string, mixed>
     */
    public function fields(): array
    {
        $fields = parent::fields();
        unset($fields['collections']);

        return $fields;
    }

    /**
     * Returns the list of valid server-type values.
     *
     * @return array<int, string>
     * @author CraftPulse
     */
    public static function serverTypeOptions(): array
    {
        return [
            self::SERVER_TYPE_SINGLE,
            self::SERVER_TYPE_CLUSTER,
            self::SERVER_TYPE_CLOUD,
        ];
    }

    /**
     * Returns the list of managedBy options.
     *
     * @return array<int, string>
     * @author CraftPulse
     */
    public static function managedByOptions(): array
    {
        return [
            self::MANAGED_BY_CONFIG,
            self::MANAGED_BY_CP,
        ];
    }

    /**
     * Validates that an attribute is empty, an environment placeholder, or numeric.
     *
     * @param string $attribute
     * @return void
     * @author CraftPulse
     */
    public function validateNumericOrEnv(string $attribute): void
    {
        $value = $this->$attribute;

        if ($value === null || $value === '') {
            return;
        }

        if (str_starts_with((string)$value, '$')) {
            return;
        }

        if (!is_numeric($value)) {
            $this->addError($attribute, Craft::t('typesense', '{attribute} must be a number or an environment variable.', [
                'attribute' => $attribute,
            ]));
        }
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @return array<string, mixed>
     */
    protected function defineBehaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => [
                    'apiKey',
                    'searchOnlyApiKey',
                    'server',
                    'port',
                    'protocol',
                    'cluster',
                    'clusterPort',
                    'nearestNode',
                    'collectionPrefix',
                    'connectionTimeoutSeconds',
                    'healthcheckIntervalSeconds',
                    'numRetries',
                    'retryIntervalSeconds',
                ],
            ],
        ];
    }

    /**
     * @inheritdoc
     * @return array<int, mixed>
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [
            [
                'apiKey', 'searchOnlyApiKey', 'server', 'port', 'protocol', 'cluster',
                'clusterPort', 'nearestNode', 'collectionPrefix', 'pluginName',
                'connectionTimeoutSeconds', 'healthcheckIntervalSeconds', 'numRetries',
                'retryIntervalSeconds', 'syncAlertEmail',
            ],
            'string',
        ];
        $rules[] = [['syncAlertEmail'], 'email', 'skipOnEmpty' => true];
        $rules[] = [['syncAlertsEnabled'], 'boolean'];
        $rules[] = [['apiKey', 'serverType'], 'required'];
        $rules[] = [['serverType'], 'in', 'range' => self::serverTypeOptions()];
        $rules[] = [
            ['synonymsManagedBy', 'curationManagedBy', 'presetsManagedBy'],
            'in',
            'range' => self::managedByOptions(),
        ];
        $rules[] = [['analyticsEnabled', 'analyticsUserIdEnabled', 'syncSuspended'], 'boolean'];
        $rules[] = [['queuePriority', 'analyticsRetentionDays'], 'integer', 'min' => 0];
        $rules[] = [
            ['connectionTimeoutSeconds', 'healthcheckIntervalSeconds', 'numRetries', 'retryIntervalSeconds'],
            'validateNumericOrEnv',
        ];
        $rules[] = [
            ['server', 'port'],
            'required',
            'when' => fn(self $model): bool => $model->serverType === self::SERVER_TYPE_SINGLE,
        ];
        $rules[] = [
            ['cluster', 'clusterPort'],
            'required',
            'when' => fn(self $model): bool => in_array($model->serverType, [self::SERVER_TYPE_CLUSTER, self::SERVER_TYPE_CLOUD], true),
        ];

        return $rules;
    }
}
