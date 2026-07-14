<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

/**
 * Typesense config.php
 *
 * This file exists only as a template for the Typesense settings.
 * It does nothing on its own.
 *
 * Don't edit this file, instead copy it to 'craft/config' as 'typesense.php'
 * and make your changes there to override default settings.
 *
 * Once copied to 'craft/config', this file will be multi-environment aware as
 * well, so you can have different settings groups for each environment, just as
 * you do for 'general.php'
 */

use craft\elements\db\EntryQuery;
use craft\elements\Entry;
use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\builders\Field;

return [
    'collections' => [
        Collection::make('schools')
            ->elementType(Entry::class)
            ->elementQuery(fn(EntryQuery $query) => $query->section('schools'))
            ->fields(
                Field::string('title')->sort(),
                Field::string('slug')->facet(),
                Field::string('handle'),
                Field::int32('post_date_timestamp'),
            )
            ->defaultSortingField('post_date_timestamp')
            ->transform(fn(Entry $entry) => [
                'id' => (string)$entry->id,
                'title' => $entry->title,
                'handle' => $entry->getSection()?->handle,
                'slug' => $entry->slug,
                'post_date_timestamp' => (int)($entry->postDate?->format('U') ?? 0),
            ]),
    ],
];
