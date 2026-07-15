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
 * Stopwords and stemming-dictionary management and seeding.
 *
 * Stopwords are single-shape across supported versions (no v30 set migration).
 * A collection that declares stopwords in config owns them (read-only in the
 * manager); otherwise the manager owns the set. Stemming dictionaries are
 * imported so a field's stem_dictionary reference resolves.
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
     * Whether a collection's stopwords are declared in config (and therefore
     * owned by config, read-only in the manager).
     *
     * @param Collection $collection
     * @return bool
     * @author CraftPulse
     */
    public function isConfigManaged(Collection $collection): bool
    {
        return $collection->getStopwords() !== [];
    }

    /**
     * Deletes a collection's stopwords set (fail-soft).
     *
     * @param Collection $collection
     * @return void
     * @author CraftPulse
     */
    public function deleteStopwords(Collection $collection): void
    {
        Typesense::$plugin->getClient()->request('DELETE', '/stopwords/' . $this->stopwordsName($collection));
    }

    /**
     * Reads a collection's stopwords set, normalised to `{stopwords, locale}`.
     *
     * @param Collection $collection
     * @return array{stopwords: array<int, string>, locale: string}
     * @author CraftPulse
     */
    public function getStopwords(Collection $collection): array
    {
        $response = Typesense::$plugin->getClient()->request('GET', '/stopwords/' . $this->stopwordsName($collection));

        return [
            'stopwords' => array_values(array_map('strval', $response['stopwords'] ?? [])),
            'locale' => (string)($response['locale'] ?? ''),
        ];
    }

    /**
     * Writes a collection's stopwords set from the manager (control-panel-owned).
     *
     * @param Collection $collection
     * @param array<int, string> $stopwords
     * @param string|null $locale
     * @return void
     * @author CraftPulse
     */
    public function saveStopwords(Collection $collection, array $stopwords, ?string $locale = null): void
    {
        $data = ['stopwords' => array_values($stopwords)];

        if ($locale !== null && $locale !== '') {
            $data['locale'] = $locale;
        }

        Typesense::$plugin->getClient()->request('PUT', '/stopwords/' . $this->stopwordsName($collection), $data);
    }

    /**
     * Lists the ids of the imported stemming dictionaries (fail-soft).
     *
     * @return array<int, string>
     * @author CraftPulse
     */
    public function stemmingDictionaries(): array
    {
        $response = Typesense::$plugin->getClient()->request('GET', '/stemming/dictionaries');

        return array_values(array_map('strval', $response['dictionaries'] ?? []));
    }

    /**
     * The ids of every stopword set on the server. Stopword sets are server-global
     * and applied per search via the `stopwords` search parameter, so a collection
     * can default to any of them. See https://typesense.org/docs/30.2/api/stopwords.html.
     *
     * @return array<int, string>
     * @author CraftPulse
     */
    public function stopwordSets(): array
    {
        $response = Typesense::$plugin->getClient()->request('GET', '/stopwords');
        $sets = is_array($response['stopwords'] ?? null) ? $response['stopwords'] : [];

        return array_values(array_filter(array_map(
            static fn($set): string => (string)($set['id'] ?? ''),
            $sets,
        ), static fn(string $id): bool => $id !== ''));
    }

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
