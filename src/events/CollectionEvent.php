<?php

namespace percipiolondon\typesense\events;

use yii\base\Event;

/**
 * Collection event class.
 *
 * @author Percipio Global Ltd. <support@percipio.london>
 * @since 1.0.0
 */
class CollectionEvent extends Event
{
    /**
     * @var \percipiolondon\models\Collection|null The collection model associated with the event.
     */
    public $collection;

    /**
     * @var bool Whether the collection is brand new
     */
    public $isNew = false;
}
