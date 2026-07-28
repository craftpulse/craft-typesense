<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Test-suite fixtures: a stand-in for the playground's own "heroes" section
 * and Typesense collection (declared in the playground's own
 * cms/config/typesense.php, which this plugin's standalone test install has
 * no access to), plus the dedicated, prefixed Typesense-side collection this
 * suite indexes them into. Loaded once from tests/Bootstrap.php, after Craft
 * and the plugin are installed - never per-test, so the fixture Craft
 * content and the Typesense-side collection it feeds are created exactly
 * once per suite run and every test observes the same, stable baseline.
 *
 * The fixture Craft section's *handle* is deliberately the literal "heroes"
 * too, matching the playground's own real section: a large share of this
 * suite queries `Entry::find()->section('heroes')` directly rather than
 * through the plugin's Collection abstraction, and those queries need a
 * genuine "heroes" section to resolve against. This is safe precisely
 * because db_test is an entirely separate database from the live db - a
 * "heroes" section here can never collide with the live db's real "heroes"
 * content (including its known double-JSON-encoded rows), since the two
 * never share a schema. Only the *physical Typesense collection* behind it
 * needs, and gets, the "typesensefix_" prefix, because Typesense (unlike
 * MySQL) is one real, shared server with no per-suite database boundary -
 * see TYPESENSE_COLLECTION_PREFIX in phpunit.xml.dist.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\Entry;
use craft\fieldlayoutelements\CustomField;
use craft\fieldlayoutelements\entries\EntryTitleField;
use craft\fields\PlainText;
use craft\models\EntryType;
use craft\models\FieldLayout;
use craft\models\FieldLayoutTab;
use craft\models\Section;
use craft\models\Section_SiteSettings;
use craft\models\Site;
use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\builders\Field;
use craftpulse\typesense\enums\MultisiteStrategy;
use craftpulse\typesense\Typesense;

/**
 * @var string The handle of the fixture Craft section standing in for the
 * playground's own "heroes" section. Lives only in db_test, an entirely
 * separate database from the live db's real "heroes" section, so the shared
 * literal handle never collides with (or reads) the live db's content.
 */
const TYPESENSEFIX_SECTION_HANDLE = 'heroes';

/**
 * @var string The logical collection handle every test in this suite already
 * references. Only the Craft section and the physical Typesense collection
 * behind it are fixture-owned and prefixed; this in-memory handle is not, so
 * the ~100 existing "heroes"-handle references across the suite need no
 * changes.
 */
const TYPESENSEFIX_COLLECTION_HANDLE = 'heroes';

/**
 * @var int The number of fixture entries created in the section above.
 */
const TYPESENSEFIX_HERO_COUNT = 12;

/**
 * Ensures a second, non-primary site exists, so multisite-scoped assertions
 * (composite document ids, per-site collections) have a real second site to
 * exercise. Runs once, at bootstrap, strictly before any test executes -
 * never mid-suite - so `Sites::refreshSites()`/`getIsMultiSite()` never
 * observe an inconsistent site count partway through a run (a bootstrap-time
 * site is safe for exactly this reason; a per-test site is not - see the
 * craft-pest skill's craft-state.md).
 *
 * @return void
 * @throws \Throwable
 * @author CraftPulse
 */
function typesensefixEnsureSecondSite(): void
{
    $sites = Craft::$app->getSites();

    if (count($sites->getAllSites(true)) > 1) {
        return;
    }

    $primary = $sites->getPrimarySite();
    $site = new Site([
        'groupId' => $primary->groupId,
        'handle' => 'typesensefixSecondary',
        'language' => 'en-US',
        'hasUrls' => true,
        'primary' => false,
        'sortOrder' => 2,
    ]);
    $site->setName('Typesensefix Secondary');
    $site->setBaseUrl('http://typesensefix-secondary.test');

    if (!$sites->saveSite($site)) {
        throw new \RuntimeException('Could not create the typesensefix fixture site: ' . json_encode($site->getErrors()));
    }

    $sites->refreshSites();
    Craft::$app->getIsMultiSite(true, true);
}

/**
 * Ensures the fixture section and entry type exist, propagated to every
 * site. Idempotent: safe to call on every bootstrap.
 *
 * @return Section
 * @throws \Throwable
 * @author CraftPulse
 */
function typesensefixEnsureHeroesSection(): Section
{
    $entriesService = Craft::$app->getEntries();
    $section = $entriesService->getSectionByHandle(TYPESENSEFIX_SECTION_HANDLE);

    if ($section !== null) {
        return $section;
    }

    $entryType = new EntryType([
        'name' => 'Typesensefix Hero',
        'handle' => 'typesensefixHero',
    ]);

    // Entries::saveEntryType() derives $hasTitleField from whether the field
    // layout itself includes a "title" element
    // (`$entryType->hasTitleField = $entryType->getFieldLayout()->isFieldIncluded('title')`)
    // rather than trusting a directly-assigned property - Craft 5 moved Title
    // from a bare native attribute to an explicit field-layout element
    // (`EntryTitleField`). Without it here, every fixture entry saves with a
    // silently-null title (no validation error, since an unset title is only
    // invalid when the layout's title field is marked required) - which is
    // exactly the failure this addition fixes.
    //
    // The sample custom field (see typesensefixEnsureSampleField()) is
    // included here too, not just created globally: MappingSourcesTest-family
    // fixtures (MappingSaveTest, FieldLayoutMappingTest, MappedCollectionE2ETest)
    // scope "available custom fields" to a CollectionDefinition's own
    // `source` entry type, so a merely-existing-but-unattached field would
    // still resolve to none available for the "heroes" source.
    $sampleField = Craft::$app->getFields()->getFieldByHandle('typesensefixSample');
    $fieldLayout = new FieldLayout(['type' => Entry::class]);
    $fieldLayout->setTabs([
        new FieldLayoutTab([
            'layout' => $fieldLayout,
            'name' => 'Content',
            'elements' => array_filter([
                new EntryTitleField(),
                $sampleField !== null ? new CustomField($sampleField) : null,
            ]),
        ]),
    ]);
    $entryType->setFieldLayout($fieldLayout);

    if (!$entriesService->saveEntryType($entryType)) {
        throw new \RuntimeException('Could not create the typesensefix hero entry type: ' . json_encode($entryType->getErrors()));
    }

    $siteSettings = [];

    foreach (Craft::$app->getSites()->getAllSites(true) as $site) {
        $siteSettings[$site->id] = new Section_SiteSettings([
            'siteId' => $site->id,
            'enabledByDefault' => true,
            'hasUrls' => true,
            'uriFormat' => 'typesensefix-heroes/{slug}',
        ]);
    }

    $section = new Section([
        'name' => 'Typesensefix Heroes',
        'handle' => TYPESENSEFIX_SECTION_HANDLE,
        'type' => Section::TYPE_CHANNEL,
    ]);
    $section->setEntryTypes([$entryType]);
    $section->setSiteSettings($siteSettings);

    if (!$entriesService->saveSection($section)) {
        throw new \RuntimeException('Could not create the typesensefix heroes section: ' . json_encode($section->getErrors()));
    }

    return $entriesService->getSectionByHandle(TYPESENSEFIX_SECTION_HANDLE) ?? $section;
}

/**
 * Ensures the fixture section carries its fixed set of published entries on
 * the primary site (Craft propagates each save to every other site the
 * section is enabled for). Idempotent: tops up to the target count rather
 * than re-creating entries that already exist.
 *
 * @param Section $section
 * @return void
 * @throws \Throwable
 * @author CraftPulse
 */
function typesensefixEnsureHeroesEntries(Section $section): void
{
    $primarySite = Craft::$app->getSites()->getPrimarySite();
    $existingCount = Entry::find()->section($section->handle)->siteId($primarySite->id)->status(null)->count();

    if ($existingCount >= TYPESENSEFIX_HERO_COUNT) {
        return;
    }

    $entryType = $section->getEntryTypes()[0];
    $elements = Craft::$app->getElements();

    for ($i = $existingCount + 1; $i <= TYPESENSEFIX_HERO_COUNT; $i++) {
        $entry = new Entry();
        $entry->sectionId = $section->id;
        $entry->setTypeId($entryType->id);
        $entry->siteId = $primarySite->id;
        $entry->title = "Typesensefix Hero {$i}";
        $entry->slug = "typesensefix-hero-{$i}";
        $entry->postDate = (new DateTime('now', new DateTimeZone('UTC')))->modify("-{$i} hours");
        $entry->enabled = true;

        if (!$elements->saveElement($entry)) {
            throw new \RuntimeException("Could not create typesensefix hero entry {$i}: " . json_encode($entry->getErrors()));
        }
    }
}

/**
 * Ensures at least one ordinary custom field exists. A fresh db_test install
 * has none, but several mapping/field-layout tests across this suite
 * (MappingSaveTest, FieldLayoutMappingTest, MappedCollectionE2ETest) reach
 * for "any field" via `Craft::$app->getFields()->getAllFields()[0]` - an
 * ambient-fixture assumption the live playground's populated field set
 * happened to satisfy, but a from-scratch db_test does not. Idempotent by
 * handle.
 *
 * @return void
 * @throws \Throwable
 * @author CraftPulse
 */
function typesensefixEnsureSampleField(): void
{
    $fieldsService = Craft::$app->getFields();

    if ($fieldsService->getFieldByHandle('typesensefixSample') !== null) {
        return;
    }

    $field = new PlainText([
        'name' => 'Typesensefix Sample',
        'handle' => 'typesensefixSample',
    ]);

    if (!$fieldsService->saveField($field)) {
        throw new \RuntimeException('Could not create the typesensefix sample field: ' . json_encode($field->getErrors()));
    }
}

/**
 * Builds the "heroes"-handled fixture collection definition, matching the
 * shape of the playground's own real config/typesense.php declaration
 * (title/slug/post_date_timestamp fields, searchable, shared-with-site-filter
 * multisite), but scoped to the fixture section instead of the live one.
 *
 * @return Collection
 * @author CraftPulse
 */
function typesensefixHeroesCollection(): Collection
{
    return Collection::make(TYPESENSEFIX_COLLECTION_HANDLE)
        ->elementType(Entry::class)
        ->searchable()
        ->multisite(MultisiteStrategy::SharedWithSiteFilter)
        ->elementQuery(static fn($query) => $query->section(TYPESENSEFIX_SECTION_HANDLE))
        ->fields(
            Field::string('title')->sort(),
            Field::string('slug')->facet(),
            Field::make('post_date_timestamp', 'int32'),
        )
        ->defaultSortingField('post_date_timestamp')
        ->preset(['query_by' => 'title,slug'])
        ->transform(static function(Entry $entry): array {
            return [
                'id' => (string)$entry->id,
                'title' => (string)$entry->title,
                'slug' => (string)$entry->slug,
                'post_date_timestamp' => $entry->postDate ? (int)$entry->postDate->format('U') : 0,
            ];
        });
}

/**
 * Returns the baseline `Settings::$collections` array every test starts
 * from: just the "heroes" fixture collection. Re-applied in a global
 * `beforeEach()` (see tests/Pest.php) rather than trusted to survive from
 * bootstrap alone, so a test that replaces `$settings->collections` for its
 * own scoped fixture and fails before its `finally` restore can never leak
 * into the next test (the root cause of the ConfigCollectionScreenTest
 * order-dependency this suite's own smoke run surfaced).
 *
 * @return array<int, Collection>
 * @author CraftPulse
 */
function typesensefixBaselineCollections(): array
{
    return [typesensefixHeroesCollection()];
}

/**
 * Ensures the physical, prefixed Typesense collection behind the "heroes"
 * fixture exists on the real server and carries exactly, only, the current
 * fixture entries as documents. Deliberately drop-then-recreate rather than
 * create-if-missing-and-upsert: an upsert-only reconciliation would leave
 * stale documents behind from an element id range a prior, now-superseded
 * bootstrap iteration created (this suite doesn't otherwise delete the
 * fixture entries it created earlier), so counts that "compute from the
 * fixture data at runtime" would silently drift high. Recreating is cheap at
 * this fixture's size (a dozen documents) and keeps the collection an exact
 * mirror of the current fixture entries on every boot. Fails soft when no
 * Typesense server is reachable this run: the handful of tests that need
 * real hits will surface that themselves, with a real error, rather than
 * this helper masking it.
 *
 * @param Collection $collection
 * @return void
 * @author CraftPulse
 */
function typesensefixEnsureHeroesIndexed(Collection $collection): void
{
    $client = Typesense::$plugin->getClient()->client();

    if ($client === null) {
        return;
    }

    $registry = Typesense::$plugin->getCollectionRegistry();
    $sync = Typesense::$plugin->getSync();
    $primarySiteId = Craft::$app->getSites()->getPrimarySite()->id;
    $target = $registry->resolveName($collection, $primarySiteId);

    try {
        $client->collections[$target]->delete();
    } catch (Throwable) {
        // not present yet - nothing to drop
    }

    $sync->ensureCollectionExists($collection, $primarySiteId);

    $ids = Entry::find()->section(TYPESENSEFIX_SECTION_HANDLE)->siteId($primarySiteId)->status(null)->ids();
    $sync->indexElements($collection, $primarySiteId, $ids);
}

/**
 * Primes every Typesense-suite fixture: the second site, a sample custom
 * field, the fixture section and its entries, the plugin's connection
 * settings and collection prefix, the "heroes" collection declaration, and
 * the real, seeded Typesense-side collection behind it. Guarded by a static
 * flag so repeated calls (every test file shares one process) are a no-op
 * after the first.
 *
 * @return void
 * @throws \Throwable
 * @author CraftPulse
 */
function typesensefixPrimeFixtures(): void
{
    static $primed = false;

    if ($primed) {
        return;
    }

    $primed = true;

    /** @var \craftpulse\typesense\models\Settings $settings */
    $settings = Typesense::$plugin->getSettings();

    // Connection coordinates: the ambient environment (a local, gitignored
    // .env in this plugin's own root under DDEV, or the CI job's own env
    // block), never hardcoded here. Falls back to the DDEV playground's own
    // Typesense service coordinates only when nothing else supplies them, so
    // a bare `vendor/bin/pest` still has a sane default to fail loudly
    // against rather than silently skip every real-HTTP assertion.
    $settings->server = getenv('TYPESENSE_HOST') ?: 'typesense';
    $settings->port = getenv('TYPESENSE_PORT') ?: '8108';
    $settings->protocol = getenv('TYPESENSE_PROTOCOL') ?: 'http';
    $settings->apiKey = getenv('TYPESENSE_API_KEY') ?: null;

    // The suite's own Typesense-side isolation lever (see
    // phpunit.xml.dist): every collection this suite creates or resolves is
    // namespaced away from the live server's real collections.
    $settings->collectionPrefix = getenv('TYPESENSE_COLLECTION_PREFIX') ?: 'typesensefix_';

    typesensefixEnsureSecondSite();
    typesensefixEnsureSampleField();
    $section = typesensefixEnsureHeroesSection();
    typesensefixEnsureHeroesEntries($section);

    $collection = typesensefixHeroesCollection();
    $settings->collections = [$collection];

    typesensefixEnsureHeroesIndexed($collection);
}
