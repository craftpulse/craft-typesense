<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The experimental AI model service: conversation-model config validation (shape
 * and environment references, never a live LLM call) and the capability-gated
 * experimental-feature list (version-gated features hidden when unsupported).
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\typesense\services\AiModels;
use craftpulse\typesense\Typesense;

function aiModels(): AiModels
{
    return Typesense::$plugin->getAiModels();
}

it('requires the core fields and a resolvable api key for a conversation model', function() {
    // Missing everything.
    expect(aiModels()->validateConversationModel([]))->not->toBe([]);

    // Missing api_key.
    $errors = aiModels()->validateConversationModel([
        'id' => 'advisor',
        'model_name' => 'openai/gpt-4o-mini',
        'history_collection' => 'conversation_store',
    ]);
    expect($errors)->not->toBe([]);
});

it('validates a conversation model whose api key resolves from the environment', function() {
    putenv('TS_TEST_LLM_KEY=sk-live');

    try {
        $errors = aiModels()->validateConversationModel([
            'id' => 'advisor',
            'model_name' => 'openai/gpt-4o-mini',
            'history_collection' => 'conversation_store',
            'api_key' => '$TS_TEST_LLM_KEY',
        ]);
        expect($errors)->toBe([]);
    } finally {
        putenv('TS_TEST_LLM_KEY');
    }
});

it('never upserts a conversation model with an invalid config (no live call)', function() {
    // An unresolved api_key fails validation, so no request is made and null is returned.
    expect(aiModels()->upsertConversationModel([
        'id' => 'advisor',
        'model_name' => 'openai/gpt-4o-mini',
        'history_collection' => 'conversation_store',
        'api_key' => '$TS_UNSET_LLM_KEY_XYZ',
    ]))->toBeNull();
});

it('refuses personalization log wiring on a server without native personalization', function() {
    // The v28 playground has no native personalization (v30.2+), so the gate is
    // closed and no analytics rule is created (hide, never badge).
    $capabilities = Typesense::$plugin->getClient()->getServerCapabilities();

    expect($capabilities?->personalizationModels() ?? false)->toBeFalse()
        ->and(aiModels()->configurePersonalizationLog('heroes', 'ts_pest_personalization'))->toBeFalse();
});

it('lists RAG and BYO experimental features and gates the version-locked ones', function() {
    $keys = array_column(aiModels()->experimentalFeatures(), 'key');

    // RAG and BYO re-ranking are always offered (with their honest reasons).
    expect($keys)->toContain('rag')
        ->and($keys)->toContain('byoReranking');

    // On the v28 playground, NL search (v29+) and personalization (v30.2+) are
    // hidden, not badged.
    $capabilities = Typesense::$plugin->getClient()->getServerCapabilities();

    if (!($capabilities?->nlSearch() ?? false)) {
        expect($keys)->not->toContain('nlSearch');
    }

    if (!($capabilities?->personalizationModels() ?? false)) {
        expect($keys)->not->toContain('personalization');
    }

    // Each feature carries a non-empty honest reason.
    foreach (aiModels()->experimentalFeatures() as $feature) {
        expect($feature['reason'])->not->toBe('');
    }
});
