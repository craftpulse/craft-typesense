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
use craftpulse\typesense\models\CollectionDefinition;
use craftpulse\typesense\services\ManagedCollections;

/**
 * Backfills the `collectionType` on control-panel-managed collection definitions
 * (Gap 1: union collections). Every definition that predates the field is a
 * regular single-source collection, so it is backfilled to `regular` with no
 * members. Idempotent (a definition that already carries a collectionType is left
 * untouched).
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.9
 */
class m260716_190000_add_collection_type extends Migration
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
            if (!is_array($config) || isset($config['collectionType'])) {
                continue;
            }

            $config['collectionType'] = CollectionDefinition::COLLECTION_TYPE_REGULAR;
            $config['members'] = [];

            $projectConfig->set(
                ManagedCollections::CONFIG_KEY . '.' . $uid,
                $config,
                'Backfill Typesense collection type',
            );
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m260716_190000_add_collection_type cannot be reverted.\n";

        return false;
    }
}
