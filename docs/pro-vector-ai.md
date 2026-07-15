---
title: Vector and AI (Pro)
description: Auto-embedding, hybrid search, similar items, CLIP image search, and the experimental AI features
---
# Vector and AI (Pro)

The Pro edition adds semantic search: auto-embedding, hybrid (keyword + vector)
search, similar-items recommendations, CLIP image search, and a set of
experimental AI features.

## Editions and permissions

Vector configuration lives on the Vector / AI tab of a control-panel-managed
collection's edit screen (Typesense, then Collections, then a collection, then
the Vector / AI tab), gated by `typesense:manageRelevance`. The search helpers
are Free-callable from Twig once a collection has an embedding field.

## Auto-embedding

Turn on auto-embedding for a control-panel-managed collection and pick a model
and the source fields. Typesense generates the vectors server-side at index time,
so you index plain text and get semantic search for free.

Changing the model or the source fields **re-embeds every document**: re-sync the
collection after saving. The editor surfaces this, and the cost, honestly.

### Models

| Kind | Examples | Notes |
| --- | --- | --- |
| Built-in (`ts/*`) | `ts/all-MiniLM-L12-v2` (English), `ts/e5-small` (multilingual), `ts/clip-vit-b-p32` (image) | Local ONNX inference on the server CPU, no API key. Downloaded on first use. |
| Remote | `openai/text-embedding-3-small`, `azure/...`, `google/...`, `gcp/...`, `cloudflare/...`, custom OpenAI-compatible | An API call per document at index time (cost). |

Remote credentials are read from **environment variables**: enter the environment
reference (for example `$OPENAI_API_KEY`) in the editor, never the raw key. The
plugin validates the config's shape and that the referenced variable resolves,
but never makes a live call from the control panel.

## Hybrid search

Hybrid search fuses keyword and semantic results:

```twig
{% set results = craft.typesense.search('products', {
    q: 'running shoes',
    query_by: 'title,embedding',
    vector_query: 'embedding:([], alpha: 0.3)',
}) %}
```

Or use the service helper (`Search::hybridSearch`), which mixes the vector field
into `query_by`, sets the alpha, excludes the vector from the response, and, on a
v28+ server, applies `rerank_hybrid_matches` for accuracy. Alpha weights the
semantic side: 0 keyword only, around 0.3 for most UIs, 1 pure semantic.

## Similar items

```twig
{% set related = craft.typesense.similar(entry, 'products', 6) %}
```

Finds items near the given element (or document id) by vector similarity. The
collection needs an auto-embedding field.

## CLIP image search

Choose the CLIP model (`ts/clip-vit-b-p32`) and point the source field at the
asset image. At index time the plugin streams the asset file (in bounded-memory
chunks) and base64-encodes it, and Typesense embeds the image. You can then search
images by a text description, or find visually similar images. Like all
embeddings, CLIP runs on the server CPU and a large image set takes time to
embed.

## Experimental AI features

These are shown in the editor only when the server supports them, each with its
honest trade-off. They are opt-in.

| Feature | Availability | The honest reason |
| --- | --- | --- |
| Conversational RAG | Any server with a conversation model | Each conversational query makes a per-search LLM call (cost). |
| Natural-language search | v29+ | The model can emit invalid filter syntax that is retried, adding latency. |
| Native personalization | v30.2+ | Undocumented upstream and driven by analytics log rules; treat as unstable. |
| User-level BYO re-ranking | Any (external) | Requires a re-ranking model you host and operate. |

### Conversational RAG

Configure a conversation model (env-keyed) via the `AiModels` service, then ask:

```twig
{% set answer = craft.typesense.ask('products', 'red running shoes under 100', 'shop-advisor', { query_by: 'title,embedding' }) %}
{# answer.conversation.answer holds the generated reply #}
```

The api key is read from an environment variable and never stored raw; the config
is validated without a live call.

### Natural-language search

`Search::naturalLanguageSearch()` passes `nl_query` on a v29+ server and falls
back to a plain search elsewhere (hide, never badge).

### Personalization and BYO re-ranking

Native personalization (v30.2+) wires into analytics log rules and is treated as
unstable; user-level BYO re-ranking requires an external model. Both are surfaced
as experimental where the server supports them and are documented rather than
enabled by default.
