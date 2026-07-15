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
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Element indexing inspector: explains, for one element, which collections it
 * belongs to, whether it is indexable, and what document it produces (or why it
 * is skipped).
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class InspectController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Inspects an element's Typesense indexing state.
     *
     * @param int $elementId
     * @return int
     * @author CraftPulse
     */
    public function actionIndex(int $elementId): int
    {
        /** @phpstan-ignore-next-line the inspected element type is not known ahead of time */
        $element = Craft::$app->getElements()->getElementById($elementId);

        if ($element === null) {
            $this->stderr("No element found with ID {$elementId}." . PHP_EOL);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $class = $element::class;
        $report = Typesense::$plugin->getInspector()->inspectElement($element);
        $this->stdout("Element {$elementId} ({$class}), site {$report['siteId']}, status {$report['status']}" . PHP_EOL);

        if ($report['suspended']) {
            $this->stdout('  Note: syncing is suspended, so no changes are being written.' . PHP_EOL);
        }

        foreach ($report['collections'] as $collection) {
            $this->stdout("  {$collection['name']}: {$collection['reason']}" . PHP_EOL);

            if ($collection['documents'] !== []) {
                $this->stdout('    ' . Json::encode($collection['documents'][0]) . PHP_EOL);
            }
        }

        return ExitCode::OK;
    }
}
