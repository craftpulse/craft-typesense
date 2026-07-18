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

use craftpulse\typesense\services\Audit;
use craftpulse\typesense\Typesense;
use yii\base\InvalidConfigException;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * Scoped search key commands.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class KeysController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @var string|null A filter_by expression to embed in the scoped key.
     */
    public ?string $filter = null;

    /**
     * @var int|null Seconds from now until the key expires.
     */
    public ?int $expiresIn = null;

    /**
     * @var int|null The maximum number of hits the key may return.
     */
    public ?int $limitHits = null;

    /**
     * @var int|null The maximum number of multi-searches the key may run.
     */
    public ?int $limitMultiSearches = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), [
            'filter', 'expiresIn', 'limitHits', 'limitMultiSearches',
        ]);
    }

    /**
     * Derives and prints a scoped search key from the search-only key.
     *
     * @return int
     * @author CraftPulse
     */
    public function actionGenerateScoped(): int
    {
        $parameters = [];

        if ($this->filter !== null) {
            $parameters['filter_by'] = $this->filter;
        }

        if ($this->expiresIn !== null) {
            $parameters['expires_at'] = time() + $this->expiresIn;
        }

        if ($this->limitHits !== null) {
            $parameters['limit_hits'] = $this->limitHits;
        }

        if ($this->limitMultiSearches !== null) {
            $parameters['limit_multi_searches'] = $this->limitMultiSearches;
        }

        try {
            $key = Typesense::$plugin->getKeys()->generateScopedSearchKey($parameters);
        } catch (InvalidConfigException $e) {
            $this->stderr($e->getMessage() . PHP_EOL);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        Typesense::$plugin->getAudit()->record(Audit::EVENT_KEY_SCOPED_GENERATED, [
            'parameterCount' => count($parameters),
        ]);
        $this->stdout($key . PHP_EOL);

        return ExitCode::OK;
    }
}
