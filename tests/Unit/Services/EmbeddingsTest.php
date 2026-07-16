<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The embeddings service: the built-in model catalog, the auto-embedding field
 * builder (built-in and provider branches), and shape validation (a referenced
 * embedding provider must exist and its credentials must resolve, never a live
 * call).
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\typesense\models\AiProvider;
use craftpulse\typesense\services\AiProviders;
use craftpulse\typesense\services\Embeddings;
use craftpulse\typesense\Typesense;

function embeddings(): Embeddings
{
    return Typesense::$plugin->getEmbeddings();
}

function anEmbeddingProviderForField(string $handle): AiProvider
{
    $provider = new AiProvider();
    $provider->handle = $handle;
    $provider->name = 'OpenAI embeddings';
    $provider->kind = AiProviders::KIND_EMBEDDING;
    $provider->type = 'openai';
    $provider->credentials = ['api_key' => '$TS_TEST_EMBED_KEY'];

    return $provider;
}

it('builds an auto-embedding field for a built-in model', function() {
    $field = embeddings()->embedField('embedding', [
        'builtIn' => true,
        'model' => 'ts/all-MiniLM-L12-v2',
        'from' => ['title', 'description'],
    ]);

    expect($field)->not->toBeNull();

    $schema = $field->toArray();
    expect($schema['type'])->toBe('float[]')
        ->and($schema['num_dim'])->toBe(384)
        ->and($schema['embed']['from'])->toBe(['title', 'description'])
        ->and($schema['embed']['model_config']['model_name'])->toBe('ts/all-MiniLM-L12-v2')
        // A built-in model carries no credential config.
        ->and($schema['embed']['model_config'])->not->toHaveKey('api_key');
});

it('returns no field when the embedding config is incomplete', function() {
    expect(embeddings()->embedField('embedding', ['builtIn' => true, 'model' => 'ts/e5-small']))->toBeNull()
        ->and(embeddings()->embedField('embedding', ['builtIn' => true, 'from' => ['title']]))->toBeNull();
});

it('identifies built-in and image models', function() {
    expect(embeddings()->isBuiltIn('ts/all-MiniLM-L12-v2'))->toBeTrue()
        ->and(embeddings()->isBuiltIn('openai/text-embedding-3-small'))->toBeFalse()
        ->and(embeddings()->isImageModel('ts/clip-vit-b-p32'))->toBeTrue()
        ->and(embeddings()->isImageModel('ts/all-MiniLM-L12-v2'))->toBeFalse();
});

it('carries embedding prefixes into the model config', function() {
    $field = embeddings()->embedField('embedding', [
        'builtIn' => true,
        'model' => 'ts/e5-small',
        'from' => ['title'],
        'indexingPrefix' => 'passage: ',
        'queryPrefix' => 'query: ',
    ]);

    $config = $field->toArray()['embed']['model_config'];
    expect($config['indexing_prefix'])->toBe('passage: ')
        ->and($config['query_prefix'])->toBe('query: ');
});

it('validates a built-in model with no further requirements', function() {
    expect(embeddings()->validateModelConfig(['builtIn' => true, 'model' => 'ts/all-MiniLM-L12-v2', 'from' => ['title']]))->toBe([]);
});

it('rejects a provider branch that references no or an unknown provider', function() {
    // A provider branch with no provider handle.
    expect(embeddings()->validateModelConfig([
        'builtIn' => false,
        'model' => 'openai/text-embedding-3-small',
        'providerHandle' => '',
    ]))->not->toBe([]);

    // An unknown provider handle.
    expect(embeddings()->validateModelConfig([
        'builtIn' => false,
        'model' => 'openai/text-embedding-3-small',
        'providerHandle' => 'does_not_exist',
    ]))->not->toBe([]);

    // A missing model is rejected.
    expect(embeddings()->validateModelConfig([]))->not->toBe([]);
});

it('resolves a referenced provider credential into the compiled config', function() {
    putenv('TS_TEST_EMBED_KEY=sk-live-value');

    try {
        Typesense::$plugin->getAiProviders()->saveProvider(anEmbeddingProviderForField('field_openai'));

        $embedding = [
            'builtIn' => false,
            'model' => 'openai/text-embedding-3-small',
            'providerHandle' => 'field_openai',
            'from' => ['title'],
            'dims' => 1536,
        ];

        expect(embeddings()->validateModelConfig($embedding))->toBe([]);

        $field = embeddings()->embedField('embedding', $embedding);
        $schema = $field->toArray();
        // The provider's stored reference resolves to its value in the compiled config.
        expect($schema['num_dim'])->toBe(1536)
            ->and($schema['embed']['model_config']['model_name'])->toBe('openai/text-embedding-3-small')
            ->and($schema['embed']['model_config']['api_key'])->toBe('sk-live-value');

        Typesense::$plugin->getAiProviders()->deleteProvider('field_openai');
    } finally {
        putenv('TS_TEST_EMBED_KEY');
    }
});
