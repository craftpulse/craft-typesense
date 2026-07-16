<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The Gap 2 end-to-end gate: a control-panel-managed collection whose source is a
 * sectionless (nested Matrix / CKEditor) entry type compiles into a runtime
 * collection whose element query returns the nested entries directly, joins the
 * registry, and syncs real nested-entry documents to the real v28 server. Proves
 * the entry-type source shape end to end. Skips when the playground has no nested
 * entry type. The test collection and its project-config definition are removed
 * afterwards.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\Entry;
use craft\models\FieldLayoutTab;
use craftpulse\typesense\events\RegisterComputedFieldsEvent;
use craftpulse\typesense\fieldlayoutelements\NativeMappingField;
use craftpulse\typesense\models\CollectionDefinition;
use craftpulse\typesense\models\ComputedField;
use craftpulse\typesense\services\Schema;
use craftpulse\typesense\Typesense;
use yii\base\Event;

function aNestedEntryTypeHandle(): ?string
{
    $entriesService = Craft::$app->getEntries();
    $sectionBound = [];

    foreach ($entriesService->getAllSections() as $section) {
        foreach ($section->getEntryTypes() as $entryType) {
            $sectionBound[$entryType->id] = true;
        }
    }

    foreach ($entriesService->getAllEntryTypes() as $entryType) {
        if (!isset($sectionBound[$entryType->id])) {
            return $entryType->handle;
        }
    }

    return null;
}

it('compiles an entry-type source whose query returns nested entries and syncs them', function() {
    $nestedHandle = aNestedEntryTypeHandle();

    if ($nestedHandle === null) {
        $this->markTestSkipped('No sectionless (nested) entry type in the playground.');
    }

    $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;
    $nestedIds = Entry::find()->type($nestedHandle)->status(null)->siteId($primarySiteId)->limit(3)->ids();

    if ($nestedIds === []) {
        $this->markTestSkipped("The nested entry type '{$nestedHandle}' has no entries to index.");
    }

    $handler = static function(RegisterComputedFieldsEvent $event): void {
        $event->computedFields[] = new ComputedField([
            'name' => 'popularity',
            'type' => 'int32',
            'value' => static fn($element): int => 7,
        ]);
    };
    Event::on(Schema::class, Schema::EVENT_REGISTER_COMPUTED_FIELDS, $handler);

    $definition = new CollectionDefinition();
    $definition->name = 'ts_nested_cp';
    $definition->elementType = Entry::class;
    $definition->source = $nestedHandle;
    $definition->sourceType = CollectionDefinition::SOURCE_TYPE_ENTRY_TYPE;
    $definition->multisite = 'sharedWithSiteFilter';

    $layout = $definition->getFieldLayout();
    $computedElement = new NativeMappingField([
        'handle' => 'popularity',
        'label' => 'popularity',
        'derivedType' => 'int32',
        'kind' => 'computed',
    ]);
    $tab = new FieldLayoutTab(['layout' => $layout, 'name' => 'Mapping']);
    $tab->setElements([$computedElement]);
    $layout->setTabs([$tab]);

    Typesense::$plugin->getManagedCollections()->save($definition);

    $registry = Typesense::$plugin->getCollectionRegistry();
    $sync = Typesense::$plugin->getSync();
    $client = Typesense::$plugin->getClient()->client();
    $target = null;

    try {
        $collection = $registry->get('ts_nested_cp');
        expect($collection)->not->toBeNull();

        // The compiled element query targets the nested type, so it returns the
        // nested entries directly (no owner or field scoping), matching a plain
        // ->type() query for the same type and site.
        $expected = (int)Entry::find()->type($nestedHandle)->status(null)->siteId($primarySiteId)->count();
        $built = 0;

        foreach ($sync->buildQueriesForSite($collection, $primarySiteId) as $query) {
            $built += (int)$query->count();
        }

        expect($built)->toBe($expected)
            ->and($built)->toBeGreaterThan(0);

        // Sync membership recognizes a nested entry as a member of the collection.
        $nestedEntry = Entry::find()->id($nestedIds[0])->status(null)->siteId($primarySiteId)->one();
        expect($nestedEntry)->not->toBeNull()
            ->and($sync->matchesCollection($collection, $nestedEntry))->toBeTrue();

        $target = $registry->resolveName($collection, $primarySiteId);

        try {
            $client->collections[$target]->delete();
        } catch (Throwable) {
            // not present
        }

        $sync->ensureCollectionExists($collection, $primarySiteId);
        $sync->indexElements($collection, $primarySiteId, $nestedIds);

        // The nested entries flow to the server as their own documents.
        expect((int)$client->collections[$target]->retrieve()['num_documents'])->toBeGreaterThan(0);

        $document = $client->collections[$target]->documents[$nestedIds[0] . '-' . $primarySiteId]->retrieve();
        expect((int)$document['popularity'])->toBe(7)
            ->and((int)$document['elementId'])->toBe((int)$nestedIds[0]);
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
