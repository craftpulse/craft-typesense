<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The relevance-tuning service: additive boost scoring (the deterministic
 * alternative to the first-match-wins _eval bug) and the preset compiler. Boost
 * scoring is pure logic over an element; preset compilation capability-gates the
 * version-sensitive parameters (text-match buckets, MMR) so a server that does
 * not understand them never receives them.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craft\elements\Entry;
use craftpulse\typesense\models\ServerCapabilities;
use craftpulse\typesense\Typesense;

it('sums the weights of every matching boost rule (additive, not first-match)', function() {
    $entry = Entry::find()->section('heroes')->status('live')->siteId(1)->one();
    $relevance = Typesense::$plugin->getRelevance();

    $rules = [
        ['weight' => 50, 'match' => 'all', 'conditions' => [['field' => 'slug', 'operator' => 'eq', 'value' => $entry->slug]]],
        ['weight' => 10, 'match' => 'all', 'conditions' => [['field' => 'slug', 'operator' => 'eq', 'value' => '__nope__']]],
        ['weight' => 7, 'match' => 'all', 'conditions' => [['field' => 'title', 'operator' => 'notEmpty', 'value' => '']]],
    ];

    // 50 (slug matches) + 7 (title not empty); the non-matching rule adds nothing.
    expect($relevance->boostScore($entry, $rules))->toBe(57.0);
});

it('honours all vs any match modes', function() {
    $entry = Entry::find()->section('heroes')->status('live')->siteId(1)->one();
    $relevance = Typesense::$plugin->getRelevance();

    $allRule = ['weight' => 1, 'match' => 'all', 'conditions' => [
        ['field' => 'slug', 'operator' => 'eq', 'value' => $entry->slug],
        ['field' => 'slug', 'operator' => 'eq', 'value' => '__nope__'],
    ]];
    $anyRule = ['weight' => 1, 'match' => 'any', 'conditions' => [
        ['field' => 'slug', 'operator' => 'eq', 'value' => $entry->slug],
        ['field' => 'slug', 'operator' => 'eq', 'value' => '__nope__'],
    ]];

    expect($relevance->matchesRule($entry, $allRule))->toBeFalse()
        ->and($relevance->matchesRule($entry, $anyRule))->toBeTrue();
});

it('wires the baked boost_score into the sort chain after the text-match sort', function() {
    $preset = Typesense::$plugin->getRelevance()->presetFragment([], null, true);

    expect($preset['sort_by'])->toBe('_text_match:desc, boost_score:desc');
});

it('gates text-match buckets and MMR on the server version', function() {
    $relevance = Typesense::$plugin->getRelevance();
    $definition = [
        'textMatchBuckets' => 3,
        'mmr' => ['enabled' => true, 'lambda' => 0.4],
    ];

    // A v28 server buckets but has no MMR.
    $v28 = $relevance->presetFragment($definition, new ServerCapabilities(['version' => '28.0']));
    expect($v28['sort_by'])->toBe('_text_match(buckets: 3):desc')
        ->and($v28)->not->toHaveKey('mmr');

    // A v30.2 server understands both.
    $v302 = $relevance->presetFragment($definition, new ServerCapabilities(['version' => '30.2']));
    expect($v302['sort_by'])->toBe('_text_match(buckets: 3):desc')
        ->and($v302['mmr'])->toBe('true')
        ->and($v302['mmr_lambda'])->toBe(0.4);

    // No detected server: neither parameter is emitted.
    $none = $relevance->presetFragment($definition, null);
    expect($none)->not->toHaveKey('sort_by')
        ->and($none)->not->toHaveKey('mmr');
});

it('passes through raw search-preset parameters and grouping, dropping blanks', function() {
    $preset = Typesense::$plugin->getRelevance()->presetFragment([
        'searchPreset' => ['num_typos' => '2', 'prefix' => 'true', 'drop_tokens_threshold' => ''],
        'grouping' => ['field' => 'kind', 'limit' => 2],
    ], null);

    expect($preset['num_typos'])->toBe('2')
        ->and($preset['prefix'])->toBe('true')
        ->and($preset)->not->toHaveKey('drop_tokens_threshold')
        ->and($preset['group_by'])->toBe('kind')
        ->and($preset['group_limit'])->toBe(2);
});
