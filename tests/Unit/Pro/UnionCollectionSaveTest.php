<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The union-collection creation flow and the type lock: a New Collection save
 * with collectionType=union persists a union collection (its sources are members,
 * not the single element-source picker), and the collection type is locked after
 * creation, so an edit that posts a different type is ignored. Definitions are
 * created and removed within the test.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\User;
use craft\helpers\UrlHelper;
use craftpulse\typesense\models\CollectionDefinition;
use craftpulse\typesense\Typesense;

function aUnionSaveAdmin(): User
{
    $suffix = bin2hex(random_bytes(4));
    $admin = new User();
    $admin->admin = true;
    $admin->username = "ts_union_{$suffix}";
    $admin->email = "ts_union_{$suffix}@example.test";
    Craft::$app->getElements()->saveElement($admin);

    return $admin;
}

it('creates a union collection from the New Collection form', function() {
    $admin = aUnionSaveAdmin();
    $handle = 'ts_union_' . bin2hex(random_bytes(3));

    $this->actingAs($admin)->post(UrlHelper::actionUrl('typesense/collections/save'), [
        'displayName' => 'Site search',
        'name' => $handle,
        'collectionType' => 'union',
        'multisite' => 'sharedWithSiteFilter',
        'enabled' => '1',
        // The single-source picker is present in the form but ignored for a union.
        'source' => \craft\elements\Entry::class . ':heroes',
    ]);

    $definitions = array_filter(
        Typesense::$plugin->getManagedCollections()->getAll(),
        static fn(CollectionDefinition $d): bool => $d->name === $handle,
    );
    $definition = reset($definitions);

    try {
        expect($definition)->not->toBeFalse()
            ->and($definition->isUnion())->toBeTrue()
            ->and($definition->collectionType)->toBe(CollectionDefinition::COLLECTION_TYPE_UNION)
            // The single source picker was ignored: no member was synthesized from it.
            ->and($definition->members)->toBe([]);
    } finally {
        if ($definition instanceof CollectionDefinition) {
            Typesense::$plugin->getManagedCollections()->delete($definition);
        }
    }
});

it('locks the collection type after creation (an edit cannot change it)', function() {
    $admin = aUnionSaveAdmin();

    $definition = new CollectionDefinition();
    $definition->name = 'ts_lock_' . bin2hex(random_bytes(3));
    $definition->elementType = \craft\elements\Entry::class;
    $definition->source = 'heroes';
    $definition->multisite = 'sharedWithSiteFilter';
    $definition->collectionType = CollectionDefinition::COLLECTION_TYPE_REGULAR;
    Typesense::$plugin->getManagedCollections()->save($definition);

    try {
        // Edit the saved (regular) collection, posting collectionType=union.
        $this->actingAs($admin)->post(UrlHelper::actionUrl('typesense/collections/save'), [
            'uid' => $definition->uid,
            'displayName' => 'Renamed',
            'name' => $definition->name,
            'collectionType' => 'union',
            'multisite' => 'sharedWithSiteFilter',
            'enabled' => '1',
            'source' => \craft\elements\Entry::class . ':heroes',
        ]);

        $reloaded = Typesense::$plugin->getManagedCollections()->getByUid((string)$definition->uid);

        // The type stayed regular; the posted union type was ignored (locked).
        expect($reloaded->collectionType)->toBe(CollectionDefinition::COLLECTION_TYPE_REGULAR)
            ->and($reloaded->displayName)->toBe('Renamed');
    } finally {
        Typesense::$plugin->getManagedCollections()->delete($definition);
    }
});
