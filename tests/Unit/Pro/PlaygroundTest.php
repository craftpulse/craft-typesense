<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The Pro search playground controller paths, against the real heroes
 * collection: a search-params body runs and returns ranked hits with text-match
 * scores and timing, diff mode returns a coherent before/after for a pending
 * overlay, the schema and document actions feed the docs-explorer side pane, and
 * the one unified screen renders (the retired browser URL redirects into it).
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\User;
use craft\helpers\UrlHelper;
use craftpulse\typesense\Typesense;

function aPlaygroundAdmin(): User
{
    $suffix = bin2hex(random_bytes(4));
    $user = new User();
    $user->admin = true;
    $user->username = "ts_pg_{$suffix}";
    $user->email = "ts_pg_{$suffix}@example.test";
    Craft::$app->getElements()->saveElement($user);

    return $user;
}

/**
 * The fixture "heroes" collection's real document count (see
 * tests/Support/typesense-fixtures.php), read fresh rather than hardcoded -
 * a hardcoded count is exactly what drifted against the shared live
 * "heroes" collection before this suite owned its own Typesense-side
 * fixture.
 *
 * @return int
 */
function playgroundHeroesDocumentCount(): int
{
    $registry = Typesense::$plugin->getCollectionRegistry();
    $collection = $registry->get('heroes');
    $target = $registry->resolveName($collection, Craft::$app->getSites()->getPrimarySite()->id);

    return (int)Typesense::$plugin->getClient()->client()->collections[$target]->retrieve()['num_documents'];
}

it('runs a search-params body and returns ranked hits with scores and timing', function() {
    $found = playgroundHeroesDocumentCount();

    $this->actingAs(aPlaygroundAdmin())
        ->post(UrlHelper::actionUrl('typesense/playground/run'), [
            'collection' => 'heroes',
            'body' => '{"q": "*", "query_by": "title", "per_page": 5}',
        ])
        ->assertOk()
        ->assertSee("\"found\":{$found}", false)
        ->assertSee('"searchTimeMs"', false)
        ->assertSee('"hits"', false)
        ->assertSee('"textMatch"', false);
});

it('returns a coherent before/after diff for a pending overlay', function() {
    $this->actingAs(aPlaygroundAdmin())
        ->post(UrlHelper::actionUrl('typesense/playground/diff'), [
            'collection' => 'heroes',
            'body' => '{"q": "*", "query_by": "title", "per_page": 5}',
            'overlay' => '{"query_by_weights": "3"}',
        ])
        ->assertOk()
        ->assertSee('"current"', false)
        ->assertSee('"proposed"', false)
        ->assertSee('"entered"', false)
        ->assertSee('"dropped"', false)
        ->assertSee('"moved"', false);
});

it('pages the real indexed documents for the docs-explorer', function() {
    $found = playgroundHeroesDocumentCount();

    $this->actingAs(aPlaygroundAdmin())
        ->post(UrlHelper::actionUrl('typesense/playground/documents'), [
            'collection' => 'heroes',
            'page' => 1,
            'perPage' => 5,
        ])
        ->assertOk()
        ->assertSee("\"found\":{$found}", false)
        ->assertSee('"documents"', false)
        ->assertSee("\"numDocuments\":{$found}", false);
});

it('returns the live schema for the docs-explorer', function() {
    $this->actingAs(aPlaygroundAdmin())
        ->post(UrlHelper::actionUrl('typesense/playground/schema'), [
            'collection' => 'heroes',
        ])
        ->assertOk()
        ->assertSee('"fields"', false);
});

it('renders the one unified playground screen for a collection', function() {
    $this->actingAs(aPlaygroundAdmin())
        ->get(UrlHelper::cpUrl('typesense/playground/heroes'))
        ->assertOk()
        ->assertSee('ts-pg-run', false)
        ->assertSee('ts-pg-editor__input', false)
        ->assertSee('ts-pg-schema', false);
});

it('redirects the retired document-browser URL into the playground', function() {
    $this->actingAs(aPlaygroundAdmin())
        ->get(UrlHelper::cpUrl('typesense/playground/heroes/browse'))
        ->assertRedirect();
});

it('forbids the playground run in Free even for an admin', function() {
    $plugin = \craftpulse\typesense\Typesense::$plugin;
    $original = $plugin->edition;
    $plugin->edition = \craftpulse\typesense\Typesense::EDITION_FREE;

    try {
        $this->actingAs(aPlaygroundAdmin())
            ->withExceptionHandling()
            ->post(UrlHelper::actionUrl('typesense/playground/run'), ['collection' => 'heroes'])
            ->assertForbidden();
    } finally {
        $plugin->edition = $original;
    }
});
