<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * A union collection's member sources and their per-member mapping field
 * layouts: the definition hydrates one member model per source (a field-layout
 * provider scoped to that source, carrying its own layout), and the Members save
 * action rebuilds the member list from the posted rows with unique handles.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craftpulse\typesense\models\CollectionDefinition;
use craftpulse\typesense\models\CollectionMember;
use craftpulse\typesense\Typesense;

function aUnionMembersAdmin(): User
{
    $suffix = bin2hex(random_bytes(4));
    $admin = new User();
    $admin->admin = true;
    $admin->username = "ts_mem_{$suffix}";
    $admin->email = "ts_mem_{$suffix}@example.test";
    Craft::$app->getElements()->saveElement($admin);

    return $admin;
}

it('hydrates one field-layout provider per member, scoped to its source', function() {
    $definition = new CollectionDefinition();
    $definition->name = 'union_members_model';
    $definition->collectionType = CollectionDefinition::COLLECTION_TYPE_UNION;
    $definition->multisite = 'sharedWithSiteFilter';
    $definition->members = [
        ['handle' => 'news', 'elementType' => Entry::class, 'source' => 'heroes', 'sourceType' => 'section'],
        ['handle' => 'images', 'elementType' => Asset::class, 'source' => 'images', 'sourceType' => 'section'],
    ];

    $models = $definition->getMemberModels();

    expect($models)->toHaveCount(2)
        ->and($models[0])->toBeInstanceOf(CollectionMember::class)
        ->and($models[0]->handle)->toBe('news')
        ->and($models[0]->elementType)->toBe(Entry::class)
        // Each member's layout is scoped to the member as its provider, typed to
        // the member's element type (so the palette shows that member's fields).
        ->and($models[0]->getFieldLayout()->provider)->toBe($models[0])
        ->and($models[0]->getFieldLayout()->type)->toBe(Entry::class)
        ->and($models[1]->elementType)->toBe(Asset::class)
        ->and($models[1]->getFieldLayout()->type)->toBe(Asset::class);
});

it('rebuilds the member list from the posted rows with unique handles', function() {
    $admin = aUnionMembersAdmin();

    $definition = new CollectionDefinition();
    $definition->name = 'union_members_save_' . bin2hex(random_bytes(3));
    $definition->collectionType = CollectionDefinition::COLLECTION_TYPE_UNION;
    $definition->multisite = 'sharedWithSiteFilter';
    Typesense::$plugin->getManagedCollections()->save($definition);

    try {
        $this->actingAs($admin)->post(UrlHelper::actionUrl('typesense/collections/save-members'), [
            'uid' => $definition->uid,
            'members' => [
                ['source' => Entry::class . ':heroes', 'handle' => 'news'],
                // A blank handle is generated, and a duplicate is de-duplicated.
                ['source' => Asset::class . ':images', 'handle' => ''],
                ['source' => Entry::class . ':heroes', 'handle' => 'news'],
            ],
        ]);

        $reloaded = Typesense::$plugin->getManagedCollections()->getByUid((string)$definition->uid);
        $members = $reloaded->getMembers();

        expect($members)->toHaveCount(3)
            ->and($members[0]['handle'])->toBe('news')
            ->and($members[0]['elementType'])->toBe(Entry::class)
            ->and($members[0]['source'])->toBe('heroes')
            ->and($members[1]['elementType'])->toBe(Asset::class)
            ->and($members[1]['handle'])->not->toBe('')
            // The duplicate "news" handle is de-duplicated.
            ->and($members[2]['handle'])->not->toBe('news')
            // Reorder persists: the members are stored in the posted row order.
            ->and(array_column($members, 'source'))->toBe(['heroes', 'images', 'heroes']);
    } finally {
        Typesense::$plugin->getManagedCollections()->delete($definition);
    }
});

it('renders the members table with add, delete, and reorder controls', function() {
    $admin = aUnionMembersAdmin();

    $definition = new CollectionDefinition();
    $definition->name = 'union_members_ui_' . bin2hex(random_bytes(3));
    $definition->collectionType = CollectionDefinition::COLLECTION_TYPE_UNION;
    $definition->multisite = 'sharedWithSiteFilter';
    $definition->members = [
        ['handle' => 'news', 'elementType' => Entry::class, 'source' => 'heroes', 'sourceType' => 'section'],
    ];
    Typesense::$plugin->getManagedCollections()->save($definition);

    try {
        $body = (string)$this->actingAs($admin)
            ->get(UrlHelper::cpUrl('typesense/collections/' . $definition->uid . '/members'))
            ->content;

        // The editable table enables add/delete/reorder (the regression was all
        // three defaulting to false, so the table rendered one static row).
        expect($body)->toContain('Add a member')
            ->and($body)->toContain('allowAdd: true')
            ->and($body)->toContain('allowDelete: true')
            ->and($body)->toContain('allowReorder: true');
    } finally {
        Typesense::$plugin->getManagedCollections()->delete($definition);
    }
});
