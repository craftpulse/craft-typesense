<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The 5.9.0 namespace-move field-layout rewrite. A control-panel-managed
 * collection stores its mapping field layout in project config, and each layout
 * element records its class in the layout's `type` key. This pins that the
 * rewrite migration turns a legacy `percipiolondon\typesense\*` element class
 * stored there into the new `craftpulse\typesense\*` name, and that the backwards-
 * compatibility autoloader resolves the legacy name in the meantime.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\typesense\migrations\m260716_170000_rewrite_legacy_fqcn;
use craftpulse\typesense\services\ManagedCollections;

function seedLegacyFldCollection(): string
{
    $uid = 'ts-legacy-fqcn-' . bin2hex(random_bytes(4));

    Craft::$app->getProjectConfig()->set(ManagedCollections::CONFIG_KEY . '.' . $uid, [
        'name' => 'legacy_fqcn_test',
        'elementType' => \craft\elements\Entry::class,
        'multisite' => 'sharedWithSiteFilter',
        'enabled' => true,
        'fieldLayout' => [
            'tabs' => [
                [
                    'name' => 'Content',
                    'elements' => [
                        ['type' => 'percipiolondon\\typesense\\fieldlayoutelements\\NativeMappingField', 'handle' => 'title'],
                    ],
                ],
            ],
        ],
    ], 'Seed a legacy-namespace field layout for the rewrite test');

    return $uid;
}

it('rewrites a legacy field-layout element class to the craftpulse namespace', function() {
    $uid = seedLegacyFldCollection();

    try {
        (new m260716_170000_rewrite_legacy_fqcn())->safeUp();

        $stored = Craft::$app->getProjectConfig()->get(ManagedCollections::CONFIG_KEY . '.' . $uid);
        $type = $stored['fieldLayout']['tabs'][0]['elements'][0]['type'];

        expect($type)->toBe('craftpulse\\typesense\\fieldlayoutelements\\NativeMappingField')
            ->and($type)->not->toContain('percipiolondon');
    } finally {
        Craft::$app->getProjectConfig()->remove(ManagedCollections::CONFIG_KEY . '.' . $uid);
    }
});

it('is idempotent and leaves a clean config untouched', function() {
    $uid = 'ts-clean-fqcn-' . bin2hex(random_bytes(4));

    Craft::$app->getProjectConfig()->set(ManagedCollections::CONFIG_KEY . '.' . $uid, [
        'name' => 'clean_fqcn_test',
        'elementType' => \craft\elements\Entry::class,
        'multisite' => 'sharedWithSiteFilter',
        'enabled' => true,
        'fieldLayout' => [
            'tabs' => [
                ['name' => 'Content', 'elements' => [['type' => 'craftpulse\\typesense\\fieldlayoutelements\\NativeMappingField', 'handle' => 'title']]],
            ],
        ],
    ], 'Seed a clean field layout');

    try {
        (new m260716_170000_rewrite_legacy_fqcn())->safeUp();

        $type = Craft::$app->getProjectConfig()->get(ManagedCollections::CONFIG_KEY . '.' . $uid)['fieldLayout']['tabs'][0]['elements'][0]['type'];
        expect($type)->toBe('craftpulse\\typesense\\fieldlayoutelements\\NativeMappingField');
    } finally {
        Craft::$app->getProjectConfig()->remove(ManagedCollections::CONFIG_KEY . '.' . $uid);
    }
});
