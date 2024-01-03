<?php

namespace percipiolondon\typesense\events;

use percipiolondon\typesense\models\CollectionModel;
use yii\base\Event;

/**
 * Collection event class.
 *
 * @author Percipio Global Ltd. <support@percipio.london>
 * @since 1.0.0
 */
class DocumentEvent extends Event
{
    /**
     * @var CollectionModel|null The collection model associated with the event.
     */
    public array|null $document = null;
}
