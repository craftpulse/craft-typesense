<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Covers the Cockpit pairing warning: conservative detection that warns only for
 * an installed Cockpit below the companion version, and never otherwise.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\typesense\services\Compatibility;
use craftpulse\typesense\Typesense;

it('does not warn when Cockpit is not installed', function() {
    expect(Typesense::$plugin->getCompatibility()->pairingWarningForVersion(null))->toBeNull();
});

it('warns for an un-paired Cockpit version', function() {
    $warning = Typesense::$plugin->getCompatibility()->pairingWarningForVersion('4.2.0');

    expect($warning)->not->toBeNull()
        ->and($warning)->toContain('Cockpit')
        ->and($warning)->toContain('4.2.0');
});

it('does not warn once Cockpit reaches the companion version', function() {
    $compatibility = Typesense::$plugin->getCompatibility();

    expect($compatibility->pairingWarningForVersion(Compatibility::COCKPIT_COMPANION_VERSION))->toBeNull()
        ->and($compatibility->pairingWarningForVersion('6.0.0'))->toBeNull();
});
