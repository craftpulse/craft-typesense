<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The embedding config's persistence and compilation: a posted embedding config
 * round-trips through project config (and an invalid remote config is rejected),
 * and a CLIP embedding config compiles into a collection with an image source
 * field marked for the base64 document pipeline. No server needed.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craftpulse\typesense\models\CollectionDefinition;
use craftpulse\typesense\Typesense;

function anEmbeddingAdmin(): User
{
    $suffix = bin2hex(random_bytes(4));
    $admin = new User();
    $admin->admin = true;
    $admin->username = "ts_embed_{$suffix}";
    $admin->email = "ts_embed_{$suffix}@example.test";
    Craft::$app->getElements()->saveElement($admin);

    return $admin;
}

function anEmbeddingDefinition(): CollectionDefinition
{
    $definition = new CollectionDefinition();
    $definition->name = 'ts_embed_def_' . bin2hex(random_bytes(4));
    $definition->elementType = Entry::class;
    $definition->multisite = 'sharedWithSiteFilter';
    Typesense::$plugin->getManagedCollections()->save($definition);

    return $definition;
}

function withEmbeddingEdition(string $edition, callable $test): void
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

it('round-trips a built-in embedding config through the editor', function() {
    $admin = anEmbeddingAdmin();
    $definition = anEmbeddingDefinition();

    withEmbeddingEdition(Typesense::EDITION_PRO, function() use ($admin, $definition) {
        $this->actingAs($admin)
            ->withExceptionHandling()
            ->post(UrlHelper::actionUrl('typesense/relevance/save-vector'), [
                'uid' => $definition->uid,
                'embedEnabled' => '1',
                'embedBranch' => 'builtin',
                'embedBuiltinModel' => 'ts/all-MiniLM-L12-v2',
                'embedFrom' => "title\nbody",
            ])
            ->assertRedirect();

        $reloaded = Typesense::$plugin->getManagedCollections()->getByUid((string)$definition->uid);
        expect($reloaded->embedding['enabled'])->toBeTrue()
            ->and($reloaded->embedding['model'])->toBe('ts/all-MiniLM-L12-v2')
            ->and($reloaded->embedding['from'])->toBe(['title', 'body']);
    });

    Typesense::$plugin->getManagedCollections()->delete($definition);
});

it('rejects an enabled remote embedding config with no credentials', function() {
    $admin = anEmbeddingAdmin();
    $definition = anEmbeddingDefinition();

    withEmbeddingEdition(Typesense::EDITION_PRO, function() use ($admin, $definition) {
        // asFailure on a non-ajax post sets a flash and redirects back; the save
        // must not persist the invalid config.
        $this->actingAs($admin)
            ->withExceptionHandling()
            ->post(UrlHelper::actionUrl('typesense/relevance/save-vector'), [
                'uid' => $definition->uid,
                'embedEnabled' => '1',
                'embedBranch' => 'provider',
                'embedModelName' => 'openai/text-embedding-3-small',
                'embedProvider' => 'ts_missing_provider',
                'embedFrom' => 'title',
            ]);

        $reloaded = Typesense::$plugin->getManagedCollections()->getByUid((string)$definition->uid);
        expect($reloaded->embedding['enabled'] ?? false)->toBeFalse();
    });

    Typesense::$plugin->getManagedCollections()->delete($definition);
});

it('compiles a CLIP embedding into an image source field marked for base64', function() {
    $definition = new CollectionDefinition();
    $definition->name = 'ts_clip_def';
    $definition->elementType = \craft\elements\Asset::class;
    $definition->multisite = 'sharedWithSiteFilter';
    $definition->embedding = [
        'enabled' => true,
        'builtIn' => true,
        'model' => 'ts/clip-vit-b-p32',
        'from' => ['image'],
    ];

    $collection = Typesense::$plugin->getCompiler()->compile($definition);

    expect($collection->getImageEmbedField())->toBe('image');

    $fieldNames = array_map(static fn($f): string => $f->toArray()['name'], $collection->getFields());
    expect($fieldNames)->toContain('image')
        ->and($fieldNames)->toContain('embedding');
});
