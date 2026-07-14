<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Covers the Documents transformer: closure and generated (mapping) paths,
 * reserved-field injection, single and multi document output, status-based
 * delete signalling, dependency collection, and the before-index event.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\Entry;
use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\models\FieldMapping;
use craftpulse\typesense\services\Documents;
use craftpulse\typesense\Typesense;
use yii\base\Event;

function documents(): Documents
{
    return Typesense::$plugin->getDocuments();
}

function docTestElement(string $status = 'live'): Entry
{
    $element = new class() extends Entry {
        public string $fakeStatus = 'live';

        public function getStatus(): ?string
        {
            return $this->fakeStatus;
        }
    };
    $element->fakeStatus = $status;
    $element->id = 12345;
    $element->siteId = 1;
    $element->title = 'Hero';

    return $element;
}

it('builds a single document via the transform path with reserved fields', function() {
    $collection = Collection::make('c')->transform(fn(Entry $entry, callable $registerDependency) => [
        'id' => (string)$entry->id,
        'title' => $entry->title,
    ]);

    $result = documents()->build($collection, docTestElement(), 1);

    expect($result->documents)->toHaveCount(1)
        ->and($result->documents[0])->toMatchArray([
            'id' => '12345',
            'title' => 'Hero',
            'elementId' => 12345,
            'siteId' => 1,
        ]);
});

it('builds multiple documents when the transform returns a list', function() {
    $collection = Collection::make('c')->transform(fn(Entry $entry, callable $registerDependency) => [
        ['id' => 'a', 'variant' => 1],
        ['id' => 'b', 'variant' => 2],
    ]);

    $result = documents()->build($collection, docTestElement(), 1);

    expect($result->documents)->toHaveCount(2)
        ->and($result->documents[0]['id'])->toBe('a')
        ->and($result->documents[1]['id'])->toBe('b')
        ->and($result->documents[0]['elementId'])->toBe(12345);
});

it('signals deletion by returning no documents for an inactive element', function() {
    $collection = Collection::make('c')->transform(fn(Entry $entry, callable $registerDependency) => ['id' => (string)$entry->id]);

    $result = documents()->build($collection, docTestElement('disabled'), 1);

    expect($result->isEmpty())->toBeTrue()
        ->and($result->documents)->toBe([]);
});

it('respects custom active statuses', function() {
    $collection = Collection::make('c')
        ->activeStatuses(['pending'])
        ->transform(fn(Entry $entry, callable $registerDependency) => ['id' => (string)$entry->id]);

    expect(documents()->build($collection, docTestElement('live'), 1)->isEmpty())->toBeTrue()
        ->and(documents()->build($collection, docTestElement('pending'), 1)->isEmpty())->toBeFalse();
});

it('collects relation dependencies from the transform', function() {
    $collection = Collection::make('c')->transform(function(Entry $entry, callable $registerDependency) {
        $registerDependency(999);
        $registerDependency(888);
        $registerDependency(999);

        return ['id' => (string)$entry->id];
    });

    $result = documents()->build($collection, docTestElement(), 1);

    expect($result->dependencies)->toBe([999, 888]);
});

it('builds a document via the generated mapping path', function() {
    $collection = Collection::make('c')->mapping(
        FieldMapping::fromArray(['documentKey' => 'title', 'attribute' => 'title', 'type' => 'string']),
    );

    $result = documents()->build($collection, docTestElement(), 2);

    expect($result->documents[0])->toMatchArray([
        'title' => 'Hero',
        'elementId' => 12345,
        'siteId' => 2,
    ]);
});

it('lets the before-index event mutate the document', function() {
    $handler = function($event) {
        $event->document['injected'] = true;
    };
    Event::on(Documents::class, Documents::EVENT_BEFORE_INDEX_DOCUMENT, $handler);

    try {
        $collection = Collection::make('c')->transform(fn(Entry $entry, callable $registerDependency) => ['id' => (string)$entry->id]);
        $result = documents()->build($collection, docTestElement(), 1);

        expect($result->documents[0]['injected'])->toBeTrue();
    } finally {
        Event::off(Documents::class, Documents::EVENT_BEFORE_INDEX_DOCUMENT, $handler);
    }
});
