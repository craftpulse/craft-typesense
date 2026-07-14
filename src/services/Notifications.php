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
use craft\helpers\App;
use craftpulse\typesense\models\Settings;
use craftpulse\typesense\Typesense;
use Throwable;

/**
 * Sync-failure alerting.
 *
 * Emails a configured address when a Typesense sync job fails or the server is
 * unreachable. Off by default, and throttled per failure context so a burst of
 * failures does not flood the inbox.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class Notifications extends Component
{
    // Constants
    // =========================================================================

    /**
     * @var int Seconds to suppress repeat alerts for the same failure context.
     */
    public const THROTTLE_SECONDS = 3600;

    // Public Methods
    // =========================================================================

    /**
     * Emails an alert about a failed sync job, subject to the opt-in setting and
     * the per-context throttle. Best effort: never throws.
     *
     * @param string $context
     * @param string $error
     * @return void
     * @author CraftPulse
     */
    public function notifyJobFailure(string $context, string $error): void
    {
        $settings = $this->_settings();

        if (!$settings->syncAlertsEnabled) {
            return;
        }

        $email = (string)App::parseEnv($settings->syncAlertEmail);

        if ($email === '') {
            return;
        }

        $cache = Craft::$app->getCache();
        $cacheKey = 'typesense:syncAlert:' . md5($context);

        if ($cache->get($cacheKey) !== false) {
            return;
        }

        $cache->set($cacheKey, true, self::THROTTLE_SECONDS);

        try {
            Craft::$app->getMailer()->compose()
                ->setTo($email)
                ->setSubject(Craft::t('typesense', 'Typesense sync failure'))
                ->setTextBody(Craft::t('typesense', "A Typesense sync operation failed.\n\nContext: {context}\nError: {error}", [
                    'context' => $context,
                    'error' => $error,
                ]))
                ->send();
        } catch (Throwable $e) {
            Craft::error("Could not send Typesense sync-failure alert: {$e->getMessage()}", 'typesense');
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * @return Settings
     * @author CraftPulse
     */
    private function _settings(): Settings
    {
        /** @var Settings $settings */
        $settings = Typesense::$plugin->getSettings();

        return $settings;
    }
}
