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
 * Rewrites any legacy `percipiolondon\typesense\*` class strings persisted in a
 * control-panel-managed collection's project config to the new
 * `craftpulse\typesense\*` namespace (5.9.0 namespace move). The only class
 * strings Craft persists for this plugin live in the mapping field layout: each
 * field-layout element records its class in the layout config's `type` key, so a
 * collection authored while the namespace was mid-move could carry a legacy
 * `percipiolondon\typesense\fieldlayoutelements\MappingField` (or
 * `NativeMappingField`) there.
 *
 * The backwards-compatibility autoloader already aliases those legacy names at
 * runtime, so the field layout hydrates either way; this migration removes the
 * stored dependency on the shim so the data is clean on its own. It is idempotent
 * (a config with no legacy strings is left untouched).
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.7
 */
class m260716_170000_rewrite_legacy_fqcn extends Migration
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
            if (!is_array($config)) {
                continue;
            }

            $json = json_encode($config, JSON_THROW_ON_ERROR);

            // In JSON, the class separator is an escaped backslash (\\), so the
            // legacy prefix appears as percipiolondon\\typesense\\.
            $rewritten = str_replace('percipiolondon\\\\typesense\\\\', 'craftpulse\\\\typesense\\\\', $json);

            if ($rewritten === $json) {
                continue;
            }

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($rewritten, true, 512, JSON_THROW_ON_ERROR);

            $projectConfig->set(
                ManagedCollections::CONFIG_KEY . '.' . $uid,
                $decoded,
                'Rewrite legacy Typesense field-layout class names to the craftpulse namespace',
            );
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m260716_170000_rewrite_legacy_fqcn cannot be reverted.\n";

        return false;
    }
}
