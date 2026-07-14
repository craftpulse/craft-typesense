<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\utilities;

use Craft;
use craft\base\Utility;
use craftpulse\typesense\controllers\SettingsController;
use craftpulse\typesense\services\Drift;
use craftpulse\typesense\Typesense;
use Throwable;

/**
 * Typesense control-panel utility: server status, collections overview, drift
 * report, and operational sync/flush/suspend controls. Read-only in Free (it
 * surfaces state and queues operational jobs; it never edits configuration).
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class TypesenseUtility extends Utility
{
    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('typesense', 'Typesense');
    }

    /**
     * @inheritdoc
     */
    public static function id(): string
    {
        return 'typesense';
    }

    /**
     * @inheritdoc
     */
    public static function icon(): ?string
    {
        return Craft::getAlias('@craftpulse/typesense/icon.svg');
    }

    /**
     * @inheritdoc
     */
    public static function contentHtml(): string
    {
        $plugin = Typesense::$plugin;
        /** @var \craftpulse\typesense\models\Settings $settings */
        $settings = $plugin->getSettings();

        return Craft::$app->getView()->renderTemplate('typesense/_components/utilities/typesense', [
            'status' => $plugin->getClient()->getStatus(),
            'collections' => self::_collectionRows(),
            'suspended' => $settings->syncSuspended,
            'canManage' => Craft::$app->getUser()->checkPermission(SettingsController::PERMISSION_MANAGE_SETTINGS),
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Builds the collections overview rows: name, document count, and drift status.
     *
     * @return array<int, array<string, mixed>>
     * @author CraftPulse
     */
    private static function _collectionRows(): array
    {
        $plugin = Typesense::$plugin;
        $client = $plugin->getClient()->client();
        $findings = [];

        foreach ($plugin->getDrift()->diff() as $finding) {
            $findings[$finding['target']] = $finding['status'];
        }

        $rows = [];

        foreach ($plugin->getCollectionRegistry()->getAll() as $collection) {
            $target = $plugin->getCollectionRegistry()->resolveName(
                $collection,
                Craft::$app->getSites()->getPrimarySite()->id,
            );
            $documents = null;

            if ($client !== null) {
                try {
                    $documents = (int)$client->collections[$target]->retrieve()['num_documents'];
                } catch (Throwable) {
                    $documents = null;
                }
            }

            $rows[] = [
                'name' => $collection->getName(),
                'target' => $target,
                'strategy' => $collection->getMultisiteStrategy()->value,
                'documents' => $documents,
                'drift' => $findings[$target] ?? Drift::STATUS_MISSING,
            ];
        }

        return $rows;
    }
}
