---
title: Conversational ask (RAG)
description: The anonymous ask endpoint, its opt-in, knobs, and cost exposure
---
# Conversational ask (RAG)

The plugin exposes an anonymous front-end endpoint that answers free-form
questions about a collection using Typesense's built-in Retrieval Augmented
Generation (conversational search). It is **off by default** and **opt-in per
collection**.

> Every request is a per-question call to an LLM (through your Typesense
> conversation model), so it has a real, per-request cost. This endpoint ships a
> throttle knob and a sane default, not a policy: abuse limiting is your
> deployment decision. Put a real rate limiter (a CDN or WAF rule) in front of a
> public site, exactly as you would for any endpoint that costs money per hit.

## Opting in

A collection opts in by declaring a conversation model in `config/typesense.php`.
It must also be `searchable()` (the ask endpoint shares the front-end search
trust boundary):

```php
use craftpulse\typesense\builders\Collection;

Collection::make('products')
    ->searchable()
    ->ask('conv-model-1'); // your Typesense conversation model id
```

Set up the conversation model itself (and its LLM API key) in Typesense per the
[conversational search docs](https://typesense.org/docs/latest/api/conversational-search-rag.html).
Without `->ask(...)`, the endpoint 404s and the example ask page renders an
honest "not configured" notice.

## The endpoint

`GET` or `POST` `typesense/search/ask` with:

| Parameter | Purpose |
| --- | --- |
| `collection` | The opted-in collection handle (required). |
| `q` | The question (required, truncated to the max length). |
| `queryBy` | The fields the RAG retrieval matches against (include your embedding field for best retrieval). |

It answers one-shot (the full answer plus the cited source hits, as `text/html`)
on the no-JS path and on servers without streaming. On a streaming server
(Typesense v29+) a Datastar request gets the answer token-by-token over SSE:
`datastar-patch-elements` (append) into `#ts-answer`, then the cited hits patched
into `#ts-answer-sources`. The capability is detected automatically; older
servers fall back to one-shot.

## Knobs (developer decisions)

| Setting | Default | What it does |
| --- | --- | --- |
| `askThrottlePerWindow` | `20` | Max ask requests per IP per window. A cheap cache throttle, not a security boundary. |
| `askThrottleWindowSeconds` | `60` | The throttle window, in seconds. |
| `askMaxQuestionLength` | `500` | Questions longer than this are truncated before reaching the model. |

```php
return [
    'askThrottlePerWindow' => 10,
    'askThrottleWindowSeconds' => 60,
    'askMaxQuestionLength' => 300,
];
```

The admin API key never reaches the browser: the endpoint runs the conversation
server-side, exactly like the search proxy. The example bundle ships an `ask`
page as quick-start scaffolding (question box, streamed answer, cited hits), which
renders the "not configured" notice until a collection opts in.
