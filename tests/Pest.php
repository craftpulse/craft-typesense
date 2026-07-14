<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Pest configuration. Binds craft-pest's TestCase (which boots Craft and wraps
 * each test in a database transaction) to every test in this suite. A per-test
 * in-memory cache keeps state isolated between tests and avoids the playground
 * FileCache's filemtime() stat warnings.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\typesense\models\ServerCapabilities;
use markhuot\craftpest\test\RefreshesDatabase;
use markhuot\craftpest\test\TestCase;
use yii\caching\ArrayCache;

uses(TestCase::class, RefreshesDatabase::class)
    ->beforeEach(function() {
        Craft::$app->set('cache', new ArrayCache());
    })
    ->in(__DIR__);

/**
 * Builds a ServerCapabilities value object pinned to a version string.
 */
function caps(string $version): ServerCapabilities
{
    $capabilities = new ServerCapabilities();
    $capabilities->version = $version;

    return $capabilities;
}
