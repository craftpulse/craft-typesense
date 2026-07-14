<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\services;

use Craft;
use craft\base\Component;
use craft\helpers\Json;
use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\Typesense;

/**
 * Stopwords and stemming-dictionary seeding.
 *
 * Stopwords are single-shape across supported versions (no v30 set migration).
 * Stemming dictionaries are imported so a field's stem_dictionary reference
 * resolves. Both are config-seeded.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class Dictionaries extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * The stopwords set name for a collection.
     *
     * @param Collection $collection
     * @return string
     * @author CraftPulse
     */
    public function stopwordsName(Collection $collection): string
    {
        return Typesense::$plugin->getCollectionRegistry()->resolveName(
            $collection,
            Craft::$app->getSites()->getPrimarySite()->id,
        ) . '_stopwords';
    }

    /**
     * Seeds a collection's declared stopwords.
     *
     * @param Collection $collection
     * @param string|null $locale
     * @return void
     * @author CraftPulse
     */
    public function seedStopwords(Collection $collection, ?string $locale = null): void
    {
        $stopwords = $collection->getStopwords();

        if ($stopwords === []) {
            return;
        }

        $data = ['stopwords' => $stopwords];

        if ($locale !== null) {
            $data['locale'] = $locale;
        }

        Typesense::$plugin->getClient()->request('PUT', '/stopwords/' . $this->stopwordsName($collection), $data);
    }

    /**
     * Imports a stemming dictionary (so stem_dictionary field references resolve).
     * Each entry is a [word, root] pair.
     *
     * @param string $dictionaryId
     * @param array<int, array{word: string, root: string}> $entries
     * @return void
     * @author CraftPulse
     */
    public function importStemmingDictionary(string $dictionaryId, array $entries): void
    {
        if ($entries === []) {
            return;
        }

        $jsonl = implode("\n", array_map(static fn(array $entry): string => Json::encode($entry), $entries));

        Typesense::$plugin->getClient()->request('POST', '/stemming/dictionaries/import', null, $jsonl, [
            'id' => $dictionaryId,
        ]);
    }
}
