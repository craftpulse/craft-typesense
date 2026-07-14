<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Craft Plugin that synchronises with Typesense
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

namespace craftpulse\typesense\console\controllers;

use Craft;
use craft\helpers\Json;
use craftpulse\typesense\Typesense;
use Throwable;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * JSONL backup commands, pipe-compatible with Typesense's import format.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class BackupController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @var string|null A file path to read from or write to (otherwise stdin/stdout).
     */
    public ?string $path = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['path']);
    }

    /**
     * Exports a collection's documents as JSONL.
     *
     * @param string $handle
     * @return int
     * @author CraftPulse
     */
    public function actionExport(string $handle): int
    {
        $target = $this->_target($handle);
        $client = Typesense::$plugin->getClient()->client();

        if ($target === null || $client === null) {
            $this->stderr("Could not resolve collection \"{$handle}\" or connect to Typesense." . PHP_EOL);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        try {
            $jsonl = (string)$client->collections[$target]->documents->export();
        } catch (Throwable $e) {
            $this->stderr("Export failed: {$e->getMessage()}" . PHP_EOL);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        if ($this->path !== null) {
            file_put_contents($this->path, $jsonl);
            $this->stdout("Exported {$handle} to {$this->path}." . PHP_EOL);

            return ExitCode::OK;
        }

        $this->stdout($jsonl . PHP_EOL);

        return ExitCode::OK;
    }

    /**
     * Imports JSONL documents into a collection (upsert).
     *
     * @param string $handle
     * @return int
     * @author CraftPulse
     */
    public function actionImport(string $handle): int
    {
        $target = $this->_target($handle);
        $client = Typesense::$plugin->getClient()->client();

        if ($target === null || $client === null) {
            $this->stderr("Could not resolve collection \"{$handle}\" or connect to Typesense." . PHP_EOL);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $jsonl = $this->path !== null ? (string)file_get_contents($this->path) : (string)file_get_contents('php://stdin');
        $documents = [];

        foreach (array_filter(explode("\n", trim($jsonl))) as $line) {
            $documents[] = Json::decode($line);
        }

        if ($documents === []) {
            $this->stderr('No documents to import.' . PHP_EOL);

            return ExitCode::OK;
        }

        try {
            $client->collections[$target]->documents->import($documents, ['action' => 'upsert']);
        } catch (Throwable $e) {
            $this->stderr("Import failed: {$e->getMessage()}" . PHP_EOL);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout('Imported ' . count($documents) . " document(s) into {$handle}." . PHP_EOL);

        return ExitCode::OK;
    }

    // Private Methods
    // =========================================================================

    /**
     * Resolves a collection handle to its primary-site Typesense target name.
     *
     * @param string $handle
     * @return string|null
     * @author CraftPulse
     */
    private function _target(string $handle): ?string
    {
        $collection = Typesense::$plugin->getCollectionRegistry()->get($handle);

        if ($collection === null) {
            return null;
        }

        return Typesense::$plugin->getCollectionRegistry()->resolveName(
            $collection,
            Craft::$app->getSites()->getPrimarySite()->id,
        );
    }
}
