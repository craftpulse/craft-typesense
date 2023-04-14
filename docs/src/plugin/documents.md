---
title: Documents
description: Synd and flush documents inside of Craft CMS
---
# Documents
By default, if any change is being done on an element, it will sync up to Typesense. So any delete, edit or add action will be synced. If you want to sync of flush manually, you can either do it in the control panel or as a console command.

Sync will empty the existing document and add the new content. It will also add or delete any change on entries. The flush will empty out all the documents and schema and build it from fresh. This last one is recommended if you have changed the schema in your config.php file or as best practice is you deploy to another environment.

## Sync
In order to sync the documents, you need to make sure that no change has been done within the config.php file. If the structure has changed, the sync command will fail. Follow upon the flush commands to clear out.

### Admin
In the admin panel, if you go to Typesense in the sidebar, you'll see all the collections listed under the subnav `collections`. Click on the `Sync` button to sync the element to Typesense.

### Console
```
./craft typesense/default/sync
```

## Flush
To recreate the schema and sync the documents, provide the flush command. This is recommonded to do when you're deploying to another environment.

### Admin
In the admin panel, if you go to Typesense in the sidebar, you'll see all the collections listed under the subnav `collections`. Click on the `Flush` button to sync the element to Typesense.

### Console
```
./craft typesense/default/flush
```

## Scheduled posts
By default, Craft CMS doesn't fire an event after updating a status when a scheduled post goes out. Therefore we provide a console command that you can attach to your cron jobs. The command checks if there are entries that are scheduled to go out today and if they haven't been updated after that date
```
./craft typesense/default/update-scheduled-posts
```