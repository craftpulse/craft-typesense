<?php

namespace percipiolondon\typesense\controllers;

use Craft;
use craft\base\ElementInterface;
use craft\helpers\App;
use craft\services\Structures;
use craft\web\Controller;
use craft\elements\Entry;
use craft\helpers\ElementHelper;
use craft\events\ElementEvent;
use craft\services\Elements;
use percipiolondon\typesense\events\DocumentEvent;
use percipiolondon\typesense\helpers\CollectionHelper;
use percipiolondon\typesense\TypesenseCollectionIndex;
use percipiolondon\typesense\Typesense;

use craftpulse\cockpit\Cockpit;
use craftpulse\cockpit\elements\Job;
use craftpulse\cockpit\elements\Department;
use craftpulse\cockpit\elements\MatchFieldEntry;
use craftpulse\cockpit\elements\Contact;

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

        // Only attach events to elements if the API key is configured
        if (!is_null(App::parseEnv(Typesense::$plugin->getSettings()->apiKey))) {
            /* SAVE EVENTS */
            $events = [
                [Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT],
                [Elements::class, Elements::EVENT_AFTER_RESTORE_ELEMENT],
                [Elements::class, Elements::EVENT_AFTER_UPDATE_SLUG_AND_URI],
                [Structures::class, Structures::EVENT_AFTER_MOVE_ELEMENT],
            ];

            foreach ($events as $event) {
                Event::on(
                    $event[0],
                    $event[1],
                    function (ElementEvent $event) {
                        // We need to allow for our cockpit plugin Elements here.
                        // Check if class exists
                        if (class_exists(Cockpit::class)) {
                            // This allowedTypes thing is wonderful! ;)
                            $allowedTypes = [Entry::class, Job::class, Department::class, MatchFieldEntry::class, Contact::class];

                            if (!in_array(get_class($event->element), $allowedTypes)) {
                                return;
                            }
                        } else {
                            // Ignore any element that is not an entry
                            if (!($event->element instanceof Entry)) {
                                return;
                            }
                        }

                        $element = $event->element;

                        if (ElementHelper::isDraftOrRevision($element)) {
                            // don’t do anything with drafts or revisions
                            return;
                        }

                        $this->handleSave($element);

                        if ($event->name === Elements::EVENT_AFTER_RESTORE_ELEMENT || $event->name === Structures::EVENT_AFTER_MOVE_ELEMENT) {
                            foreach ($element->getSupportedSites() as $site) {
                                if ($site['siteId'] ?? null) {
                                    $entry = Entry::find()->id($element->id)->siteId($site['siteId'])->one();
                                    if ($entry) {
                                        $this->handleSave($entry);
                                    }
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

    protected function handleSave(Entry|Job|MatchFieldEntry|Department|Contact $entry): void
    {
        $collection = $this->resolveCollection($entry);

        if (is_null($collection)) {
            return;
        }

        $resolver = $collection->schema['resolver']($entry);

        if (($entry->enabled && $entry->getEnabledForSite()) && $entry->getStatus() === 'live' && in_array($entry->id, $collection->criteria->ids())) {
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

    protected function handleDelete(ElementEvent $event): void
    {
        $element = $event->element;

        if (ElementHelper::isDraftOrRevision($element)) {
            // Don’t do anything with drafts or revisions
            return;
        }

        $elementsService = Craft::$app->getElements();

        foreach ($element->getSupportedSites() as $site) {
            $siteId = $site['siteId'] ?? null;

            if (!$siteId) {
                continue;
            }

            // Re-fetch as the element's own type. Querying Entry::find() here meant
            // Cockpit's Job, Department, Contact and MatchFieldEntry elements never
            // resolved, so their documents were left behind on delete. getElementById()
            // also ignores status, so disabled elements resolve too.
            $source = $elementsService->getElementById($element->id, get_class($element), $siteId);

            if (!$source) {
                continue;
            }

            $collection = $this->resolveCollection($source);

            if (is_null($collection)) {
                continue;
            }

            $resolver = $collection->schema['resolver']($source);

            if (!$resolver) {
                continue;
            }

            // Trigger the before delete event
            $this->triggerBeforeDelete($collection->indexName, $resolver['id']);

            Craft::info('Typesense delete document based on: ' . $source->title . ' - ' . $source->getSite()->handle, __METHOD__);
            Typesense::$plugin->getClient()->client()->collections[$collection->indexName]->documents->delete(['filter_by' => 'id: ' . $resolver['id']]);

            // Trigger the after delete event
            $this->triggerAfterDelete($collection->indexName, $resolver['id']);
        }
    }

    /**
     * Resolves the Typesense collection an element belongs to.
     *
     * Shared by the save and delete handlers so the two cannot drift apart: the
     * delete path previously carried its own copy of this logic and missed both
     * the site-aware Cockpit collections and the `.all` fallback.
     *
     * @param ElementInterface $element
     * @return \percipiolondon\typesense\TypesenseCollectionIndex|null
     */
    protected function resolveCollection(ElementInterface $element): ?TypesenseCollectionIndex
    {
        // Cockpit elements are site-scoped: each instance indexes into its own
        // collection, so these resolve from the element's site rather than a section.
        if ($element instanceof Job) {
            return $this->collectionBySection($element->getSite()->handle === 'fiftyfiveplus' ? 'jobs.ffp' : 'jobs.all');
        }

        if ($element instanceof Department) {
            return $this->collectionBySection($element->getSite()->handle === 'fiftyfiveplus' ? 'offices.ffp' : 'offices.all');
        }

        /* This is very limited - as we always need to have a section named the same, this should go to settings, and mappable!) */
        $sectionHandle = $element->section->handle ?? null;

        if (!$sectionHandle) {
            return null;
        }

        $type = $element->type->handle ?? null;

        if ($type) {
            $collection = CollectionHelper::getCollectionBySection($sectionHandle . '.' . $type);

            if ($collection instanceof TypesenseCollectionIndex) {
                return $collection;
            }
        }

        // Get the generic type if specific doesn't exist
        return $this->collectionBySection($sectionHandle . '.all');
    }

    /**
     * Returns the collection registered for a `section.type` key, creating the
     * configured collections first if it isn't registered yet.
     *
     * @param string $section
     * @return \percipiolondon\typesense\TypesenseCollectionIndex|null
     */
    protected function collectionBySection(string $section): ?TypesenseCollectionIndex
    {
        $collection = CollectionHelper::getCollectionBySection($section);

        // Create collection if it doesn't exist
        if (!$collection instanceof TypesenseCollectionIndex) {
            Typesense::$plugin->getCollections()->saveCollections();
            $collection = CollectionHelper::getCollectionBySection($section);
        }

        return $collection instanceof TypesenseCollectionIndex ? $collection : null;
    }
}
