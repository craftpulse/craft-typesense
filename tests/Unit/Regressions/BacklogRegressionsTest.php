<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The three damning-theme regression tests derived from the pre-rebuild backlog.
 * Each pins a class of bug that recurred against the legacy plugin so the rebuild
 * cannot regress into it:
 *
 *   1. Issue #68 - control-panel action URLs were built from the primary site's
 *      front-end baseUrl, so the Sync/Flush buttons broke on split-domain and
 *      headless setups. Asserted at unit level on UrlHelper::actionUrl output
 *      (the single-domain playground cannot reproduce the split-domain symptom).
 *   2. Issue #63 - saving an element before the plugin was configured fataled,
 *      because the sync listeners fired regardless of whether an API key existed.
 *   3. Issue #67 - applying a draft fataled, because the save handler re-queried
 *      the element and passed null into the indexer. The rebuild skips drafts and
 *      revisions outright.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\Entry;
use craft\helpers\ElementHelper;
use craft\helpers\UrlHelper;
use craftpulse\typesense\Typesense;

function aHeroEntry(): Entry
{
    /** @var Entry $entry */
    $entry = Entry::find()->section('heroes')->siteId(1)->one();

    return $entry;
}

it('builds control-panel action URLs from the CP base, never the site URL (issue #68)', function() {
    $site = Craft::$app->getSites()->getPrimarySite();
    $originalBaseUrl = $site->baseUrl;
    $site->baseUrl = 'https://frontend.example.com/';

    try {
        $url = UrlHelper::actionUrl('typesense/utility/sync');

        expect($url)->not->toContain('frontend.example.com')
            ->and($url)->toContain('typesense/utility/sync');
    } finally {
        $site->baseUrl = $originalBaseUrl;
    }
});

it('does not sync or fatal when the plugin is not configured (issue #63)', function() {
    $settings = Typesense::$plugin->getSettings();
    $originalKey = $settings->apiKey;
    $settings->apiKey = '';
    $sync = Typesense::$plugin->getSync();

    try {
        expect($sync->isEnabled())->toBeFalse();

        // The save listener must be a safe no-op when unconfigured, never a fatal.
        $sync->handleSave(aHeroEntry());
        $sync->flushPending();

        expect(true)->toBeTrue();
    } finally {
        $settings->apiKey = $originalKey;
    }
});

it('skips drafts in the save handler without fataling (issue #67)', function() {
    $draft = Craft::$app->getDrafts()->createDraft(aHeroEntry(), null);

    expect(ElementHelper::isDraftOrRevision($draft))->toBeTrue();

    // The legacy handler fataled on drafts; the rebuild must skip them cleanly.
    Typesense::$plugin->getSync()->handleSave($draft);
    Typesense::$plugin->getSync()->flushPending();

    expect(true)->toBeTrue();
});
