---
title: Vector and AI (Pro)
description: Auto-embedding, hybrid search, similar items, CLIP image search, and the experimental AI features
---
# Vector and AI (Pro)

The Pro edition adds semantic search: auto-embedding, hybrid (keyword + vector)
search, similar-items recommendations, CLIP image search, and a set of
experimental AI features.

## Editions and permissions

Vector and conversation configuration lives on the **Vector / AI** section of a
control-panel-managed collection's edit screen (Typesense, then Collections, then
a collection, then Vector / AI), gated by `typesense:manage-relevance`. It has two
anchor panes: **Embedding** and **Conversation**. The credentials the panes draw
on are authored separately on the **AI providers** screen (see the
[AI providers guide](ai-providers.md)), gated by `typesense:manage-ai-providers`.
The search helpers are Free-callable from Twig once a collection has an embedding
field.

## Auto-embedding

On the Embedding pane, turn on auto-embedding, choose the model source, and set
the source fields. Typesense generates the vectors server-side at index time, so
you index plain text and get semantic search for free.

Changing the model or the source fields **re-embeds every document**: re-sync the
collection after saving. The editor surfaces this, and the cost, honestly.

### Model source: built-in or provider

The Embedding pane has two branches:

| Branch | What you set | Notes |
| --- | --- | --- |
| Built-in | A `ts/*` model | Local ONNX inference on the server CPU, no API key. `ts/all-MiniLM-L12-v2` (English), `ts/e5-small` (multilingual), `ts/clip-vit-b-p32` (image). Downloaded on first use. |
| Provider | An embedding provider + the remote model name + output dimensions | An API call per document at index time (cost). The provider supplies the credentials and endpoint. |

For the provider branch, first create an embedding provider on the AI providers
screen (OpenAI, Azure, Google, GCP Vertex, Cloudflare, or an OpenAI-compatible
endpoint). Its credentials are environment references, never raw keys, resolved
only when the collection rebuilds. Then, on the Embedding pane, select the
provider, type the remote model name Typesense expects (for example
`openai/text-embedding-3-small`), and set the output dimensions the model
produces (for example 1536). Optional indexing and query prefixes are available
for models that expect them (for example E5's `passage: ` / `query: `).

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

## Conversational RAG

On the Conversation pane, opt the collection into the conversational ask endpoint
by selecting a **conversation model instance**. Conversation models are authored
on the AI providers screen: create a conversation provider (OpenAI, Cloudflare,
vLLM, an OpenAI-compatible endpoint, or the Anthropic gateway preset), then a
conversation model that references it. Push the model to the server to make it
answerable, then choose it here and save.

Every question is a paid per-question LLM call, so the endpoint is off unless a
collection opts in. Set your abuse policy at the edge (a CDN or WAF rate rule);
the plugin ships a per-IP throttle knob (see settings), not a policy.

```twig
{% set answer = craft.typesense.ask('products', 'red running shoes under 100', 'shopAdvisor', { query_by: 'title,embedding' }) %}
{# answer.conversation.answer holds the generated reply #}
```

The third argument is the conversation model instance handle. In fluent config a
collection opts in with `->ask('handle')`, referencing a `conversationModels`
declaration.

### Anthropic

Typesense has no native Anthropic conversation provider. Answer with an Anthropic
model by putting an OpenAI-compatible gateway in front of it and using the
Anthropic gateway preset. See the
[AI providers guide](ai-providers.md#anthropic-gateway).

## Other experimental AI features

| Feature | Availability | The honest reason |
| --- | --- | --- |
| Natural-language search | v29+ | The model can emit invalid filter syntax that is retried, adding latency. |
| Native personalization | v30.2+ | Undocumented upstream and driven by analytics log rules; treat as unstable. |
| User-level BYO re-ranking | Any (external) | Requires a re-ranking model you host and operate. |

`Search::naturalLanguageSearch()` passes `nl_query` on a v29+ server and falls
back to a plain search elsewhere (hide, never badge). Native personalization
(v30.2+) wires into analytics log rules via `Analytics::configurePersonalizationLog()`
and is treated as unstable; user-level BYO re-ranking requires an external model.
