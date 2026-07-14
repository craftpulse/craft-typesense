---
title: Server versions and capabilities
description: Which Typesense server versions are supported and how features are gated
---
# Server versions and capabilities

The plugin detects the connected Typesense server version at handshake (via
`GET /debug`) and derives a set of capability flags from it. Version-sensitive
features are gated on these flags: a feature the server does not support is
hidden, never shown as disabled or badged.

## Support policy

| Server version | Status |
| --- | --- |
| Below 28.0 | Unsupported (below the floor). |
| 28.0, 29.x | Supported. |
| 30.0, 30.1 | Refused. Both the per-collection and the `*_sets` synonym and curation API shapes return 404 on these releases, so the plugin cannot operate reliably. |
| 30.2 and later | Supported. The `*_sets` synonym and curation shapes are restored. |

When an unsupported or refused version is detected, the settings screen shows the
reason and write paths refuse to run.

## Capability flags

| Flag | Requires |
| --- | --- |
| `textMatchBuckets` | 28.0+ |
| `rerankHybridMatches` | 28.0+ |
| `analyticsTags` | 29.0+ |
| `nlSearch` | 29.0+ |
| `mmr` | 30.0+ |
| `collectionCloning` | 30.1+ |
| `synonymSets` | 30.2+ |
| `curationSets` | 30.2+ |
| `personalizationModels` | 30.2+ |

The connected server's available features are listed on the Connection tab of the
settings screen.
