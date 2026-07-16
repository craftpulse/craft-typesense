<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The union collection engine: the compiler resolves each member's field set
 * (merging same handle+type, namespacing on a type mismatch) into one union
 * schema with the reserved _elementType (faceted) and _elementClass fields; the
 * document path builds from the matching member's mapping and stamps the
 * discriminator; and sync spans all members. The end-to-end block indexes a real
 * two-member union and facets by _elementType against the real server.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\Entry;
use craftpulse\typesense\fieldlayoutelements\NativeMappingField;
use craftpulse\typesense\models\CollectionDefinition;
use craftpulse\typesense\models\ComputedField;
use craftpulse\typesense\services\Documents;
use craftpulse\typesense\services\Schema;
use craftpulse\typesense\Typesense;
use yii\base\Event;

function aUnionDefinition(array $members, string $name = 'union_engine'): CollectionDefinition
{
    $definition = new CollectionDefinition();
    $definition->name = $name;
    $definition->collectionType = CollectionDefinition::COLLECTION_TYPE_UNION;
    $definition->multisite = 'sharedWithSiteFilter';
    $definition->members = $members;

    return $definition;
}

function aComputedMemberLayout(string $handle, string $type): array
{
    return [
        'tabs' => [
            [
                'name' => 'Mapping',
                'elements' => [
                    ['type' => NativeMappingField::class, 'handle' => $handle, 'label' => $handle, 'derivedType' => $type, 'kind' => 'computed'],
                ],
            ],
        ],
    ];
}

it('merges a shared field and namespaces a type mismatch across members, with the reserved fields', function() {
    $definition = aUnionDefinition([
        ['handle' => 'news', 'elementType' => Entry::class, 'source' => 'heroes', 'sourceType' => 'section', 'fieldLayout' => aComputedMemberLayout('shared', 'string')],
        ['handle' => 'blocks', 'elementType' => Entry::class, 'source' => 'parleyTestOuterBlock', 'sourceType' => 'entryType', 'fieldLayout' => aComputedMemberLayout('shared', 'int32')],
    ]);

    $collection = Typesense::$plugin->getCompiler()->compile($definition);
    $fieldNames = array_map(static fn(array $f): string => $f['name'], $collection->toSchema()['fields']);

    expect($collection->isUnion())->toBeTrue()
        ->and($collection->getMembers())->toHaveCount(2)
        // news claims `shared` (string); blocks' `shared` (int32) is namespaced.
        ->and($fieldNames)->toContain('shared')
        ->and($fieldNames)->toContain('blocks_shared')
        // The reserved discriminator fields are present and faceted.
        ->and($fieldNames)->toContain(Documents::FIELD_ELEMENT_TYPE)
        ->and($fieldNames)->toContain(Documents::FIELD_ELEMENT_CLASS);

    $elementTypeField = null;

    foreach ($collection->toSchema()['fields'] as $field) {
        if ($field['name'] === Documents::FIELD_ELEMENT_TYPE) {
            $elementTypeField = $field;
        }
    }

    expect($elementTypeField['facet'] ?? false)->toBeTrue();

    // The blocks member's mapping writes to the namespaced key.
    $blocks = $collection->getMembers()[1];
    $blocksKeys = array_map(static fn($m): string => $m->documentKey, $blocks['mapping']);
    expect($blocksKeys)->toContain('blocks_shared');
});

it('merges the same field handle and type into one shared field', function() {
    $definition = aUnionDefinition([
        ['handle' => 'news', 'elementType' => Entry::class, 'source' => 'heroes', 'sourceType' => 'section', 'fieldLayout' => aComputedMemberLayout('popularity', 'int32')],
        ['handle' => 'blocks', 'elementType' => Entry::class, 'source' => 'parleyTestOuterBlock', 'sourceType' => 'entryType', 'fieldLayout' => aComputedMemberLayout('popularity', 'int32')],
    ]);

    $collection = Typesense::$plugin->getCompiler()->compile($definition);
    $fieldNames = array_map(static fn(array $f): string => $f['name'], $collection->toSchema()['fields']);

    // One shared `popularity`, not `blocks_popularity`.
    expect(array_count_values($fieldNames)['popularity'])->toBe(1)
        ->and($fieldNames)->not->toContain('blocks_popularity');
});

it('stamps the discriminator from the matching member in the built document', function() {
    $handler = static function($event): void {
        $event->computedFields[] = new ComputedField(['name' => 'popularity', 'type' => 'int32', 'value' => static fn($element): int => 7]);
    };
    Event::on(Schema::class, Schema::EVENT_REGISTER_COMPUTED_FIELDS, $handler);

    $definition = aUnionDefinition([
        ['handle' => 'news', 'elementType' => Entry::class, 'source' => 'heroes', 'sourceType' => 'section', 'fieldLayout' => aComputedMemberLayout('popularity', 'int32')],
        ['handle' => 'blocks', 'elementType' => Entry::class, 'source' => 'parleyTestOuterBlock', 'sourceType' => 'entryType', 'fieldLayout' => aComputedMemberLayout('popularity', 'int32')],
    ]);

    $collection = Typesense::$plugin->getCompiler()->compile($definition);
    $sync = Typesense::$plugin->getSync();
    $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;

    try {
        $entry = Entry::find()->section('heroes')->status('live')->siteId($primarySiteId)->one();
        expect($entry)->not->toBeNull();

        // Sync recognizes the entry as a union member.
        expect($sync->matchesCollection($collection, $entry))->toBeTrue()
            ->and($sync->buildQueriesForSite($collection, $primarySiteId))->toHaveCount(2);

        $result = Typesense::$plugin->getDocuments()->build($collection, $entry, $primarySiteId);
        expect($result->isEmpty())->toBeFalse();
        $document = $result->documents[0];

        expect($document[Documents::FIELD_ELEMENT_TYPE])->toBe('news')
            ->and($document[Documents::FIELD_ELEMENT_CLASS])->toBe('Entry')
            ->and((int)$document['popularity'])->toBe(7);
    } finally {
        Event::off(Schema::class, Schema::EVENT_REGISTER_COMPUTED_FIELDS, $handler);
    }
});

it('indexes a two-member union and facets by _elementType on the server', function() {
    $handler = static function($event): void {
        $event->computedFields[] = new ComputedField(['name' => 'popularity', 'type' => 'int32', 'value' => static fn($element): int => 7]);
    };
    Event::on(Schema::class, Schema::EVENT_REGISTER_COMPUTED_FIELDS, $handler);

    $definition = aUnionDefinition([
        ['handle' => 'news', 'elementType' => Entry::class, 'source' => 'heroes', 'sourceType' => 'section', 'fieldLayout' => aComputedMemberLayout('popularity', 'int32')],
        ['handle' => 'blocks', 'elementType' => Entry::class, 'source' => 'parleyTestOuterBlock', 'sourceType' => 'entryType', 'fieldLayout' => aComputedMemberLayout('popularity', 'int32')],
    ], 'ts_union_e2e');

    $collection = Typesense::$plugin->getCompiler()->compile($definition);
    $registry = Typesense::$plugin->getCollectionRegistry();
    $sync = Typesense::$plugin->getSync();
    $client = Typesense::$plugin->getClient()->client();
    $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;
    $target = $registry->resolveName($collection, $primarySiteId);

    $newsId = Entry::find()->section('heroes')->status('live')->siteId($primarySiteId)->ids()[0] ?? null;
    $blockId = Entry::find()->type('parleyTestOuterBlock')->status(null)->siteId($primarySiteId)->ids()[0] ?? null;

    if ($newsId === null || $blockId === null) {
        Event::off(Schema::class, Schema::EVENT_REGISTER_COMPUTED_FIELDS, $handler);
        $this->markTestSkipped('The playground lacks a heroes entry or a parleyTestOuterBlock nested entry.');
    }

    try {
        try {
            $client->collections[$target]->delete();
        } catch (Throwable) {
            // not present
        }

        $sync->ensureCollectionExists($collection, $primarySiteId);
        $sync->indexElements($collection, $primarySiteId, [$newsId, $blockId]);

        expect((int)$client->collections[$target]->retrieve()['num_documents'])->toBe(2);

        // Facet by the discriminator: both member handles appear as facet values.
        $search = $client->collections[$target]->documents->search([
            'q' => '*',
            'query_by' => Documents::FIELD_ELEMENT_TYPE,
            'facet_by' => Documents::FIELD_ELEMENT_TYPE,
        ]);
        $facetValues = array_column($search['facet_counts'][0]['counts'] ?? [], 'value');

        expect($facetValues)->toContain('news')
            ->and($facetValues)->toContain('blocks');
    } finally {
        try {
            $client->collections[$target]->delete();
        } catch (Throwable) {
            // already gone
        }
        Event::off(Schema::class, Schema::EVENT_REGISTER_COMPUTED_FIELDS, $handler);
    }
});
