<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The synonyms and curation VueAdminTable data endpoints (Fix 16 Part B) page
 * their unbounded per-collection lists server-side. This pins their gate: the
 * endpoint returns a well-formed paged JSON payload (data + pagination.total)
 * for an authorized request against a real collection, and fails closed for a
 * user without that handle and for a user holding only the other section's
 * handle (no umbrella). The shared gating helpers (aGatingAdmin,
 * aPermissionlessUser, withGatingEdition) come from GatingMatrixTest.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\helpers\UrlHelper;
use craftpulse\typesense\Typesense;

it('serves a paged JSON payload from the synonyms table-data endpoint for an authorized request', function() {
    withGatingEdition(Typesense::EDITION_PRO, function() {
        $response = $this->actingAs(aGatingAdmin())
            ->get(UrlHelper::actionUrl('typesense/synonyms/table-data', ['name' => 'heroes']))
            ->assertStatus(200);

        $payload = json_decode((string)$response->content, true);
        expect($payload)->toHaveKeys(['data', 'pagination'])
            ->and($payload['data'])->toBeArray()
            ->and($payload['pagination'])->toHaveKey('total');
    });
});

it('serves a paged JSON payload from the curation table-data endpoint for an authorized request', function() {
    withGatingEdition(Typesense::EDITION_PRO, function() {
        $response = $this->actingAs(aGatingAdmin())
            ->get(UrlHelper::actionUrl('typesense/curation/table-data', ['name' => 'heroes']))
            ->assertStatus(200);

        $payload = json_decode((string)$response->content, true);
        expect($payload)->toHaveKeys(['data', 'pagination'])
            ->and($payload['data'])->toBeArray()
            ->and($payload['pagination'])->toHaveKey('total');
    });
});

it('forbids the synonyms table-data endpoint for a user without manageSynonyms', function() {
    withGatingEdition(Typesense::EDITION_PRO, function() {
        $this->actingAs(aPermissionlessUser())
            ->withExceptionHandling()
            ->get(UrlHelper::actionUrl('typesense/synonyms/table-data', ['name' => 'heroes']))
            ->assertForbidden();
    });
});

it('forbids the curation table-data endpoint for a user without manageCuration', function() {
    withGatingEdition(Typesense::EDITION_PRO, function() {
        $this->actingAs(aPermissionlessUser())
            ->withExceptionHandling()
            ->get(UrlHelper::actionUrl('typesense/curation/table-data', ['name' => 'heroes']))
            ->assertForbidden();
    });
});

it('forbids the synonyms table-data endpoint for a user holding only manageCuration (no umbrella)', function() {
    withGatingEdition(Typesense::EDITION_PRO, function() {
        $user = aPermissionlessUser();
        Craft::$app->getUserPermissions()->saveUserPermissions((int)$user->id, ['typesense:manageCuration']);

        $this->actingAs($user)
            ->withExceptionHandling()
            ->get(UrlHelper::actionUrl('typesense/synonyms/table-data', ['name' => 'heroes']))
            ->assertForbidden();
    });
});
