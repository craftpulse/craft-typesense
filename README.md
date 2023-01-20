# Typesense plugin for Craft CMS 4.x

Craft Plugin that synchronises with Typesense

![Screenshot](resources/img/plugin-logo.png)

## Requirements

This plugin requires Craft CMS 4.0.0 or later.

## Installation

To install the plugin, follow these instructions.

1. Open your terminal and go to your Craft project:

        cd /path/to/project

2. Then tell Composer to load the plugin:

        composer require percipioglobal/typesense

3. In the Control Panel, go to Settings → Plugins and click the “Install” button for Typesense.

## Typesense Overview

After setting up the config file on which indexes you want to create, you start right away after add / edit / delete elements. If you want to sync all of them, you can go to the Collections within the Typesense section in the control panel and sync all of them.

## Configuring Typesense

After copy and paste the config.php into your own config folder and name it typesense.php. Add your index, the fields you want to attach and the query accordingly to start from. Example below on a blog section

```php
'collections' => [
    // CONTENT
    \percipiolondon\typesense\TypesenseCollectionIndex::create(
        [
            'name' => 'blog',
            'section' => 'blog.blog', //section handle + entry type handle
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
            return $query->section('blog');
        }),
]
```

By default, Craft CMS doesn't fire an event after updating a status when a scheduled post goes out. Therefore we provide a console command that you can attach to your cron jobs. The command checks if there are entries that are scheduled to go out today and if they haven't been updated after that date
```
./craft typesense/default/update-scheduled-posts
```

## Using Typesense

-Insert text here-

## Typesense Roadmap

Some things to do, and ideas for potential features:

* Release it

Brought to you by [percipiolondon](https://percipio.london)
