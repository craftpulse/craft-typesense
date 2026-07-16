<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\migrations;

use Craft;
use craft\db\Migration;
use craftpulse\typesense\services\ManagedCollections;

/**
 * Normalizes any dev-state 5.9.x auto-embedding config on control-panel-managed
 * collections to the provider-referencing shape introduced with the AI provider
 * layer. The old shape stored remote credentials inline under an `embedding.config`
 * key; that trio is gone. Since this AI build is unreleased there is nothing to
 * preserve for remote setups (there is no provider to map inline keys onto), so a
 * built-in config keeps its model and is flagged `builtIn`, and any inline-remote
 * config is disabled and stripped honestly (the author re-picks a provider). The
 * migration is idempotent.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.6
 */
class m260716_150000_ai_providers extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @throws \yii\base\ErrorException
     * @throws \yii\base\Exception
     * @throws \yii\base\NotSupportedException
     * @throws \yii\web\ServerErrorHttpException
     */
    public function safeUp(): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();
        $stored = $projectConfig->get(ManagedCollections::CONFIG_KEY) ?? [];

        if (!is_array($stored)) {
            return true;
        }

        foreach ($stored as $uid => $config) {
            if (!is_array($config) || !is_array($config['embedding'] ?? null)) {
                continue;
            }

            $embedding = $config['embedding'];

            // Already migrated: the branch flag is present and the dead key gone.
            if (isset($embedding['builtIn']) && !array_key_exists('config', $embedding)) {
                continue;
            }

            $model = (string)($embedding['model'] ?? '');
            $isBuiltIn = str_starts_with($model, 'ts/');

            $config['embedding'] = [
                'enabled' => $isBuiltIn ? (bool)($embedding['enabled'] ?? false) : false,
                'builtIn' => $isBuiltIn,
                'model' => $isBuiltIn ? $model : '',
                'providerHandle' => '',
                'dims' => 0,
                'from' => is_array($embedding['from'] ?? null) ? array_values($embedding['from']) : [],
                'indexingPrefix' => '',
                'queryPrefix' => '',
            ];
            $config['conversation'] = is_array($config['conversation'] ?? null) ? $config['conversation'] : [];

            $projectConfig->set(
                ManagedCollections::CONFIG_KEY . '.' . $uid,
                $config,
                'Normalize Typesense auto-embedding config to the provider shape',
            );
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m260716_150000_ai_providers cannot be reverted.\n";

        return false;
    }
}
