<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The mapping designer's persistence round-trip: a posted field layout (each
 * element carrying its Typesense mapping settings) is assembled from the post,
 * saved into the collection definition's project-config entry, and read back on
 * reload as a real field layout. The definition is created and removed within
 * the test so no project-config entry lingers.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craftpulse\typesense\fieldlayoutelements\MappingField;
use craftpulse\typesense\models\CollectionDefinition;
use craftpulse\typesense\Typesense;

it('persists a posted mapping field layout to the collection definition', function() {
    $suffix = bin2hex(random_bytes(4));
    $admin = new User();
    $admin->admin = true;
    $admin->username = "ts_map_{$suffix}";
    $admin->email = "ts_map_{$suffix}@example.test";
    Craft::$app->getElements()->saveElement($admin);

    $field = Craft::$app->getFields()->getAllFields()[0] ?? null;
    expect($field)->not->toBeNull();

    $definition = new CollectionDefinition();
    $definition->name = "ts_map_{$suffix}";
    $definition->elementType = Entry::class;
    $definition->multisite = 'sharedWithSiteFilter';
    Typesense::$plugin->getManagedCollections()->save($definition);

    $fieldLayout = [
        'tabs' => [
            [
                'name' => 'Mapping',
                'elements' => [
                    [
                        'type' => MappingField::class,
                        'fieldUid' => $field->uid,
                        'kind' => 'field',
                        'facet' => true,
                        'weight' => '3',
                    ],
                ],
            ],
        ],
    ];

    try {
        $this->actingAs($admin)
            ->post(UrlHelper::actionUrl('typesense/collections/save-mapping'), [
                'uid' => $definition->uid,
                'fieldLayout' => Json::encode($fieldLayout),
            ])
            ->assertRedirect();

        $reloaded = Typesense::$plugin->getManagedCollections()->getByUid((string)$definition->uid);
        expect($reloaded)->not->toBeNull();

        $elements = $reloaded->getFieldLayout()->getTabs()[0]->getElements();
        expect($elements)->toHaveCount(1);
        expect($elements[0])->toBeInstanceOf(MappingField::class);
        expect($elements[0]->facet)->toBeTrue();
        expect($elements[0]->weight)->toBe('3');
        expect($elements[0]->tsHandle())->toBe($field->handle);
    } finally {
        Typesense::$plugin->getManagedCollections()->delete($definition);
    }
});
