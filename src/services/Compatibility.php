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

/**
 * Companion-plugin compatibility checks.
 *
 * Cockpit re-registers its collections from its own side through the extension
 * events, which requires a Cockpit release paired with Typesense 5.9.0. This
 * service detects an un-paired Cockpit (installed but below the companion
 * version) and surfaces a control-panel warning. Detection is conservative: it
 * never blocks anything, and warns only when Cockpit is actually installed and
 * enabled below the companion version.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class Compatibility extends Component
{
    // Constants
    // =========================================================================

    /**
     * @var string The Cockpit plugin handle.
     */
    public const COCKPIT_HANDLE = 'cockpit';

    /**
     * @var string The first Cockpit version paired with Typesense 5.9.0. Adjust
     *             when the Cockpit companion release is cut.
     */
    public const COCKPIT_COMPANION_VERSION = '5.9.0';

    // Public Methods
    // =========================================================================

    /**
     * Returns the Cockpit pairing warning, or null when there is nothing to warn about.
     *
     * @return string|null
     * @author CraftPulse
     */
    public function cockpitPairingWarning(): ?string
    {
        $plugin = Craft::$app->getPlugins()->getPlugin(self::COCKPIT_HANDLE);

        return $this->pairingWarningForVersion($plugin?->getVersion());
    }

    /**
     * Returns the pairing warning for a given installed Cockpit version (null when
     * Cockpit is not installed or is already paired).
     *
     * @param string|null $installedVersion
     * @return string|null
     * @author CraftPulse
     */
    public function pairingWarningForVersion(?string $installedVersion): ?string
    {
        if ($installedVersion === null) {
            return null;
        }

        if (version_compare($installedVersion, self::COCKPIT_COMPANION_VERSION, '>=')) {
            return null;
        }

        return Craft::t('typesense', 'Typesense was updated to 5.9.0, but Cockpit {version} is older than the companion release ({companion}). Update Cockpit to its 5.9.0 companion so it re-registers its collections. Search will keep working in the meantime.', [
            'version' => $installedVersion,
            'companion' => self::COCKPIT_COMPANION_VERSION,
        ]);
    }
}
