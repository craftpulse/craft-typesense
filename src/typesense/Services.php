<?php

namespace percipiolondon\typesense\typesense;

use percipiolondon\typesense\services\CollectionService as Collections;

/**
 * Trait Services
 *
 * @property Collections $collections the collection service

 * @author Percipio Global Ltd. <support@percipio.london>
 * @since 1.0.0
 */

trait Services
{
    /**
     * Returns the collection service
     *
     * @return Collections The collection service
     */
    public function getCollections(): Collections
    {
        return $this->get('collections');
    }
}
