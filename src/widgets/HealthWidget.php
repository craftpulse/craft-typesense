<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\widgets;

use Craft;
use craft\base\Widget;
use craft\db\Query;
use craft\web\View;
use craftpulse\typesense\db\Table;
use craftpulse\typesense\services\Drift;
use craftpulse\typesense\Typesense;
use Throwable;

/**
 * A dashboard widget showing each collection's health: the live document count,
 * the last sync time, and the drift status, plus the zero-result query count
 * when analytics is enabled. The health data mirrors the read-only utility, so
 * it is Free-visible; the zero-result readout only appears when analytics is on.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class HealthWidget extends Widget
{
    // Static Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return Craft::t('typesense', 'Typesense health');
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
    public static function maxColspan(): ?int
    {
        return 2;
    }

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @throws \yii\base\InvalidConfigException
     */
    public function getBodyHtml(): ?string
    {
        $analytics = Typesense::$plugin->getAnalytics();
        $analyticsOn = $analytics->isEnabled() && $analytics->isServerEnabled();

        return Craft::$app->getView()->renderTemplate('typesense/_components/widgets/health', [
            'rows' => $this->_rows($analyticsOn),
            'analyticsOn' => $analyticsOn,
        ], View::TEMPLATE_MODE_CP);
    }

    /**
     * @inheritdoc
     */
    public function getTitle(): ?string
    {
        return Craft::t('typesense', 'Typesense health');
    }

    // Private Methods
    // =========================================================================

    /**
     * Builds the per-collection health rows.
     *
     * @param bool $analyticsOn
     * @return array<int, array<string, mixed>>
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    private function _rows(bool $analyticsOn): array
    {
        $plugin = Typesense::$plugin;
        $registry = $plugin->getCollectionRegistry();
        $client = $plugin->getClient()->client();
        $primarySiteId = (int)Craft::$app->getSites()->getPrimarySite()->id;

        $findings = [];

        foreach ($plugin->getDrift()->diff() as $finding) {
            $findings[$finding['target']] = $finding['status'];
        }

        $rows = [];

        foreach ($registry->getAll() as $collection) {
            $handle = $collection->getName();
            $target = $registry->resolveName($collection, $primarySiteId);
            $documents = null;

            if ($client !== null) {
                try {
                    $documents = (int)$client->collections[$target]->retrieve()['num_documents'];
                } catch (Throwable) {
                    $documents = null;
                }
            }

            $rows[] = [
                'name' => $handle,
                'documents' => $documents,
                'lastSyncedAt' => $this->_lastSyncedAt($handle, $primarySiteId),
                'drift' => $findings[$target] ?? Drift::STATUS_MISSING,
                'zeroResults' => $analyticsOn ? $this->_zeroResultCount($handle) : null,
            ];
        }

        return $rows;
    }

    /**
     * The last recorded sync time for a collection's primary site, or null.
     *
     * @param string $handle
     * @param int $siteId
     * @return string|null
     * @author CraftPulse
     */
    private function _lastSyncedAt(string $handle, int $siteId): ?string
    {
        $value = (new Query())
            ->select(['lastSyncedAt'])
            ->from(Table::SYNC_STATE)
            ->where(['collectionHandle' => $handle, 'siteId' => $siteId])
            ->scalar();

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * The total zero-result query volume recorded for a collection, or null when
     * its no-hits destination is absent.
     *
     * @param string $handle
     * @return int|null
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    private function _zeroResultCount(string $handle): ?int
    {
        $rows = Typesense::$plugin->getAnalytics()->noHitsQueries("{$handle}_nohits", 250);

        if ($rows === []) {
            return null;
        }

        return array_sum(array_column($rows, 'count'));
    }
}
