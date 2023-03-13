<?php
/**
 * Typesense plugin for Craft CMS 4.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://percipio.london
 * @copyright Copyright (c) 2022 percipiolondon
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

return [
    'collections' => [
        // CONTENT
        \percipiolondon\typesense\TypesenseCollectionIndex::create(
            [
                'name' => 'schools',
                'section' => 'schools.default', //section handle + entry type handle
                'fields' => [
                    [
                        'name' => 'title',
                        'type' => 'string',
                        'sort' => true,
                    ],
                    [
                        'name' => 'slug',
                        'type' => 'string',
                        'facet' => true
                    ],
                    [
                        'name' => 'handle',
                        'type' => 'string',
                    ],
                    [
                        'name' => 'post_date_timestamp',
                        'type' => 'int32',
                    ],
                ],
                'default_sorting_field' => 'post_date_timestamp', // can only be an integer,
                'resolver' => static function(\craft\elements\Entry $entry) {
                    return [
                        'id' => (string)$entry->id,
                        'title' => $entry->title,
                        'handle' => $entry->section->handle,
                        'slug' => $entry->slug,
                        'post_date_timestamp' => (int)$entry->postDate->format('U')
                    ];
                }
            ]
        )
        ->elementType(\craft\elements\Entry::class)
        ->criteria(function(\craft\elements\db\EntryQuery $query) {
            return $query->section('schools');
        }),
    ]
];
