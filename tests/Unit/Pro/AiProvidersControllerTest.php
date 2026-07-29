<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The Pro AI providers controller: authoring a provider persists it to project
 * config with credentials kept as environment references, and the save/delete
 * path is edition- and permission-gated server-side (a crafted POST fails closed
 * on Free and for a user without manage-ai-providers). The edition and permission
 * deny sides across every Pro controller are pinned systematically in
 * GatingMatrixTest; here we pin the happy authoring path and the model gating.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\User;
use craft\helpers\UrlHelper;
use craftpulse\typesense\Typesense;

function anAiProvidersAdmin(): User
{
    $suffix = bin2hex(random_bytes(4));
    $admin = new User();
    $admin->admin = true;
    $admin->username = "ts_ai_{$suffix}";
    $admin->email = "ts_ai_{$suffix}@example.test";
    Craft::$app->getElements()->saveElement($admin);

    return $admin;
}

function withAiProvidersEdition(string $edition, callable $test): void
{
    $plugin = Typesense::$plugin;
    $original = $plugin->edition;
    $plugin->edition = $edition;

    try {
        $test();
    } finally {
        $plugin->edition = $original;
    }
}

it('forbids provider creation on the Free edition (server-side edition gate)', function() {
    $admin = anAiProvidersAdmin();

    withAiProvidersEdition(Typesense::EDITION_FREE, function() use ($admin) {
        $this->actingAs($admin)
            ->withExceptionHandling()
            ->post(UrlHelper::actionUrl('typesense/ai-providers/save-provider'), [
                'handle' => 'x', 'name' => 'X', 'kind' => 'embedding', 'type' => 'openai',
            ])
            ->assertForbidden();
    });
});

it('persists a provider with credentials kept as environment references', function() {
    putenv('TS_TEST_CTRL_KEY=sk-live');
    $admin = anAiProvidersAdmin();

    try {
        withAiProvidersEdition(Typesense::EDITION_PRO, function() use ($admin) {
            $this->actingAs($admin)
                ->post(UrlHelper::actionUrl('typesense/ai-providers/save-provider'), [
                    'handle' => 'ctrl_openai',
                    'name' => 'Controller OpenAI',
                    'kind' => 'embedding',
                    'type' => 'openai',
                    'cred_openai_api_key' => '$TS_TEST_CTRL_KEY',
                    'enabled' => '1',
                ]);

            $saved = Typesense::$plugin->getAiProviders()->getProvider('ctrl_openai');
            expect($saved)->not->toBeNull()
                ->and($saved->type)->toBe('openai')
                ->and($saved->credentials['api_key'])->toBe('$TS_TEST_CTRL_KEY');

            Typesense::$plugin->getAiProviders()->deleteProvider('ctrl_openai');
        });
    } finally {
        putenv('TS_TEST_CTRL_KEY');
    }
});

it('rejects a conversation model whose provider does not exist', function() {
    $admin = anAiProvidersAdmin();

    withAiProvidersEdition(Typesense::EDITION_PRO, function() use ($admin) {
        $this->actingAs($admin)
            ->withExceptionHandling()
            ->post(UrlHelper::actionUrl('typesense/ai-providers/save-model'), [
                'handle' => 'orphan',
                'name' => 'Orphan',
                'providerHandle' => 'does_not_exist',
                'modelName' => 'gpt-4o-mini',
                'historyCollection' => 'conversation_store',
            ]);

        // The instance is never written because its provider reference is invalid.
        expect(Typesense::$plugin->getAiProviders()->getConversationModel('orphan'))->toBeNull();
    });
});
