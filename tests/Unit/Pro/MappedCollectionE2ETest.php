<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The P8 end-to-end gate: a control-panel-managed collection, authored as a
 * definition with per-field mappings (a faceted, weighted content field and a
 * registered computed field), compiles into a runtime collection, joins the
 * registry, and syncs real documents to the real v28 server whose schema and
 * documents match the mapping. Drift and the registry (the utility's source)
 * both see the CP collection. Runs against the real Typesense server; the test
 * collection and its project-config definition are removed afterwards.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\Entry;
use craftpulse\typesense\events\RegisterComputedFieldsEvent;
use craftpulse\typesense\models\CollectionDefinition;
use craftpulse\typesense\models\ComputedField;
use craftpulse\typesense\services\Schema;
use craftpulse\typesense\Typesense;
use yii\base\Event;

it('compiles a CP-managed collection and syncs matching documents to the server', function() {
    $handler = static function(RegisterComputedFieldsEvent $event): void {
        $event->computedFields[] = new ComputedField([
            'name' => 'popularity',
            'type' => 'int32',
            'value' => static fn($element): int => 7,
        ]);
    };
    Event::on(Schema::class, Schema::EVENT_REGISTER_COMPUTED_FIELDS, $handler);

    $definition = new CollectionDefinition();
    $definition->name = 'heroes_cp';
    $definition->elementType = Entry::class;
    $definition->source = 'heroes';
    $definition->multisite = 'sharedWithSiteFilter';

    // Map a string content field (faceted + weighted) and the computed field.
    $descriptors = Typesense::$plugin->getMappingSources()->descriptorsFor($definition);
    $stringField = null;

    foreach ($descriptors as $descriptor) {
        if ($descriptor['kind'] === 'field' && $descriptor['derivedType'] === 'string') {
            $stringField = $descriptor;
            break;
        }
    }

    expect($stringField)->not->toBeNull();

    $definition->mappings = [
        $stringField['uid'] => ['indexable' => true, 'facet' => true, 'sortable' => false, 'weight' => 5],
        'computed:popularity' => ['indexable' => true],
    ];

    Typesense::$plugin->getManagedCollections()->save($definition);

    $registry = Typesense::$plugin->getCollectionRegistry();
    $client = Typesense::$plugin->getClient()->client();
    $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;
    $target = null;

    try {
        // The registry (the utility's source) and drift both see the CP collection.
        $collection = $registry->get('heroes_cp');
        expect($collection)->not->toBeNull();

        $driftCollections = array_column(Typesense::$plugin->getDrift()->diff(), 'collection');
        expect($driftCollections)->toContain('heroes_cp');

        // The compiled preset carries the search weight.
        expect($collection->getPreset()['query_by_weights'] ?? '')->toContain('5');

        $target = $registry->resolveName($collection, $primarySiteId);

        try {
            $client->collections[$target]->delete();
        } catch (Throwable) {
            // not present
        }

        $sync = Typesense::$plugin->getSync();
        $sync->ensureCollectionExists($collection, $primarySiteId);

        // The live schema matches the mapping: the faceted content field, the
        // computed field, and the reserved fields.
        $live = $client->collections[$target]->retrieve();
        $fields = [];

        foreach ($live['fields'] as $field) {
            $fields[$field['name']] = $field;
        }

        expect($fields)->toHaveKey($stringField['handle'])
            ->and($fields[$stringField['handle']]['facet'])->toBeTrue()
            ->and($fields)->toHaveKey('popularity')
            ->and($fields['popularity']['type'])->toBe('int32')
            ->and($fields)->toHaveKey('elementId')
            ->and($fields)->toHaveKey('siteId');

        // A synced document carries the computed value.
        $ids = Entry::find()->section('heroes')->status('live')->siteId($primarySiteId)->limit(3)->ids();
        $sync->indexElements($collection, $primarySiteId, $ids);

        expect($client->collections[$target]->retrieve()['num_documents'])->toBeGreaterThan(0);

        $document = $client->collections[$target]->documents[(string)$ids[0]]->retrieve();
        expect((int)$document['popularity'])->toBe(7)
            ->and((int)$document['elementId'])->toBe((int)$ids[0]);
    } finally {
        if ($target !== null) {
            try {
                $client->collections[$target]->delete();
            } catch (Throwable) {
                // already gone
            }
        }
        Typesense::$plugin->getManagedCollections()->delete($definition);
        Event::off(Schema::class, Schema::EVENT_REGISTER_COMPUTED_FIELDS, $handler);
    }
});
