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
use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\enums\MultisiteStrategy;
use craftpulse\typesense\Typesense;
use Throwable;
use Typesense\Exceptions\ObjectNotFound;

/**
 * Drift detection: compares the live Typesense state against the declared config.
 *
 * The schema comparator is complete (declared fields vs the live collection's
 * fields, per resolved target), detecting both a manual server-side edit (an
 * extra live field) and a config change (a declared field the server lacks). The
 * synonyms and curation comparators run for config-managed collections (config is
 * the source of truth, so a live rule the config lacks is drift), and the preset
 * comparator compares the declared preset value against the live one. Powers
 * `typesense/config/diff` and the CP drift panel.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class Drift extends Component
{
    // Constants
    // =========================================================================

    /**
     * @var string The declared and live state match.
     */
    public const STATUS_IN_SYNC = 'inSync';

    /**
     * @var string The declared and live state differ.
     */
    public const STATUS_DRIFTED = 'drifted';

    /**
     * @var string The collection is declared but does not exist on the server.
     */
    public const STATUS_MISSING = 'missing';

    // Public Methods
    // =========================================================================

    /**
     * Returns the schema drift findings, one per resolved collection target.
     *
     * @return array<int, array<string, mixed>>
     * @author CraftPulse
     */
    public function diff(): array
    {
        $findings = [];

        foreach (Typesense::$plugin->getCollectionRegistry()->getAll() as $collection) {
            foreach ($this->_targets($collection) as $siteId => $target) {
                $findings[] = $this->_schemaFinding($collection, $siteId, $target);
            }

            $findings = array_merge($findings, $this->_resourceFindings($collection));
        }

        return $findings;
    }

    /**
     * Whether any collection target has drifted from its declared schema.
     *
     * @return bool
     * @author CraftPulse
     */
    public function hasDrift(): bool
    {
        foreach ($this->diff() as $finding) {
            if ($finding['status'] !== self::STATUS_IN_SYNC) {
                return true;
            }
        }

        return false;
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the resolved Typesense target names for a collection, keyed by site.
     *
     * @param Collection $collection
     * @return array<int, string>
     * @author CraftPulse
     */
    private function _targets(Collection $collection): array
    {
        $registry = Typesense::$plugin->getCollectionRegistry();

        if ($collection->getMultisiteStrategy() === MultisiteStrategy::CollectionPerSite) {
            $targets = [];

            foreach (Craft::$app->getSites()->getAllSiteIds() as $siteId) {
                $targets[$siteId] = $registry->resolveName($collection, $siteId);
            }

            return $targets;
        }

        $primaryId = (int)Craft::$app->getSites()->getPrimarySite()->id;

        return [$primaryId => $registry->resolveName($collection, $primaryId)];
    }

    /**
     * Compares a collection's declared schema against the live collection.
     *
     * @param Collection $collection
     * @param int $siteId
     * @param string $target
     * @return array<string, mixed>
     * @author CraftPulse
     */
    private function _schemaFinding(Collection $collection, int $siteId, string $target): array
    {
        $registry = Typesense::$plugin->getCollectionRegistry();
        $client = Typesense::$plugin->getClient()->client();
        $declared = $this->_fieldTypes($registry->getCreateSchema($collection, $siteId)['fields']);

        $base = [
            'collection' => $collection->getName(),
            'target' => $target,
            'aspect' => 'schema',
        ];

        if ($client === null) {
            return $base + ['status' => self::STATUS_MISSING, 'details' => ['reason' => 'No client']];
        }

        try {
            $live = $this->_fieldTypes($client->collections[$target]->retrieve()['fields'] ?? []);
        } catch (ObjectNotFound) {
            return $base + ['status' => self::STATUS_MISSING, 'details' => []];
        } catch (Throwable $e) {
            return $base + ['status' => self::STATUS_MISSING, 'details' => ['reason' => $e->getMessage()]];
        }

        $missingFields = array_values(array_diff(array_keys($declared), array_keys($live)));
        $extraFields = array_values(array_diff(array_keys($live), array_keys($declared)));
        $typeMismatches = [];

        foreach ($declared as $name => $type) {
            if (isset($live[$name]) && $live[$name] !== $type) {
                $typeMismatches[$name] = ['declared' => $type, 'live' => $live[$name]];
            }
        }

        $drifted = $missingFields !== [] || $extraFields !== [] || $typeMismatches !== [];

        return $base + [
            'status' => $drifted ? self::STATUS_DRIFTED : self::STATUS_IN_SYNC,
            'details' => [
                'missingFields' => $missingFields,
                'extraFields' => $extraFields,
                'typeMismatches' => $typeMismatches,
            ],
        ];
    }

    /**
     * Indexes a Typesense fields array to a name => type map.
     *
     * @param array<int, array<string, mixed>> $fields
     * @return array<string, string>
     * @author CraftPulse
     */
    private function _fieldTypes(array $fields): array
    {
        $types = [];

        foreach ($fields as $field) {
            if (isset($field['name'], $field['type'])) {
                $types[$field['name']] = $field['type'];
            }
        }

        return $types;
    }

    /**
     * Builds the synonyms, curation, and preset drift findings for a collection.
     *
     * @param Collection $collection
     * @return array<int, array<string, mixed>>
     * @author CraftPulse
     */
    private function _resourceFindings(Collection $collection): array
    {
        $findings = [];
        $synonyms = Typesense::$plugin->getSynonyms();
        $curation = Typesense::$plugin->getCuration();

        if ($synonyms->isConfigOwned($collection)) {
            $findings[] = $this->_ruleFinding(
                $collection,
                'synonyms',
                $this->_declaredIds($collection->getSynonymDefinitions(), $collection->getName()),
                $this->_liveIds($synonyms->all($collection)),
            );
        }

        if ($curation->isConfigOwned($collection)) {
            $findings[] = $this->_ruleFinding(
                $collection,
                'curation',
                $this->_declaredIds($collection->getCurationRules(), $collection->getName()),
                $this->_liveIds($curation->all($collection)),
            );
        }

        $presetFinding = $this->_presetFinding($collection);

        if ($presetFinding !== null) {
            $findings[] = $presetFinding;
        }

        return $findings;
    }

    /**
     * Compares a declared set of rule ids against the live set (config is the
     * source of truth: a live-only rule and a missing declared rule are both drift).
     *
     * @param Collection $collection
     * @param string $aspect
     * @param array<int, string> $declared
     * @param array<int, string> $live
     * @return array<string, mixed>
     * @author CraftPulse
     */
    private function _ruleFinding(Collection $collection, string $aspect, array $declared, array $live): array
    {
        $missing = array_values(array_diff($declared, $live));
        $extra = array_values(array_diff($live, $declared));
        $drifted = $missing !== [] || $extra !== [];

        return [
            'collection' => $collection->getName(),
            'target' => $collection->getName(),
            'aspect' => $aspect,
            'status' => $drifted ? self::STATUS_DRIFTED : self::STATUS_IN_SYNC,
            'details' => ['missing' => $missing, 'extra' => $extra],
        ];
    }

    /**
     * Compares a collection's declared preset value against the live preset, or
     * null when neither a declared nor a live preset exists.
     *
     * @param Collection $collection
     * @return array<string, mixed>|null
     * @author CraftPulse
     */
    private function _presetFinding(Collection $collection): ?array
    {
        $declared = $collection->getPreset();
        $name = Typesense::$plugin->getPresets()->presetName($collection);
        $stored = Typesense::$plugin->getClient()->request('GET', '/presets/' . $name);
        $live = is_array($stored['value'] ?? null) ? $stored['value'] : null;

        if ($declared === null && $live === null) {
            return null;
        }

        return [
            'collection' => $collection->getName(),
            'target' => $name,
            'aspect' => 'preset',
            'status' => $declared === $live ? self::STATUS_IN_SYNC : self::STATUS_DRIFTED,
            'details' => ['declared' => $declared, 'live' => $live],
        ];
    }

    /**
     * The rule ids a config declaration would seed (mirrors the seed id fallback).
     *
     * @param array<int, array<string, mixed>> $definitions
     * @param string $name
     * @return array<int, string>
     * @author CraftPulse
     */
    private function _declaredIds(array $definitions, string $name): array
    {
        $ids = [];

        foreach ($definitions as $index => $definition) {
            $ids[] = (string)($definition['id'] ?? ($name . '-' . $index));
        }

        return $ids;
    }

    /**
     * The rule ids present in a live rule list.
     *
     * @param array<int, array<string, mixed>> $rules
     * @return array<int, string>
     * @author CraftPulse
     */
    private function _liveIds(array $rules): array
    {
        $ids = [];

        foreach ($rules as $rule) {
            if (isset($rule['id'])) {
                $ids[] = (string)$rule['id'];
            }
        }

        return $ids;
    }
}
