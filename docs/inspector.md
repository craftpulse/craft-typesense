---
title: Indexing inspector (Pro)
description: Read-only per-element indexing diagnostics in the entry sidebar
---
# Indexing inspector (Pro)

"Why is this entry (not) in Typesense?" The Pro edition answers it in place. The
entry edit screen gains a read-only Typesense panel in its sidebar that reports,
per collection the element could belong to, exactly what the sync engine sees.

## Editions and permissions

The inspector is a Pro feature, gated by `typesense:manage-collections`. In the
Free edition the plugin renders none of the Pro control panel. The panel is
shown only when the plugin is running Pro, the current user holds
`typesense:manage-collections`, and the element matches at least one collection;
otherwise it renders nothing.

## What it reports

For each collection the element's type could belong to, the panel shows:

| State | Meaning |
| --- | --- |
| Indexed | A member, in an active status, with a built document that is live on the server. |
| Not a member | The element does not match the collection's query. |
| Inactive | A member, but not in an active status (it would be removed). |
| No document | A member in an active status, but the transform builds no document (for example an empty required value, a zero-coordinate geopoint, or a transform that returned nothing). |

It also shows the collection's last-indexed timestamp for the element's site,
the skip reason where one applies, and the live document currently in Typesense
(as pretty-printed JSON) so you can compare what the server holds against what
the element would build now.

## One source of truth

The panel and the sync console read the same `Inspector` service
(`inspectElement()`), so the control panel never drifts from the command line.
The diagnostics are read-only: the inspector reports, it never writes.
