<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Settings persistence safety (C2): on a fluent-config install, Settings holds
 * Collection builder objects (which carry elementQuery/transform closures) in
 * its `collections` attribute. Those must never reach project config, or every
 * settings Save and suspend-toggle fatals trying to serialise a closure. The
 * fields() override drops `collections` from the serialised representation.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\models\Settings;
use craftpulse\typesense\Typesense;

it('excludes fluent collections from the serialised settings', function() {
    /** @var Settings $settings */
    $settings = Typesense::$plugin->getSettings();

    // The playground config declares a fluent heroes collection, so the runtime
    // settings carry Collection objects.
    expect($settings->collections)->not->toBeEmpty()
        ->and($settings->collections[0])->toBeInstanceOf(Collection::class);

    // toArray() (what savePluginSettings serialises) must not include them.
    $array = $settings->toArray();
    expect($array)->not->toHaveKey('collections');

    // The whole serialised payload is JSON-encodable (no closures survive), which
    // is exactly what project config requires.
    expect(fn() => json_encode($array, JSON_THROW_ON_ERROR))->not->toThrow(Exception::class);
});

it('round-trips a scalar settings save without touching collections', function() {
    /** @var Settings $settings */
    $settings = Typesense::$plugin->getSettings();
    $original = $settings->syncSuspended;

    $settings->syncSuspended = !$original;

    // Serialising the toggled settings (the suspend-toggle path) is closure-free.
    $array = $settings->toArray();
    expect($array)->toHaveKey('syncSuspended')
        ->and($array['syncSuspended'])->toBe(!$original)
        ->and($array)->not->toHaveKey('collections');

    $settings->syncSuspended = $original;
});
