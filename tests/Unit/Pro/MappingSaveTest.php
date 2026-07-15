<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The mapping designer's persistence round-trip: a posted mapping payload is
 * decoded, written to the collection definition's project-config entry keyed by
 * field UID, and read back on reload. The definition is created and removed
 * within the test so no project-config entry lingers.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craftpulse\typesense\models\CollectionDefinition;
use craftpulse\typesense\Typesense;

it('persists a posted field mapping to project config, keyed by field UID', function() {
    $suffix = bin2hex(random_bytes(4));
    $admin = new User();
    $admin->admin = true;
    $admin->username = "ts_map_{$suffix}";
    $admin->email = "ts_map_{$suffix}@example.test";
    Craft::$app->getElements()->saveElement($admin);

    $definition = new CollectionDefinition();
    $definition->name = "ts_map_{$suffix}";
    $definition->elementType = Entry::class;
    $definition->multisite = 'sharedWithSiteFilter';
    Typesense::$plugin->getManagedCollections()->save($definition);

    $fieldUid = "fld-{$suffix}";

    try {
        $this->actingAs($admin)
            ->post(UrlHelper::actionUrl('typesense/collections/save-mapping'), [
                'uid' => $definition->uid,
                'mappings' => [
                    $fieldUid => Json::encode(['indexable' => true, 'facet' => true, 'weight' => '3']),
                ],
            ])
            ->assertRedirect();

        $reloaded = Typesense::$plugin->getManagedCollections()->getByUid((string)$definition->uid);

        expect($reloaded)->not->toBeNull()
            ->and($reloaded->mappings)->toHaveKey($fieldUid)
            ->and($reloaded->mappings[$fieldUid]['indexable'])->toBeTrue()
            ->and($reloaded->mappings[$fieldUid]['facet'])->toBeTrue()
            ->and($reloaded->mappings[$fieldUid]['weight'])->toBe('3');
    } finally {
        Typesense::$plugin->getManagedCollections()->delete($definition);
    }
});
