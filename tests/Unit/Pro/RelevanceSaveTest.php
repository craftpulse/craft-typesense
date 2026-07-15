<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The relevance editor's persistence round-trip: a posted relevance payload
 * (boost rules, search preset, grouping) is normalised and written to the
 * collection definition's project-config entry, and read back on reload. The
 * edition gate is enforced server-side: a crafted POST fails closed on Free.
 * The definition is created and removed within the test.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\Entry;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craftpulse\typesense\models\CollectionDefinition;
use craftpulse\typesense\Typesense;

function aRelevanceAdmin(): User
{
    $suffix = bin2hex(random_bytes(4));
    $admin = new User();
    $admin->admin = true;
    $admin->username = "ts_rel_{$suffix}";
    $admin->email = "ts_rel_{$suffix}@example.test";
    Craft::$app->getElements()->saveElement($admin);

    return $admin;
}

function aRelevanceDefinition(): CollectionDefinition
{
    $definition = new CollectionDefinition();
    $definition->name = 'ts_rel_' . bin2hex(random_bytes(4));
    $definition->elementType = Entry::class;
    $definition->multisite = 'sharedWithSiteFilter';
    Typesense::$plugin->getManagedCollections()->save($definition);

    return $definition;
}

function withRelevanceEdition(string $edition, callable $test): void
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

it('persists a posted relevance payload to project config', function() {
    $admin = aRelevanceAdmin();
    $definition = aRelevanceDefinition();

    withRelevanceEdition(Typesense::EDITION_PRO, function() use ($admin, $definition) {
        $this->actingAs($admin)
            ->withExceptionHandling()
            ->post(UrlHelper::actionUrl('typesense/relevance/save'), [
                'uid' => $definition->uid,
                'boostRules' => [
                    ['label' => 'Featured', 'field' => 'featured', 'operator' => 'eq', 'value' => '1', 'weight' => '50'],
                    ['label' => '', 'field' => '', 'operator' => 'eq', 'value' => '', 'weight' => '0'],
                ],
                'num_typos' => '2',
                'prefix' => 'true',
                'groupBy' => 'kind',
                'groupLimit' => '3',
                'textMatchBuckets' => '4',
            ])
            ->assertRedirect();

        $reloaded = Typesense::$plugin->getManagedCollections()->getByUid((string)$definition->uid);
        $relevance = $reloaded->relevance;

        // The blank row is dropped; the real rule becomes a single-condition boost.
        expect($relevance['boostRules'])->toHaveCount(1)
            ->and($relevance['boostRules'][0]['weight'])->toBe(50.0)
            ->and($relevance['boostRules'][0]['conditions'][0]['field'])->toBe('featured')
            ->and($relevance['searchPreset']['num_typos'])->toBe('2')
            ->and($relevance['grouping']['field'])->toBe('kind')
            ->and($relevance['grouping']['limit'])->toBe(3)
            ->and($relevance['textMatchBuckets'])->toBe(4);
    });

    Typesense::$plugin->getManagedCollections()->delete($definition);
});

it('forbids the relevance save on the Free edition (server-side gate)', function() {
    $admin = aRelevanceAdmin();
    $definition = aRelevanceDefinition();

    withRelevanceEdition(Typesense::EDITION_FREE, function() use ($admin, $definition) {
        $this->actingAs($admin)
            ->withExceptionHandling()
            ->post(UrlHelper::actionUrl('typesense/relevance/save'), ['uid' => $definition->uid])
            ->assertForbidden();
    });

    Typesense::$plugin->getManagedCollections()->delete($definition);
});
