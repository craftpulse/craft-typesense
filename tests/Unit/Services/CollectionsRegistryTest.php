<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Covers the Collections registry: merging event-registered and config-file
 * collections, config-wins collision resolution with the overridden-name state,
 * and environment-prefix name resolution.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\builders\Field;
use craftpulse\typesense\services\Collections;
use craftpulse\typesense\Typesense;
use yii\base\Event;

function registry(): Collections
{
    return Typesense::$plugin->getCollectionRegistry();
}

/**
 * Runs a callback with the given config-file collections and event collections,
 * restoring both afterward (settings mutation and the event handler).
 *
 * @param array $configCollections
 * @param array $eventCollections
 * @param callable $test
 */
function withCollections(array $configCollections, array $eventCollections, callable $test): void
{
    $settings = Typesense::$plugin->getSettings();
    $originalCollections = $settings->collections;
    $settings->collections = $configCollections;

    $handler = function($event) use ($eventCollections) {
        foreach ($eventCollections as $collection) {
            $event->collections[] = $collection;
        }
    };
    Event::on(Collections::class, Collections::EVENT_REGISTER_COLLECTIONS, $handler);

    try {
        $test();
    } finally {
        Event::off(Collections::class, Collections::EVENT_REGISTER_COLLECTIONS, $handler);
        $settings->collections = $originalCollections;
    }
}

it('registers event collections into the runtime map', function() {
    $collection = Collection::make('offices')->fields(Field::string('title'));

    withCollections([], [$collection], function() {
        expect(registry()->getAll())->toHaveKey('offices')
            ->and(registry()->get('offices')->getName())->toBe('offices');
    });
});

it('lets a config-file collection win over an event collection of the same name', function() {
    $configCollection = Collection::make('jobs')->fields(Field::string('configTitle'));
    $eventCollection = Collection::make('jobs')->fields(Field::string('eventTitle'));

    withCollections([$configCollection], [$eventCollection], function() use ($configCollection) {
        expect(registry()->get('jobs'))->toBe($configCollection)
            ->and(registry()->getOverriddenNames())->toBe(['jobs']);
    });
});

it('does not flag overrides when names do not collide', function() {
    withCollections(
        [Collection::make('a')->fields(Field::string('t'))],
        [Collection::make('b')->fields(Field::string('t'))],
        function() {
            expect(registry()->getAll())->toHaveKeys(['a', 'b'])
                ->and(registry()->getOverriddenNames())->toBe([]);
        },
    );
});

it('exposes the config-file and event sources separately so neither leaks into the other', function() {
    withCollections(
        [Collection::make('cfg_only')->fields(Field::string('t'))],
        [Collection::make('evt_only')->fields(Field::string('t'))],
        function() {
            expect(registry()->getConfigCollections())->toHaveKey('cfg_only')
                ->and(registry()->getConfigCollections())->not->toHaveKey('evt_only')
                ->and(registry()->getEventCollections())->toHaveKey('evt_only')
                ->and(registry()->getEventCollections())->not->toHaveKey('cfg_only')
                ->and(registry()->getAll())->toHaveKeys(['cfg_only', 'evt_only']);
        },
    );
});

it('resolves collection names through the environment prefix', function() {
    $settings = Typesense::$plugin->getSettings();
    $original = $settings->collectionPrefix;
    $settings->collectionPrefix = 'staging_';

    try {
        $collection = Collection::make('heroes')->fields(Field::string('title'));

        expect(registry()->resolveName('heroes'))->toBe('staging_heroes')
            ->and(registry()->resolveName($collection))->toBe('staging_heroes')
            ->and(registry()->getCreateSchema($collection)['name'])->toBe('staging_heroes');
    } finally {
        $settings->collectionPrefix = $original;
    }
});
