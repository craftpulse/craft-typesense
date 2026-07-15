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
use craftpulse\typesense\models\Experiment;
use craftpulse\typesense\Typesense;

/**
 * A/B search experiment orchestration.
 *
 * Experiments are persisted to project config. Traffic is split by picking a
 * variant (weighted) and deriving a per-variant scoped search key that embeds the
 * variant's analytics tag (when the server supports analytics tags) and applies
 * the variant's scoped-key profile, so each request is attributed to its variant
 * server-side. The click-through / no-result comparison across variants belongs
 * to the analytics dashboard (a later phase); this service builds the definition
 * and the per-variant keys, leaving that comparison as a clean seam.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class Experiments extends Component
{
    // Constants
    // =========================================================================

    /**
     * @var string The project-config key under which experiments live.
     */
    public const CONFIG_KEY = 'typesense.experiments';

    // Public Methods
    // =========================================================================

    /**
     * Deletes an experiment from project config.
     *
     * @param string $handle
     * @return void
     * @throws \yii\base\ErrorException
     * @throws \yii\base\Exception
     * @throws \yii\base\NotSupportedException
     * @throws \yii\web\ServerErrorHttpException
     * @author CraftPulse
     */
    public function delete(string $handle): void
    {
        Craft::$app->getProjectConfig()->remove(
            self::CONFIG_KEY . '.' . $handle,
            "Delete Typesense experiment \u{201C}{$handle}\u{201D}",
        );
    }

    /**
     * Returns an experiment by handle, or null.
     *
     * @param string $handle
     * @return Experiment|null
     * @author CraftPulse
     */
    public function get(string $handle): ?Experiment
    {
        $config = Craft::$app->getProjectConfig()->get(self::CONFIG_KEY . '.' . $handle);

        return is_array($config) ? $this->_fromConfig($handle, $config) : null;
    }

    /**
     * Returns every experiment, keyed by handle.
     *
     * @return array<string, Experiment>
     * @author CraftPulse
     */
    public function getAll(): array
    {
        $config = Craft::$app->getProjectConfig()->get(self::CONFIG_KEY);
        $experiments = [];

        if (is_array($config)) {
            foreach ($config as $handle => $data) {
                if (is_array($data)) {
                    $experiments[(string)$handle] = $this->_fromConfig((string)$handle, $data);
                }
            }
        }

        return $experiments;
    }

    /**
     * Resolves an experiment into a chosen variant and its derived per-variant
     * scoped search key. Returns null for an unknown or disabled experiment.
     *
     * @param string $handle
     * @return array{variant: string, key: string, tag: string, preset: string}|null
     * @throws \yii\base\InvalidConfigException
     * @author CraftPulse
     */
    public function keyFor(string $handle): ?array
    {
        $experiment = $this->get($handle);

        if ($experiment === null || !$experiment->enabled || $experiment->variants === []) {
            return null;
        }

        $variant = $this->pickVariant($experiment);
        $parameters = [];
        $tag = trim((string)($variant['analyticsTag'] ?? ''));
        $capabilities = Typesense::$plugin->getClient()->getServerCapabilities();

        if ($tag !== '' && ($capabilities?->analyticsTags() ?? false)) {
            $parameters['analytics_tag'] = $tag;
        }

        $profile = trim((string)($variant['profile'] ?? ''));
        $key = Typesense::$plugin->getKeys()->generateScopedSearchKey(
            $parameters,
            null,
            $profile !== '' ? $profile : null,
        );

        return [
            'variant' => (string)($variant['handle'] ?? ''),
            'key' => $key,
            'tag' => $tag,
            'preset' => trim((string)($variant['preset'] ?? '')),
        ];
    }

    /**
     * Picks a variant by weight (uniform when no weights are set). Deterministic
     * only in aggregate; each call is an independent weighted draw.
     *
     * @param Experiment $experiment
     * @return array<string, mixed>
     * @author CraftPulse
     */
    public function pickVariant(Experiment $experiment): array
    {
        $variants = array_values($experiment->variants);

        if ($variants === []) {
            return [];
        }

        $total = 0;

        foreach ($variants as $variant) {
            $total += max(1, (int)($variant['weight'] ?? 1));
        }

        $roll = random_int(1, max(1, $total));
        $cursor = 0;

        foreach ($variants as $variant) {
            $cursor += max(1, (int)($variant['weight'] ?? 1));

            if ($roll <= $cursor) {
                return $variant;
            }
        }

        return $variants[0];
    }

    /**
     * Validates an experiment and writes it to project config.
     *
     * @param Experiment $experiment
     * @return bool false when the experiment fails validation
     * @throws \yii\base\ErrorException
     * @throws \yii\base\Exception
     * @throws \yii\base\NotSupportedException
     * @throws \yii\web\ServerErrorHttpException
     * @author CraftPulse
     */
    public function save(Experiment $experiment): bool
    {
        if (!$experiment->validate()) {
            return false;
        }

        Craft::$app->getProjectConfig()->set(
            self::CONFIG_KEY . '.' . $experiment->handle,
            $experiment->getConfig(),
            "Save Typesense experiment \u{201C}{$experiment->handle}\u{201D}",
        );

        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * Hydrates an experiment model from a stored project-config array.
     *
     * @param string $handle
     * @param array<string, mixed> $config
     * @return Experiment
     * @author CraftPulse
     */
    private function _fromConfig(string $handle, array $config): Experiment
    {
        $experiment = new Experiment();
        $experiment->handle = $handle;
        $experiment->name = (string)($config['name'] ?? '');
        $experiment->collection = (string)($config['collection'] ?? '');
        $experiment->enabled = (bool)($config['enabled'] ?? true);
        $experiment->variants = is_array($config['variants'] ?? null) ? array_values($config['variants']) : [];

        return $experiment;
    }
}
