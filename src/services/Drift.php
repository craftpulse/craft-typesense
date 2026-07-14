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
 * In this release the schema comparator is complete (declared fields vs the live
 * collection's fields, per resolved target), detecting both a manual server-side
 * edit (an extra live field) and a config change (a declared field the server
 * lacks). The synonyms, curation, and presets aspects are stubbed here and deepen
 * when those services land. Powers `typesense/config/diff` and the CP drift panel.
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

        $primaryId = Craft::$app->getSites()->getPrimarySite()->id;

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
}
