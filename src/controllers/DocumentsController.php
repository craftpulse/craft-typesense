<?php
namespace percipiolondon\typesense\controllers;

use Craft;
use craft\web\Controller;
use craft\elements\Entry;
use craft\helpers\ElementHelper;
use craft\events\ElementEvent;
use craft\services\Elements;
use percipiolondon\typesense\events\DocumentEvent;
use percipiolondon\typesense\helpers\CollectionHelper;
use percipiolondon\typesense\Typesense;
use Typesense\Exceptions\ObjectNotFound;
use Typesense\Exceptions\ServerError;
use yii\base\Event;

class DocumentsController extends Controller
{
    // Events
    // -------------------------------------------------------------------------

    /**
     * @event The event that is triggered before a deletion / upsert happens.
     */
    public const EVENT_AFTER_DELETE = 'afterDelete';
    public const EVENT_AFTER_UPSERT = 'afterUpsert';
    public const EVENT_BEFORE_DELETE = 'beforeDelete';
    public const EVENT_BEFORE_UPSERT = 'beforeUpsert';

    public function init(): void
    {
        parent::init();

        /* SAVE EVENTS */
        $events = [
            [Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT],
            [Elements::class, Elements::EVENT_AFTER_RESTORE_ELEMENT],
            [Elements::class, Elements::EVENT_AFTER_UPDATE_SLUG_AND_URI],
        ];

        foreach ($events as $event) {
            Event::on(
                $event[0],
                $event[1],
                function (ElementEvent $event) {
                    // Ignore any element that is not an entry
                    if (!($event->element instanceof Entry)) {
                        return;
                    }

                    $element = $event->element;

                    if (ElementHelper::isDraftOrRevision($element)) {
                        // don’t do anything with drafts or revisions
                        return;
                    }

                    $this->handleSave($element);

                    if ($event->name === Elements::EVENT_AFTER_RESTORE_ELEMENT) {
                        foreach($element->getSupportedSites() as $site) {
                            if ($site['siteId'] ?? null) {
                                $entry = Entry::find()->id($element->id)->siteId($site['siteId'])->one();
                                $this->handleSave($entry);
                            }
                        }
                    }
                }
            );
        }

        /* DELETE EVENT */
        Event::on(
            Elements::class,
            Elements::EVENT_BEFORE_DELETE_ELEMENT,
            function (ElementEvent $event) {
                $this->handleDelete($event);
            }
        );
    }

    public function triggerAfterDelete(string $index, string $id): void
    {
        // Trigger the after delete event
        if ($this->hasEventHandlers(self::EVENT_AFTER_DELETE)) {
            $this->trigger(self::EVENT_AFTER_DELETE, new DocumentEvent([
                'document' => [
                    'index' => $index,
                    'type' => 'Delete',
                    'id' => $id,
                ]
            ]));
        }
    }

    public function triggerAfterUpsert(string $index, string $id): void
    {
        if ($this->hasEventHandlers(self::EVENT_AFTER_UPSERT)) {
            $this->trigger(self::EVENT_AFTER_UPSERT, new DocumentEvent([
                'document' => [
                    'index' => $index,
                    'type' => 'Upsert',
                    'id' => $id,
                ]
            ]));
        }
    }

    public function triggerBeforeDelete(string $index, string $id): void
    {
        // Trigger the after delete event
        if ($this->hasEventHandlers(self::EVENT_BEFORE_DELETE)) {
            $this->trigger(self::EVENT_BEFORE_DELETE, new DocumentEvent([
                'document' => [
                    'index' => $index,
                    'type' => 'Delete',
                    'id' => $id,
                ]
            ]));
        }
    }

    public function triggerBeforeUpsert(string $index, string $id): void
    {
        if ($this->hasEventHandlers(self::EVENT_BEFORE_UPSERT)) {
            $this->trigger(self::EVENT_BEFORE_UPSERT, new DocumentEvent([
                'document' => [
                    'index' => $index,
                    'type' => 'Upsert',
                    'id' => $id,
                ]
            ]));
        }
    }

    protected function handleSave(Entry $entry): void
    {
        $sectionHande = $entry->section->handle ?? null;
        $type = $entry->type->handle ?? null;
        $collection = null;
        $resolver = null;

        if ($sectionHande) {
            $section = '';

            if ($type) {
                $section = $sectionHande . '.' . $type;
            }

            $collection = CollectionHelper::getCollectionBySection($section);

            // get the generic type if specific doesn't exist
            if (is_null($collection)) {
                $section = $sectionHande . '.all';
                $collection = CollectionHelper::getCollectionBySection($section);
            }

            //create collection if it doesn't exist
            if (!$collection instanceof \percipiolondon\typesense\TypesenseCollectionIndex) {
                Typesense::$plugin->getCollections()->saveCollections();
                $collection = CollectionHelper::getCollectionBySection($section);
            }
        }

        if ($collection) {
            $resolver = $collection->schema['resolver']($entry);
        }

        if (($entry->enabled && $entry->getEnabledForSite()) && $entry->getStatus() === 'live') {
            // element is enabled --> save to Typesense
            if ($resolver) {
                // Trigger the before upsert event
                $this->triggerBeforeUpsert($collection->indexName, $resolver['id']);

                Craft::info('Typesense edit / add document based of: ' . $entry->title, __METHOD__);

                try {
                    Typesense::$plugin->getClient()->client()->collections[$collection->indexName]->documents->upsert($resolver);

                    // Trigger the after upsert event
                    $this->triggerAfterUpsert($collection->indexName, $resolver['id']);
                } catch (ObjectNotFound | ServerError $e) {
                    Craft::$app->session->setFlash('error', Craft::t('typesense', 'There was an issue saving your action, check the logs for more info'));
                    Craft::error($e->getMessage(), __METHOD__);
                }
            }
        } else {
            // element is disabled --> delete from Typesense
            if ($resolver) {
                // Trigger the before delete event
                $this->triggerBeforeDelete($collection->indexName, $resolver['id']);

                Craft::info('Typesense delete document based of: ' . $entry->title, __METHOD__);
                Typesense::$plugin->getClient()->client()->collections[$collection->indexName]->documents->delete(['filter_by' => 'id: ' . $resolver['id']]);

                // Trigger the after delete event
                $this->triggerAfterDelete($collection->indexName, $resolver['id']);
            }
        }
    }

    protected function handleDelete(ElementEvent $event)
    {
        $element = $event->element;

        if (ElementHelper::isDraftOrRevision($element)) {
            // Don’t do anything with drafts or revisions
            return;
        }

        foreach ($element->getSupportedSites() as $site) {
            if ($site['siteId'] ?? null) {

                $entry = Entry::find()->id($element->id)->siteId($site['siteId'])->one();

                if ($entry) {
                    $section = $entry->section->handle ?? null;
                    $type = $entry->type->handle ?? null;
                    $collection = null;
                    $resolver = null;

                    if ($section) {
                        if ($type) {
                            $section = $section . '.' . $type;
                        }

                        $collection = CollectionHelper::getCollectionBySection($section);
                    }

                    if ($collection) {
                        $resolver = $collection->schema['resolver']($entry);
                    }

                    if ($resolver) {
                        // Trigger the before delete event
                        $this->triggerBeforeDelete($collection->indexName, $resolver['id']);

                        Craft::info('Typesense delete document based on: ' . $entry->title . ' - ' . $entry->getSite()->handle, __METHOD__);
                        Typesense::$plugin->getClient()->client()->collections[$collection->indexName]->documents->delete(['filter_by' => 'id: ' . $resolver['id']]);

                        // Trigger the after delete event
                        $this->triggerAfterDelete($collection->indexName, $resolver['id']);
                    }
                }
            }
        }
    }
}
