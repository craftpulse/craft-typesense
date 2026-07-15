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
use craftpulse\typesense\Typesense;
use Throwable;
use Typesense\Exceptions\ObjectNotFound;
use yii\base\InvalidConfigException;

/**
 * Zero-downtime reindex orchestration through Typesense aliases.
 *
 * The searchable (logical) name is served through an alias pointing at a
 * timestamped physical collection. A rebuild creates a fresh physical collection
 * from the current declared schema, copies the documents into it, atomically
 * swaps the alias to the new physical collection, and deletes the old one, so a
 * search never sees downtime: the alias resolves to a queryable collection at
 * every step, and the swap is atomic. The first rebuild of a plain (non-alias)
 * collection converts it to the alias pattern.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class Aliases extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Lists the server's aliases as logical name => physical collection name.
     * Fail-soft.
     *
     * @return array<string, string>
     * @throws InvalidConfigException
     * @author CraftPulse
     */
    public function all(): array
    {
        $client = Typesense::$plugin->getClient()->client();

        if ($client === null) {
            return [];
        }

        try {
            $aliases = [];

            foreach ($client->aliases->retrieve()['aliases'] ?? [] as $alias) {
                $aliases[(string)($alias['name'] ?? '')] = (string)($alias['collection_name'] ?? '');
            }

            return $aliases;
        } catch (Throwable $e) {
            Craft::error("Could not list Typesense aliases: {$e->getMessage()}", 'typesense');

            return [];
        }
    }

    /**
     * Clones a collection's schema into a new collection using Typesense's
     * `src_name` (capability-gated: refused servers below the cloning version
     * never reach this). Documents are not copied by a clone.
     *
     * @param string $source The source collection (or alias) name.
     * @param string $target The new collection name.
     * @return bool
     * @throws InvalidConfigException
     * @author CraftPulse
     */
    public function clone(string $source, string $target): bool
    {
        $capabilities = Typesense::$plugin->getClient()->getServerCapabilities();

        if (!($capabilities?->collectionCloning() ?? false)) {
            Craft::warning('Collection cloning requires a newer Typesense server; ignoring.', 'typesense');

            return false;
        }

        // POST /collections?src_name=<source> with {name: <target>} copies the
        // source schema into a new collection.
        $response = Typesense::$plugin->getClient()->request('POST', '/collections', ['name' => $target], null, [
            'src_name' => $source,
        ]);

        return ($response['name'] ?? null) === $target;
    }

    /**
     * Rebuilds a collection with zero downtime: a fresh physical collection from
     * the current declared schema, the documents copied over, an atomic alias
     * swap, and cleanup of the old physical collection.
     *
     * @param Collection $collection
     * @param int $siteId
     * @return array{logical: string, physical: string, previous: string|null}
     * @throws InvalidConfigException
     * @throws Throwable
     * @author CraftPulse
     */
    public function rebuild(Collection $collection, int $siteId): array
    {
        $client = Typesense::$plugin->getClient()->client();
        $registry = Typesense::$plugin->getCollectionRegistry();

        if ($client === null) {
            throw new InvalidConfigException('No Typesense client is configured.');
        }

        $logical = $registry->resolveName($collection, $siteId);
        $previous = $this->resolve($logical);
        $physical = $logical . '_' . dechex(time());

        // 1. Build the fresh physical collection from the current declared schema.
        $schema = $registry->getCreateSchema($collection, $siteId);
        $schema['name'] = $physical;
        $client->collections->create($schema);

        // 2. Copy the documents over (server-side export/import), when a current
        // physical collection exists.
        if ($previous !== null) {
            $this->_copyDocuments($previous, $physical);
        }

        // 3. Atomic swap, then clean up the old physical collection.
        $this->_swap($logical, $physical, $previous);

        return ['logical' => $logical, 'physical' => $physical, 'previous' => $previous];
    }

    /**
     * Resolves a logical name to the physical collection currently serving it:
     * the alias target if it is an alias, else the collection itself if it
     * exists, else null.
     *
     * @param string $logical
     * @return string|null
     * @throws InvalidConfigException
     * @author CraftPulse
     */
    public function resolve(string $logical): ?string
    {
        $client = Typesense::$plugin->getClient()->client();

        if ($client === null) {
            return null;
        }

        try {
            return (string)$client->aliases[$logical]->retrieve()['collection_name'];
        } catch (ObjectNotFound) {
            // Not an alias; fall through to a direct collection lookup.
        } catch (Throwable $e) {
            Craft::error("Could not resolve alias '{$logical}': {$e->getMessage()}", 'typesense');
        }

        try {
            return (string)$client->collections[$logical]->retrieve()['name'];
        } catch (Throwable) {
            return null;
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * Copies every document from one physical collection to another, server-side
     * (export then import), in one streamed pass.
     *
     * @param string $from
     * @param string $to
     * @return void
     * @throws InvalidConfigException
     * @author CraftPulse
     */
    private function _copyDocuments(string $from, string $to): void
    {
        $client = Typesense::$plugin->getClient()->client();

        if ($client === null) {
            return;
        }

        try {
            $jsonl = $client->collections[$from]->documents->export();
        } catch (Throwable $e) {
            Craft::error("Could not export documents from '{$from}': {$e->getMessage()}", 'typesense');

            return;
        }

        if (trim((string)$jsonl) === '') {
            return;
        }

        $client->collections[$to]->documents->import($jsonl, ['action' => 'upsert']);
    }

    /**
     * Atomically points the logical name at the new physical collection and
     * removes the previous physical collection. When the logical name is still a
     * plain collection (the first rebuild), it is dropped first to free the name
     * for the alias.
     *
     * @param string $logical
     * @param string $physical
     * @param string|null $previous
     * @return void
     * @throws InvalidConfigException
     * @author CraftPulse
     */
    private function _swap(string $logical, string $physical, ?string $previous): void
    {
        $client = Typesense::$plugin->getClient()->client();

        if ($client === null) {
            return;
        }

        $isAlias = false;

        try {
            $client->aliases[$logical]->retrieve();
            $isAlias = true;
        } catch (Throwable) {
            // Not an alias yet.
        }

        // A plain collection holds the logical name: drop it to free the name,
        // then point the alias at the new physical collection.
        if (!$isAlias && $previous === $logical) {
            try {
                $client->collections[$logical]->delete();
            } catch (Throwable $e) {
                Craft::error("Could not drop collection '{$logical}' during rebuild: {$e->getMessage()}", 'typesense');
            }
        }

        $client->aliases->upsert($logical, ['collection_name' => $physical]);

        // Clean up the old physical collection (only when it was a distinct
        // alias target, never the just-created one).
        if ($previous !== null && $previous !== $logical && $previous !== $physical) {
            try {
                $client->collections[$previous]->delete();
            } catch (Throwable $e) {
                Craft::error("Could not delete old physical collection '{$previous}': {$e->getMessage()}", 'typesense');
            }
        }
    }
}
