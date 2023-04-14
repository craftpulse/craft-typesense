---
title: Collections
description: Create collections inside of Craft CMS
---
# Collections

Collections are created within the Craft CMS through a config file. Copy over `src/config.php` and edit to get started with your collections.

## Config.php
Return an array with a keyed value `collections`. This key contains an array of all the collections you want to add. You can find all the supported [Typesense Field Types here](https://typesense.org/docs/0.23.0/api/collections.html#field-types)

Per array item, you will create a `new TypesenseCollectionIndex`. Here's a basic example on how to achieve this
```
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
```

## Collection fields

### Name
The name is how the collection will be named in Typesense. If you're using different indexes per environment, you would simply do this (if you're using the default environment variable, you can change this to whatever suits in your .env file):
```
'name' => App::env('COLLECTION_SCHOOLS')
```

### Section
We need both the section and the entry type to defer the difference in our Typesense core to collect the data. Pick the section handle from within the Sections and take the entry type handle (also required when there's only one entry type available).

### Fields
These are the fields that needs to be created within the document inside of an index. To get the available types, please check the [Field Types documentation](https://typesense.org/docs/0.23.0/api/collections.html#field-types). To learn more about the schema's, you can read upon these on the [pre-defined schema documentation](https://typesense.org/docs/0.23.0/api/collections.html#with-pre-defined-schema)

### Default sorting field
The documents will be sorted on this field by default. As a warning, you can only use int32 / float fields to sort upon.

### Resolver
This function will give each entry queries by the Section variable. You need to map the fieds you need within Typesense. Make sure you're using the correct types, which means for example to format your datestamps to unix.

<!--Typesense can't handle objects to be saved. What is recommended is providing the data from within the entry you need as a value, so you can filter on. You can parse all the data through a json encoded string, so you can use the data after fetching the Typesense documents. For example

#### Returned array inside of the resolver
```
# returned
return [
  'category' => $entry->newsCategory->one()->slug ?? '',
  'categoryMeta' => Json::encode(_getCategory($entry->newsCategory->one() ?? null)),
]
```
#### Helper function to convert entry to object
```
function _getCategory($category)
{
    if ($category) {
        return (object)[
            'title' => $category->title,
            'slug' => $category->slug,
            'url' => $category->url
        ];
    }

    return '';
}
```-->

### Element type
Define which element from Craft CMS is used to provide the data. You can look up the [documentation](https://typesense.org/docs/0.24.0/api/collections.html#field-types) to map out the data

### Criteria
Define the query to fetch the data from within the Database. You can look at the Craft CMS documentation on how to [fetch data from within PHP](https://Craft CMS.com/docs/4.x/element-queries.html#executing-element-queries). You don't have to define the `->all()`, this is being handled within the plugin.