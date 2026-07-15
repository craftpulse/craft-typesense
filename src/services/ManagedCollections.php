<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\services;

use Craft;
use craft\base\Component;
use craft\helpers\StringHelper;
use craftpulse\typesense\models\CollectionDefinition;

/**
 * Stores and retrieves the control-panel-managed collection definitions the Pro
 * cockpit authors. Definitions live in project config keyed by UID, so they are
 * deployable, reviewable, and survive environments; this service is the read and
 * write door to that store. The generated transformer compiles each definition
 * into a runtime collection.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class ManagedCollections extends Component
{
    // Constants
    // =========================================================================

    /**
     * @var string The project-config key the definitions live under.
     */
    public const CONFIG_KEY = 'typesense.managedCollections';

    // Public Methods
    // =========================================================================

    /**
     * Deletes a definition from project config.
     *
     * @param CollectionDefinition $definition
     * @return bool
     * @throws \yii\base\ErrorException
     * @throws \yii\base\Exception
     * @throws \yii\base\NotSupportedException
     * @throws \yii\web\ServerErrorHttpException
     * @author CraftPulse
     */
    public function delete(CollectionDefinition $definition): bool
    {
        if ($definition->uid === null) {
            return false;
        }

        Craft::$app->getProjectConfig()->remove(
            self::CONFIG_KEY . '.' . $definition->uid,
            "Delete Typesense collection \u{201C}{$definition->name}\u{201D}",
        );

        return true;
    }

    /**
     * Returns every CP-managed definition, keyed by UID.
     *
     * @return array<string, CollectionDefinition>
     * @author CraftPulse
     */
    public function getAll(): array
    {
        $stored = Craft::$app->getProjectConfig()->get(self::CONFIG_KEY) ?? [];
        $definitions = [];

        foreach ($stored as $uid => $config) {
            if (is_array($config)) {
                $definitions[$uid] = $this->_definitionFromConfig($uid, $config);
            }
        }

        return $definitions;
    }

    /**
     * Returns a definition by UID, or null.
     *
     * @param string $uid
     * @return CollectionDefinition|null
     * @author CraftPulse
     */
    public function getByUid(string $uid): ?CollectionDefinition
    {
        $config = Craft::$app->getProjectConfig()->get(self::CONFIG_KEY . '.' . $uid);

        return is_array($config) ? $this->_definitionFromConfig($uid, $config) : null;
    }

    /**
     * Validates a definition and writes it to project config (assigning a UID on
     * a new one).
     *
     * @param CollectionDefinition $definition
     * @return bool false when the definition fails validation
     * @throws \yii\base\ErrorException
     * @throws \yii\base\Exception
     * @throws \yii\base\NotSupportedException
     * @throws \yii\web\ServerErrorHttpException
     * @author CraftPulse
     */
    public function save(CollectionDefinition $definition): bool
    {
        if (!$definition->validate()) {
            return false;
        }

        if ($definition->uid === null) {
            $definition->uid = StringHelper::UUID();
        }

        Craft::$app->getProjectConfig()->set(
            self::CONFIG_KEY . '.' . $definition->uid,
            $definition->getConfig(),
            "Save Typesense collection \u{201C}{$definition->name}\u{201D}",
        );

        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * Hydrates a definition model from a stored project-config array.
     *
     * @param string $uid
     * @param array<string, mixed> $config
     * @return CollectionDefinition
     * @author CraftPulse
     */
    private function _definitionFromConfig(string $uid, array $config): CollectionDefinition
    {
        $definition = new CollectionDefinition();
        $definition->uid = $uid;
        $definition->name = (string)($config['name'] ?? '');
        /** @var class-string $elementType */
        $elementType = (string)($config['elementType'] ?? '');
        $definition->elementType = $elementType;
        $definition->source = isset($config['source']) ? (string)$config['source'] : null;
        $definition->multisite = (string)($config['multisite'] ?? '');
        $definition->enabled = (bool)($config['enabled'] ?? true);
        $definition->mappings = is_array($config['mappings'] ?? null) ? $config['mappings'] : [];
        $definition->metadata = is_array($config['metadata'] ?? null) ? $config['metadata'] : [];
        $definition->relevance = is_array($config['relevance'] ?? null) ? $config['relevance'] : [];

        return $definition;
    }
}
