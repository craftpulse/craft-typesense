<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Covers the utility controller access gate: its operational actions require the
 * typesense:manageSettings permission.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\User;
use craft\enums\CmsEdition;
use craft\helpers\UrlHelper;

it('forbids the sync action for a user without manageSettings', function() {
    Craft::$app->edition = CmsEdition::Pro;
    $suffix = bin2hex(random_bytes(4));
    $user = new User();
    $user->username = "ts_util_{$suffix}";
    $user->email = "ts_util_{$suffix}@example.test";
    Craft::$app->getElements()->saveElement($user);

    $this->actingAs($user)
        ->withExceptionHandling()
        ->post(UrlHelper::actionUrl('typesense/utility/sync'))
        ->assertForbidden();
});
