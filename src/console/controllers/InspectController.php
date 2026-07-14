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

        $sync = Typesense::$plugin->getSync();
        $documents = Typesense::$plugin->getDocuments();
        $siteId = (int)$element->siteId;
        $class = $element::class;
        $status = (string)$element->getStatus();
        $this->stdout("Element {$elementId} ({$class}), site {$siteId}, status {$status}" . PHP_EOL);

        foreach (Typesense::$plugin->getCollectionRegistry()->getAll() as $collection) {
            $type = $collection->getElementType();
            $name = $collection->getName();

            if (!$element instanceof $type) {
                continue;
            }

            if (!$sync->matchesCollection($collection, $element)) {
                $this->stdout("  {$name}: not a member of this collection's query" . PHP_EOL);
                continue;
            }

            if (!$documents->isActive($collection, $element)) {
                $this->stdout("  {$name}: member but not in an active status (would be deleted)" . PHP_EOL);
                continue;
            }

            $result = $documents->build($collection, $element, $siteId);

            if ($result->isEmpty()) {
                $this->stdout("  {$name}: builds no documents (transform returned nothing, for example a null value or a zero coordinate)" . PHP_EOL);
                continue;
            }

            $this->stdout("  {$name}: " . count($result->documents) . ' document(s)' . PHP_EOL);
            $this->stdout('    ' . Json::encode($result->documents[0]) . PHP_EOL);
        }

        return ExitCode::OK;
    }
}
