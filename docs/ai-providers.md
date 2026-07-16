---
title: AI providers (Pro)
description: Managing AI providers and conversation models for embedding and conversational search
---
# AI providers (Pro)

The AI providers screen (Typesense, then AI providers) is the single place you
manage the credentials and endpoints the AI features draw on. It holds two
resources:

- **AI providers**: a credential-and-endpoint record for one subsystem. A
  provider names its `kind` (embedding or conversation), a provider `type`, an
  optional endpoint URL, and per-type credentials.
- **Conversation models**: a named answer configuration a searchable collection
  opts into with `->ask('handle')`. It references a conversation provider and adds
  the model name, system prompt, history collection, TTL, and answer byte budget.

Gated by the edition and the `typesense:manageAiProviders` permission.

## Credentials are environment references, never secrets

Every credential and endpoint is stored as an **environment variable reference**
(for example `$OPENAI_API_KEY`), never a raw secret. The reference is resolved to
its value only at the moment a config is sent to Typesense (when a collection
rebuilds with an embedding field, or a conversation model is pushed). The plugin
validates that a reference resolves to a non-empty value, but never makes a live
model call from the control panel. The admin API key and provider secrets never
reach the browser.

## Provider ownership

A provider or conversation model authored in the control panel lives in project
config, keyed by handle, so it deploys across environments. A provider or model
declared in `config/typesense.php` is **read-only** in the control panel
(presence-based ownership): the config file wins, and the screen shows it with a
"Config file" source and no edit or delete affordance.

```php
// config/typesense.php
return [
    'aiProviders' => [
        'openaiEmbed' => [
            'name' => 'OpenAI embeddings',
            'kind' => 'embedding',
            'type' => 'openai',
            'credentials' => ['api_key' => '$OPENAI_API_KEY'],
        ],
    ],
    'conversationModels' => [
        'shopAdvisor' => [
            'name' => 'Shop advisor',
            'providerHandle' => 'anthropicChat',
            'modelName' => 'claude-3-5-sonnet',
            'systemPrompt' => 'Answer only from the provided products.',
            'historyCollection' => 'conversation_store',
        ],
    ],
];
```

## Embedding providers

Embedding providers supply credentials for a remote embedding model. Choose one on
a collection's Vector / AI section, then type the remote model name Typesense
expects (for example `openai/text-embedding-3-small`) and the output dimensions.
See the vector and AI guide for the full embedding flow.

| Type | Credentials | Endpoint |
| --- | --- | --- |
| OpenAI | `api_key` | no |
| Azure OpenAI | `api_key` | yes |
| Google AI (PaLM) | `api_key` | no |
| GCP Vertex AI | `access_token`, `refresh_token`, `client_id`, `client_secret`, `project_id` | no |
| Cloudflare Workers AI | `api_key`, `account_id` | no |
| OpenAI-compatible (custom) | `api_key` | yes |

## Conversation providers

Conversation providers supply credentials for the conversational RAG model.

| Type | Credentials | Endpoint |
| --- | --- | --- |
| OpenAI | `api_key` | no |
| Cloudflare Workers AI | `api_key`, `account_id` | no |
| vLLM (self-hosted) | none | yes |
| OpenAI-compatible (custom) | `api_key` | yes |
| Anthropic (via OpenAI-compatible gateway) | `api_key` | yes |

### Anthropic gateway walkthrough {#anthropic-gateway}

Typesense has no native Anthropic conversation provider. To answer with an
Anthropic model, put an **OpenAI-compatible gateway** in front of Anthropic (for
example a small proxy that translates the OpenAI chat-completions API to
Anthropic's Messages API, or a hosted gateway that exposes an OpenAI-compatible
endpoint), then point a conversation provider at it.

The screen offers this as a guided preset, "Anthropic (via OpenAI-compatible
gateway)", so the intent is explicit:

1. Stand up an OpenAI-compatible gateway to Anthropic and note its base URL.
2. New conversation provider, type **Anthropic (via OpenAI-compatible gateway)**.
3. Set **Endpoint URL** to the gateway base URL (an env reference, for example
   `$ANTHROPIC_GATEWAY_URL`).
4. Set **api_key** to the gateway key (for example `$ANTHROPIC_GATEWAY_KEY`).
5. Save the provider, then create a conversation model that references it, with
   the Anthropic model name your gateway expects (for example
   `claude-3-5-sonnet`).

The preset is the OpenAI-compatible type with a documentation link; nothing about
the storage or resolution differs, so you can also declare it in config.

## Conversation models

A conversation model instance is a named answer configuration. Its **handle** is
also the Typesense server model id, and the value a collection references with
`->ask('handle')`.

- **Push to server** creates or updates the model on Typesense, resolving the
  provider's credentials at push time and creating the history collection if
  needed. Authoring (Save) never calls the server; pushing is the explicit server
  operation. The list shows whether each model is present on the server.
- Deleting a model removes it from both project config and the server.

Every question sent to a conversation model is a paid LLM call. Opt in
deliberately per collection, and set your abuse policy at the edge (a CDN or WAF
rate rule); the plugin ships a per-IP throttle knob (see settings), not a policy.
