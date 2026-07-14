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

use craftpulse\typesense\builders\Collection;
use craftpulse\typesense\models\Settings;
use craftpulse\typesense\services\Drift;
use craftpulse\typesense\Typesense;
use craftpulse\typesense\TypesenseCollectionIndex;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Config generation commands: migrate legacy config to the fluent builders, and
 * reverse-migrate a control-panel-managed collection back to a fluent config.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class ConfigController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @var string|null A file path to write the generated config to (otherwise stdout).
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
     * Generates a fluent config file from the legacy TypesenseCollectionIndex
     * configs in the current config file.
     *
     * @return int
     * @author CraftPulse
     */
    public function actionMigrate(): int
    {
        /** @var Settings $settings */
        $settings = Typesense::$plugin->getSettings();
        $collections = $settings->collections;
        $generator = Typesense::$plugin->getConfigGenerator();
        $expressions = [];

        foreach ($collections as $collection) {
            if ($collection instanceof TypesenseCollectionIndex) {
                $expressions[] = $generator->fromLegacy($collection);
            }
        }

        if ($expressions === []) {
            $this->stderr('No legacy TypesenseCollectionIndex configs found to migrate.' . PHP_EOL);

            return ExitCode::OK;
        }

        return $this->_output($generator->file($expressions));
    }

    /**
     * Reports schema drift between the declared config and the live server.
     *
     * @return int
     * @author CraftPulse
     */
    public function actionDiff(): int
    {
        $findings = Typesense::$plugin->getDrift()->diff();

        if ($findings === []) {
            $this->stdout('No collections declared.' . PHP_EOL);

            return ExitCode::OK;
        }

        $drifted = false;

        foreach ($findings as $finding) {
            $status = $finding['status'];

            if ($status === Drift::STATUS_IN_SYNC) {
                $this->stdout("[in sync] {$finding['target']}" . PHP_EOL);
                continue;
            }

            $drifted = true;
            $this->stdout("[{$status}] {$finding['target']}" . PHP_EOL);
            $details = $finding['details'];

            foreach (['missingFields', 'extraFields'] as $key) {
                if (!empty($details[$key])) {
                    $this->stdout('    ' . $key . ': ' . implode(', ', $details[$key]) . PHP_EOL);
                }
            }

            if (!empty($details['typeMismatches'])) {
                foreach ($details['typeMismatches'] as $name => $types) {
                    $this->stdout("    type mismatch {$name}: declared {$types['declared']}, live {$types['live']}" . PHP_EOL);
                }
            }
        }

        return $drifted ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }

    /**
     * Reverse-migrates a control-panel-managed collection into a fluent config.
     *
     * @param string $handle
     * @return int
     * @author CraftPulse
     */
    public function actionExport(string $handle): int
    {
        $collection = Typesense::$plugin->getCollectionRegistry()->get($handle);

        if (!$collection instanceof Collection) {
            $this->stderr("No collection found with the handle \"{$handle}\"." . PHP_EOL);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $generator = Typesense::$plugin->getConfigGenerator();

        return $this->_output($generator->file([$generator->fromCollection($collection)]));
    }

    // Private Methods
    // =========================================================================

    /**
     * Writes the generated config to the path option or stdout.
     *
     * @param string $config
     * @return int
     * @author CraftPulse
     */
    private function _output(string $config): int
    {
        if ($this->path === null) {
            $this->stdout($config . PHP_EOL);

            return ExitCode::OK;
        }

        file_put_contents($this->path, $config);
        $this->stdout("Wrote generated config to {$this->path}." . PHP_EOL);

        return ExitCode::OK;
    }
}
