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
use craftpulse\typesense\builders\Field;
use craftpulse\typesense\enums\MultisiteStrategy;
use craftpulse\typesense\TypesenseCollectionIndex;

/**
 * Backwards-compatibility shim for pre-5.9.0 config files.
 *
 * Adapts a legacy TypesenseCollectionIndex (authored in config/typesense.php the
 * old way) into a registry Collection, so legacy configs run untouched through
 * the new sync engine and inherit its chunking, multisite, and status-to-delete
 * correctness. Each adaptation is deprecator-logged. The legacy resolver closure
 * is reused verbatim as the transform (its single argument is unaffected by the
 * extra registerDependency argument the engine passes), and the legacy criteria
 * query is cloned and re-scoped per site. Adapted collections use the
 * sharedWithSiteFilter strategy so the collection keeps its original name.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class LegacyConfig extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Adapts a legacy collection index into a registry Collection.
     *
     * @param TypesenseCollectionIndex $legacy
     * @return Collection
     * @author CraftPulse
     */
    public function adapt(TypesenseCollectionIndex $legacy): Collection
    {
        $this->_logDeprecation($legacy->indexName);

        $collection = Collection::make($legacy->indexName)
            ->elementType($legacy->elementType)
            ->multisite(MultisiteStrategy::SharedWithSiteFilter)
            ->fields(...$this->_fields($legacy->schema['fields'] ?? []))
            ->elementQuery(fn($base) => (clone $legacy->criteria)->siteId($base->siteId)->status(null));

        if (isset($legacy->schema['default_sorting_field'])) {
            $collection->defaultSortingField($legacy->schema['default_sorting_field']);
        }

        $resolver = $legacy->schema['resolver'] ?? $legacy->resolver;

        if (is_callable($resolver)) {
            $collection->transform(fn($element, callable $registerDependency) => $resolver($element));
        }

        return $collection;
    }

    // Private Methods
    // =========================================================================

    /**
     * Builds Field builders from legacy field arrays, preserving every schema key.
     *
     * @param array<int, array<string, mixed>> $fields
     * @return array<int, Field>
     * @author CraftPulse
     */
    private function _fields(array $fields): array
    {
        $builders = [];

        foreach ($fields as $field) {
            if (!isset($field['name'], $field['type'])) {
                continue;
            }

            $builder = Field::make($field['name'], $field['type']);

            foreach ($field as $key => $value) {
                if ($key !== 'name' && $key !== 'type') {
                    $builder->raw($key, $value);
                }
            }

            $builders[] = $builder;
        }

        return $builders;
    }

    /**
     * Logs the legacy-config deprecation once per collection.
     *
     * @param string $name
     * @return void
     * @author CraftPulse
     */
    private function _logDeprecation(string $name): void
    {
        Craft::$app->getDeprecator()->log(
            "typesense.legacyCollectionConfig.{$name}",
            "The Typesense collection \"{$name}\" is defined with the legacy TypesenseCollectionIndex config shape. It still works through a compatibility shim, but you should migrate it to the fluent Collection builder. Run `craft typesense/config/migrate` to generate the new config.",
        );
    }
}
