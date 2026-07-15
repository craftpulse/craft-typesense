<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The real-FieldLayout mapping architecture: a collection definition owns a
 * craft\models\FieldLayout whose palette is scoped to its element source, its
 * per-field mapping settings round-trip through project-config config, and an
 * ordinary entry type's field layout is left completely untouched.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\Entry;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craftpulse\typesense\fieldlayoutelements\MappingField;
use craftpulse\typesense\fieldlayoutelements\NativeMappingField;
use craftpulse\typesense\models\CollectionDefinition;

function aMappedDefinition(): CollectionDefinition
{
    $definition = new CollectionDefinition();
    $definition->name = 'rt_heroes';
    $definition->elementType = Entry::class;
    $definition->source = 'heroes';

    return $definition;
}

it('round-trips a mapping field and its settings through the project-config shape', function() {
    $field = Craft::$app->getFields()->getAllFields()[0] ?? null;
    expect($field)->not->toBeNull();

    $definition = aMappedDefinition();
    $layout = $definition->getFieldLayout();

    $element = MappingField::forField($field, 'field');
    $element->facet = true;
    $element->sortable = true;
    $element->weight = '5';
    $element->description = 'The hero name.';

    $tab = new FieldLayoutTab(['layout' => $layout, 'name' => 'Mapping']);
    $tab->setElements([$element]);
    $layout->setTabs([$tab]);

    $config = $definition->getConfig();
    expect($config)->toHaveKey('fieldLayout');
    expect($config)->toHaveKey('fieldLayoutUid');

    $restored = FieldLayout::createFromConfig($config['fieldLayout']);
    $elements = $restored->getTabs()[0]->getElements();

    expect($elements[0])->toBeInstanceOf(MappingField::class);
    expect($elements[0]->facet)->toBeTrue();
    expect($elements[0]->sortable)->toBeTrue();
    expect($elements[0]->weight)->toBe('5');
    expect($elements[0]->description)->toBe('The hero name.');
    expect($elements[0]->kind)->toBe('field');
});

it('scopes the designer palette to the collection source as mapping elements', function() {
    $definition = aMappedDefinition();
    $layout = $definition->getFieldLayout();

    $groups = $layout->getAvailableCustomFields();
    $custom = array_merge(...array_values($groups));

    expect($custom)->not->toBeEmpty();
    foreach ($custom as $element) {
        expect($element)->toBeInstanceOf(MappingField::class);
    }
});

it('leaves an ordinary entry type field layout untouched', function() {
    $section = Craft::$app->getEntries()->getAllSections()[0] ?? null;
    expect($section)->not->toBeNull();

    $entryType = $section->getEntryTypes()[0];
    $layout = $entryType->getFieldLayout();

    foreach (array_merge(...array_values($layout->getAvailableCustomFields())) as $element) {
        expect($element)->not->toBeInstanceOf(MappingField::class);
    }

    foreach ($layout->getAvailableNativeFields() as $element) {
        expect($element)->not->toBeInstanceOf(NativeMappingField::class);
    }
});
