<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The AI provider registry: provider and conversation-model CRUD in project
 * config, the config-file merge (presence-based read-only ownership), the per-kind
 * type catalog, and credential validation and resolution from environment
 * references (never a live call).
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\typesense\models\AiProvider;
use craftpulse\typesense\models\ConversationModel;
use craftpulse\typesense\services\AiProviders;
use craftpulse\typesense\Typesense;

function aiProviders(): AiProviders
{
    return Typesense::$plugin->getAiProviders();
}

function anEmbeddingProvider(string $handle = 'openai_embed'): AiProvider
{
    $provider = new AiProvider();
    $provider->handle = $handle;
    $provider->name = 'OpenAI embeddings';
    $provider->kind = AiProviders::KIND_EMBEDDING;
    $provider->type = 'openai';
    $provider->credentials = ['api_key' => '$TS_TEST_PROVIDER_KEY'];

    return $provider;
}

function aConversationProvider(string $handle = 'openai_chat'): AiProvider
{
    $provider = new AiProvider();
    $provider->handle = $handle;
    $provider->name = 'OpenAI chat';
    $provider->kind = AiProviders::KIND_CONVERSATION;
    $provider->type = 'openai';
    $provider->credentials = ['api_key' => '$TS_TEST_PROVIDER_KEY'];

    return $provider;
}

it('round-trips a managed provider through project config', function() {
    putenv('TS_TEST_PROVIDER_KEY=sk-live');

    try {
        expect(aiProviders()->saveProvider(anEmbeddingProvider('rt_provider')))->toBeTrue();

        $loaded = aiProviders()->getProvider('rt_provider');
        expect($loaded)->not->toBeNull()
            ->and($loaded->type)->toBe('openai')
            ->and($loaded->kind)->toBe(AiProviders::KIND_EMBEDDING)
            ->and($loaded->readOnly)->toBeFalse()
            // The stored credential is the reference, never the resolved secret.
            ->and($loaded->credentials['api_key'])->toBe('$TS_TEST_PROVIDER_KEY');

        aiProviders()->deleteProvider('rt_provider');
        expect(aiProviders()->getProvider('rt_provider'))->toBeNull();
    } finally {
        putenv('TS_TEST_PROVIDER_KEY');
    }
});

it('filters providers by kind', function() {
    putenv('TS_TEST_PROVIDER_KEY=sk-live');

    try {
        aiProviders()->saveProvider(anEmbeddingProvider('kind_embed'));
        aiProviders()->saveProvider(aConversationProvider('kind_chat'));

        expect(aiProviders()->getProviders(AiProviders::KIND_EMBEDDING))->toHaveKey('kind_embed')
            ->and(aiProviders()->getProviders(AiProviders::KIND_EMBEDDING))->not->toHaveKey('kind_chat')
            ->and(aiProviders()->getProviders(AiProviders::KIND_CONVERSATION))->toHaveKey('kind_chat')
            ->and(aiProviders()->getProviders(AiProviders::KIND_CONVERSATION))->not->toHaveKey('kind_embed');

        aiProviders()->deleteProvider('kind_embed');
        aiProviders()->deleteProvider('kind_chat');
    } finally {
        putenv('TS_TEST_PROVIDER_KEY');
    }
});

it('rejects an unknown type, a missing endpoint, and an unresolved credential', function() {
    // Unknown type for the kind.
    $bad = anEmbeddingProvider();
    $bad->type = 'nope';
    expect(aiProviders()->validateProvider($bad))->not->toBe([]);

    // Endpoint-requiring type without an endpoint (Azure).
    $azure = anEmbeddingProvider();
    $azure->type = 'azure';
    $azure->endpoint = '';
    expect(aiProviders()->validateProvider($azure))->not->toBe([]);

    // Credential reference that resolves to nothing.
    $unresolved = anEmbeddingProvider();
    $unresolved->credentials = ['api_key' => '$TS_UNSET_KEY_XYZ'];
    expect(aiProviders()->validateProvider($unresolved))->not->toBe([]);
});

it('validates and resolves a provider whose credentials resolve from the environment', function() {
    putenv('TS_TEST_PROVIDER_KEY=sk-resolved');

    try {
        $provider = anEmbeddingProvider();
        expect(aiProviders()->validateProvider($provider))->toBe([])
            ->and(aiProviders()->resolveCredentials($provider))->toBe(['api_key' => 'sk-resolved']);
    } finally {
        putenv('TS_TEST_PROVIDER_KEY');
    }
});

it('round-trips a conversation model and ties it to a conversation provider', function() {
    putenv('TS_TEST_PROVIDER_KEY=sk-live');

    try {
        aiProviders()->saveProvider(aConversationProvider('cm_provider'));

        $model = new ConversationModel();
        $model->handle = 'advisor';
        $model->name = 'Advisor';
        $model->providerHandle = 'cm_provider';
        $model->modelName = 'gpt-4o-mini';
        $model->historyCollection = 'conversation_store';
        $model->systemPrompt = 'Answer from the docs.';

        expect(aiProviders()->validateConversationModel($model))->toBe([])
            ->and(aiProviders()->saveConversationModel($model))->toBeTrue();

        $loaded = aiProviders()->getConversationModel('advisor');
        expect($loaded)->not->toBeNull()
            ->and($loaded->providerHandle)->toBe('cm_provider')
            ->and($loaded->modelName)->toBe('gpt-4o-mini');

        aiProviders()->deleteConversationModel('advisor');
        aiProviders()->deleteProvider('cm_provider');
    } finally {
        putenv('TS_TEST_PROVIDER_KEY');
    }
});

it('rejects a conversation model pointing at a missing or embedding-kind provider', function() {
    putenv('TS_TEST_PROVIDER_KEY=sk-live');

    try {
        aiProviders()->saveProvider(anEmbeddingProvider('wrong_kind'));

        $model = new ConversationModel();
        $model->handle = 'advisor';
        $model->name = 'Advisor';
        $model->providerHandle = 'does_not_exist';
        $model->modelName = 'gpt-4o-mini';
        $model->historyCollection = 'conversation_store';
        expect(aiProviders()->validateConversationModel($model))->not->toBe([]);

        // Pointing at an embedding-kind provider is rejected.
        $model->providerHandle = 'wrong_kind';
        expect(aiProviders()->validateConversationModel($model))->not->toBe([]);

        aiProviders()->deleteProvider('wrong_kind');
    } finally {
        putenv('TS_TEST_PROVIDER_KEY');
    }
});

it('treats config-file declarations as read-only and lets them win over managed ones', function() {
    putenv('TS_TEST_PROVIDER_KEY=sk-live');

    /** @var \craftpulse\typesense\models\Settings $settings */
    $settings = Typesense::$plugin->getSettings();
    $originalProviders = $settings->aiProviders;

    try {
        aiProviders()->saveProvider(anEmbeddingProvider('shared_handle'));

        // A config-file provider under the same handle overrides the managed one
        // and is read-only.
        $settings->aiProviders = [
            'shared_handle' => [
                'name' => 'Config OpenAI',
                'kind' => AiProviders::KIND_EMBEDDING,
                'type' => 'openai',
                'credentials' => ['api_key' => '$TS_TEST_PROVIDER_KEY'],
            ],
        ];

        $winner = aiProviders()->getProvider('shared_handle');
        expect($winner)->not->toBeNull()
            ->and($winner->name)->toBe('Config OpenAI')
            ->and($winner->readOnly)->toBeTrue()
            // The managed store keeps its own copy, unflagged.
            ->and(aiProviders()->getManagedProviders()['shared_handle']->readOnly)->toBeFalse();

        aiProviders()->deleteProvider('shared_handle');
    } finally {
        $settings->aiProviders = $originalProviders;
        putenv('TS_TEST_PROVIDER_KEY');
    }
});

it('refuses to save a provider onto an existing handle (no silent clobber)', function() {
    putenv('TS_TEST_PROVIDER_KEY=sk-live');

    try {
        aiProviders()->saveProvider(anEmbeddingProvider('clash_a'));
        aiProviders()->saveProvider(anEmbeddingProvider('clash_b'));

        // A rename of clash_b onto the existing clash_a (target handle taken) must
        // fail with a handle error, not clobber clash_a.
        $rename = anEmbeddingProvider('clash_a');
        expect(aiProviders()->saveProvider($rename, 'clash_b'))->toBeFalse()
            ->and($rename->getErrors('handle'))->not->toBeEmpty();

        // A genuine in-place update (handle unchanged from the original) succeeds.
        expect(aiProviders()->saveProvider(anEmbeddingProvider('clash_a'), 'clash_a'))->toBeTrue();

        aiProviders()->deleteProvider('clash_a');
        aiProviders()->deleteProvider('clash_b');
    } finally {
        putenv('TS_TEST_PROVIDER_KEY');
    }
});

it('carries the anthropic gateway preset as a distinct conversation type over the custom shape', function() {
    $catalog = AiProviders::TYPES[AiProviders::KIND_CONVERSATION];

    expect($catalog)->toHaveKey('anthropic-gateway')
        ->and($catalog['anthropic-gateway']['presetOf'])->toBe('custom')
        ->and($catalog['anthropic-gateway']['endpoint'])->toBeTrue()
        ->and($catalog['anthropic-gateway']['docUrl'])->not->toBe('');
});
