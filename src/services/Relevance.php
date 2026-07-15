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

use craft\base\Component;
use craft\base\ElementInterface;
use craftpulse\typesense\models\ServerCapabilities;

/**
 * Relevance-tuning compiler and evaluator.
 *
 * Additive boost rules are baked into an indexed `boost_score` field at index
 * time, the deterministic alternative to Typesense's first-match-wins `_eval`
 * bug (v27-30.1): a document's boost score is the sum of the weights of every
 * rule it matches, so the ranking is stable and testable. This service both
 * evaluates that score for an element (the index-time path) and compiles the
 * relevance definition into Typesense preset parameters (the search-time path),
 * capability-gating the parameters that only newer servers understand.
 *
 * @author    CraftPulse
 * @package   Typesense
 * @since     5.9.0
 */
class Relevance extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * The additive boost score for an element: the sum of the weights of every
     * boost rule it matches.
     *
     * @param ElementInterface $element
     * @param array<int, array<string, mixed>> $rules
     * @return float
     * @author CraftPulse
     */
    public function boostScore(ElementInterface $element, array $rules): float
    {
        $score = 0.0;

        foreach ($rules as $rule) {
            if ($this->matchesRule($element, $rule)) {
                $score += (float)($rule['weight'] ?? 0);
            }
        }

        return $score;
    }

    /**
     * Whether an element matches a boost rule. `match` is `all` (every condition,
     * the default) or `any` (at least one).
     *
     * @param ElementInterface $element
     * @param array<string, mixed> $rule
     * @return bool
     * @author CraftPulse
     */
    public function matchesRule(ElementInterface $element, array $rule): bool
    {
        $conditions = is_array($rule['conditions'] ?? null) ? $rule['conditions'] : [];

        if ($conditions === []) {
            return false;
        }

        $any = ($rule['match'] ?? 'all') === 'any';

        foreach ($conditions as $condition) {
            $matched = $this->_matchesCondition($element, is_array($condition) ? $condition : []);

            if ($any && $matched) {
                return true;
            }

            if (!$any && !$matched) {
                return false;
            }
        }

        return !$any;
    }

    /**
     * Compiles the relevance definition into Typesense preset parameters. Only
     * parameters the server supports are emitted (hide, never badge): text-match
     * buckets and MMR are version-gated. `$hasBoost` wires the baked
     * `boost_score` into the sort chain after the text-match sort.
     *
     * @param array<string, mixed> $relevance
     * @param ServerCapabilities|null $capabilities
     * @param bool $hasBoost
     * @param string $boostField
     * @return array<string, mixed>
     * @author CraftPulse
     */
    public function presetFragment(
        array $relevance,
        ?ServerCapabilities $capabilities,
        bool $hasBoost = false,
        string $boostField = 'boost_score',
    ): array {
        $preset = [];

        foreach (is_array($relevance['searchPreset'] ?? null) ? $relevance['searchPreset'] : [] as $key => $value) {
            if (is_scalar($value) && (string)$value !== '') {
                $preset[(string)$key] = $value;
            }
        }

        $buckets = (int)($relevance['textMatchBuckets'] ?? 0);
        $bucketsOn = $buckets > 0 && ($capabilities?->textMatchBuckets() ?? false);
        $sort = [];

        if ($hasBoost || $bucketsOn) {
            $sort[] = $bucketsOn ? sprintf('_text_match(buckets: %d):desc', $buckets) : '_text_match:desc';
        }

        if ($hasBoost) {
            $sort[] = $boostField . ':desc';
        }

        if ($sort !== []) {
            $preset['sort_by'] = implode(', ', $sort);
        }

        $groupBy = trim((string)($relevance['grouping']['field'] ?? ''));

        if ($groupBy !== '') {
            $preset['group_by'] = $groupBy;
            $groupLimit = (int)($relevance['grouping']['limit'] ?? 1);

            if ($groupLimit > 0) {
                $preset['group_limit'] = $groupLimit;
            }
        }

        if (!empty($relevance['mmr']['enabled']) && ($capabilities?->mmr() ?? false)) {
            $preset['mmr'] = 'true';
            $lambda = (float)($relevance['mmr']['lambda'] ?? 0);

            if ($lambda > 0) {
                $preset['mmr_lambda'] = $lambda;
            }
        }

        return $preset;
    }

    // Private Methods
    // =========================================================================

    /**
     * Whether one condition (`field`, `operator`, `value`) holds for an element.
     *
     * @param ElementInterface $element
     * @param array<string, mixed> $condition
     * @return bool
     * @author CraftPulse
     */
    private function _matchesCondition(ElementInterface $element, array $condition): bool
    {
        $field = trim((string)($condition['field'] ?? ''));

        if ($field === '') {
            return false;
        }

        $actual = $this->_value($element, $field);
        $expected = (string)($condition['value'] ?? '');

        return match ((string)($condition['operator'] ?? 'eq')) {
            'ne' => $this->_scalar($actual) !== $expected,
            'contains' => is_array($actual)
                ? in_array($expected, array_map([$this, '_scalar'], $actual), true)
                : str_contains($this->_scalar($actual), $expected),
            'gt' => (float)$this->_scalar($actual) > (float)$expected,
            'gte' => (float)$this->_scalar($actual) >= (float)$expected,
            'lt' => (float)$this->_scalar($actual) < (float)$expected,
            'lte' => (float)$this->_scalar($actual) <= (float)$expected,
            'empty' => $this->_scalar($actual) === '',
            'notEmpty' => $this->_scalar($actual) !== '',
            default => $this->_scalar($actual) === $expected,
        };
    }

    /**
     * Casts a resolved value to a comparable scalar string.
     *
     * @param mixed $value
     * @return string
     * @author CraftPulse
     */
    private function _scalar(mixed $value): string
    {
        if ($value instanceof ElementInterface) {
            return (string)$value->id;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * Resolves a field/attribute value from an element, falling back to the
     * custom-field value.
     *
     * @param ElementInterface $element
     * @param string $field
     * @return mixed
     * @author CraftPulse
     */
    private function _value(ElementInterface $element, string $field): mixed
    {
        try {
            if (isset($element->$field) || $element->canGetProperty($field)) {
                return $element->$field;
            }
        } catch (\Throwable) {
            // fall through to the custom-field lookup
        }

        try {
            return $element->getFieldValue($field);
        } catch (\Throwable) {
            return null;
        }
    }
}
