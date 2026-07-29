<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\controllers;

use Craft;
use craftpulse\typesense\controllers\base\ProController;
use craftpulse\typesense\models\Settings;
use craftpulse\typesense\services\Analytics;
use craftpulse\typesense\Typesense;
use yii\web\Response;

/**
 * The Pro analytics dashboard: popular queries, zero-result queries, and counter
 * readouts from the analytics destination collections, plus an A/B comparison by
 * analytics tag (shown only on tag-capable servers). The GDPR posture (opt-in
 * state, user-id association off by default, retention guidance) is surfaced in
 * the screen. Gated by the edition and `typesense:view-analytics`.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class AnalyticsController extends ProController
{
    // Constants
    // =========================================================================

    /**
     * @var string The permission that gates the analytics dashboard.
     */
    public const PERMISSION_VIEW_ANALYTICS = 'typesense:view-analytics';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @param \yii\base\Action $action
     * @return bool
     * @throws \yii\web\ForbiddenHttpException
     * @throws \yii\base\InvalidConfigException
     * @throws \yii\web\BadRequestHttpException
     */
    public function beforeAction($action): bool
    {
        if (!parent::beforeAction($action)) {
            return false;
        }

        $this->requirePermission(self::PERMISSION_VIEW_ANALYTICS);

        return true;
    }

    /**
     * The analytics dashboard: rule readouts, the A/B comparison, and the GDPR
     * posture.
     *
     * @return Response
     * @throws \yii\base\Exception
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionIndex(): Response
    {
        /** @var Settings $settings */
        $settings = Typesense::$plugin->getSettings();
        $analytics = Typesense::$plugin->getAnalytics();
        $capabilities = Typesense::$plugin->getClient()->getServerCapabilities();
        $serverEnabled = $analytics->isServerEnabled();

        return $this->renderTemplate('typesense/analytics/index', [
            'optedIn' => $settings->analyticsEnabled,
            'serverEnabled' => $serverEnabled,
            'userIdEnabled' => $settings->analyticsUserIdEnabled,
            'retentionDays' => $settings->analyticsRetentionDays,
            'supportsTags' => $capabilities?->analyticsTags() ?? false,
            'readouts' => $serverEnabled ? $this->_readouts() : [],
            'experiments' => Typesense::$plugin->getExperiments()->getAll(),
        ]);
    }

    /**
     * Creates the recommended popular-queries and no-hits rules (and their
     * destination collections) for every declared collection. Idempotent.
     *
     * @return Response|null
     * @throws \yii\web\BadRequestHttpException
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function actionSetUpRules(): ?Response
    {
        $this->requirePostRequest();
        $this->requirePermission(self::PERMISSION_VIEW_ANALYTICS);

        $analytics = Typesense::$plugin->getAnalytics();
        $registry = Typesense::$plugin->getCollectionRegistry();

        foreach (array_keys($registry->getAll()) as $handle) {
            $this->_ensureRule($handle, Analytics::TYPE_POPULAR_QUERIES, "{$handle}_popular");
            $this->_ensureRule($handle, Analytics::TYPE_NOHITS_QUERIES, "{$handle}_nohits");
        }

        return $this->asSuccess(Craft::t('typesense', 'Recommended analytics rules created.'), [], 'typesense/analytics');
    }

    // Private Methods
    // =========================================================================

    /**
     * Ensures one aggregation rule and its destination collection exist.
     *
     * @param string $handle
     * @param string $type
     * @param string $destination
     * @return void
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    private function _ensureRule(string $handle, string $type, string $destination): void
    {
        $analytics = Typesense::$plugin->getAnalytics();
        $suffix = $type === Analytics::TYPE_POPULAR_QUERIES ? 'popular' : 'nohits';

        $analytics->ensureDestination($destination);
        $analytics->upsertRule("{$handle}_{$suffix}", $type, [
            'source' => ['collections' => [$handle]],
            'destination' => ['collection' => $destination],
            'limit' => 250,
        ]);
    }

    /**
     * Builds the per-rule readouts for the aggregation rules on the server.
     *
     * @return array<int, array<string, mixed>>
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    private function _readouts(): array
    {
        $analytics = Typesense::$plugin->getAnalytics();
        $readouts = [];

        foreach ($analytics->rules() as $rule) {
            $type = (string)($rule['type'] ?? '');
            $destination = (string)($rule['params']['destination']['collection'] ?? '');

            if ($destination === '' || !in_array($type, [Analytics::TYPE_POPULAR_QUERIES, Analytics::TYPE_NOHITS_QUERIES], true)) {
                continue;
            }

            $readouts[] = [
                'name' => (string)($rule['name'] ?? ''),
                'type' => $type,
                'rows' => $type === Analytics::TYPE_POPULAR_QUERIES
                    ? $analytics->popularQueries($destination)
                    : $analytics->noHitsQueries($destination),
            ];
        }

        return $readouts;
    }
}
