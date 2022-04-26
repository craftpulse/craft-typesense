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
     * @var string The public-facing name of the plugin
     */
    public $pluginName = 'Typesense';

    /**
     * @var string The API endpoint where Typesense connects to.
     */
    public $server = '0.0.0.0';

    /**
     * @var string The API port which Typesense listens to.
     */
    public $port = '8108';

    /**
     * @var string The Admin API key.
     */
    public $apiKey = '';

    /**
     * @var string The search-only API key.
     */
    public $searchOnlyApiKey = '';

    /**
     * @var array Provide an array of collections that needs to be added.
     */
    public $collections = [];

    // Public Methods
    // =========================================================================

    /**
     * Returns the validation rules for attributes.
     *
     * Validation rules are used by [[validate()]] to check if attribute values are valid.
     * Child classes may override this method to declare different validation rules.
     *
     * More info: http://www.yiiframework.com/doc-2.0/guide-input-validation.html
     *
     * @return array
     */
    public function rules()
    {
        return [
            [['pluginName', 'server', 'port', 'apiKey', 'searchOnlyApiKey'] , 'string'],
            [['apiKey'] , 'required'],
            ['pluginName', 'default', 'value' => 'Typesense'],
            ['server', 'default', 'value' => '0.0.0.0'],
            ['port', 'default', 'value' => '8108'],
        ];
    }

    /**
     * @inheritdoc
     */
    public function behaviors(): array
    {
        return [
            'parser' => [
                'class' => EnvAttributeParserBehavior::class,
                'attributes' => ['server', 'port', 'apiKey', 'searchOnlyApiKey'],
            ]
        ];
    }
}
