<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The embeddings service: the model catalog, the auto-embedding field builder,
 * and remote-config validation (shape and environment checks, never a live
 * call). Pure logic, no server needed.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\typesense\services\Embeddings;
use craftpulse\typesense\Typesense;

function embeddings(): Embeddings
{
    return Typesense::$plugin->getEmbeddings();
}

it('builds an auto-embedding field for a built-in model', function() {
    $field = embeddings()->embedField('embedding', [
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
    expect(embeddings()->embedField('embedding', ['model' => 'ts/e5-small']))->toBeNull()
        ->and(embeddings()->embedField('embedding', ['from' => ['title']]))->toBeNull();
});

it('identifies built-in, image, and remote models', function() {
    expect(embeddings()->isBuiltIn('ts/all-MiniLM-L12-v2'))->toBeTrue()
        ->and(embeddings()->isBuiltIn('openai/text-embedding-3-small'))->toBeFalse()
        ->and(embeddings()->isImageModel('ts/clip-vit-b-p32'))->toBeTrue()
        ->and(embeddings()->isImageModel('ts/all-MiniLM-L12-v2'))->toBeFalse();
});

it('validates a built-in model with no further requirements', function() {
    expect(embeddings()->validateModelConfig(['model' => 'ts/all-MiniLM-L12-v2', 'from' => ['title']]))->toBe([]);
});

it('requires credentials for a remote provider (shape check, no live call)', function() {
    // Missing api_key for OpenAI.
    $errors = embeddings()->validateModelConfig(['model' => 'openai/text-embedding-3-small', 'from' => ['title']]);
    expect($errors)->not->toBe([]);

    // An unknown provider is rejected.
    expect(embeddings()->validateModelConfig(['model' => 'nope/whatever']))->not->toBe([]);

    // A missing model is rejected.
    expect(embeddings()->validateModelConfig([]))->not->toBe([]);
});

it('resolves a remote credential from an environment reference', function() {
    putenv('TS_TEST_EMBED_KEY=sk-live-value');

    try {
        $errors = embeddings()->validateModelConfig([
            'model' => 'openai/text-embedding-3-small',
            'from' => ['title'],
            'config' => ['api_key' => '$TS_TEST_EMBED_KEY'],
        ]);
        expect($errors)->toBe([]);

        $field = embeddings()->embedField('embedding', [
            'model' => 'openai/text-embedding-3-small',
            'from' => ['title'],
            'config' => ['api_key' => '$TS_TEST_EMBED_KEY'],
        ]);
        // The stored reference resolves to its value in the compiled config.
        expect($field->toArray()['embed']['model_config']['api_key'])->toBe('sk-live-value');
    } finally {
        putenv('TS_TEST_EMBED_KEY');
    }
});
