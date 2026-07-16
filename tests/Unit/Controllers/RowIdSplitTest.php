<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * Pins the composite VueAdminTable row-id split on the synonyms and curation
 * section controllers. The delete affordance posts "<screenKey>|<id>", where the
 * screen key is a uid or a "config:<name>" token (neither ever contains a pipe)
 * and the id is a user-supplied synonym/rule id that MAY contain a pipe. The
 * split must therefore break on the FIRST separator only (explode limit 2), so a
 * pipe in the id survives and the right row is deleted.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\typesense\controllers\CurationController;
use craftpulse\typesense\controllers\SynonymsController;
use craftpulse\typesense\Typesense;

/**
 * Invokes the private `_splitRowId` on a freshly built controller.
 *
 * @param object $controller
 * @param string $rowId
 * @return array{0: string, 1: string}
 */
function splitRowId(object $controller, string $rowId): array
{
    $method = new ReflectionMethod($controller, '_splitRowId');

    /** @var array{0: string, 1: string} $parts */
    $parts = $method->invoke($controller, $rowId);

    return $parts;
}

it('splits a synonyms row id on the first separator, keeping a pipe in the id', function() {
    $controller = new SynonymsController('synonyms', Typesense::$plugin);

    expect(splitRowId($controller, 'config:heroes|brand|acme|corp'))
        ->toBe(['config:heroes', 'brand|acme|corp'])
        ->and(splitRowId($controller, 'a1b2c3d4-uid|outerwear'))
        ->toBe(['a1b2c3d4-uid', 'outerwear'])
        ->and(splitRowId($controller, 'config:heroes|'))
        ->toBe(['config:heroes', '']);
});

it('splits a curation row id on the first separator, keeping a pipe in the id', function() {
    $controller = new CurationController('curation', Typesense::$plugin);

    expect(splitRowId($controller, 'config:heroes|rule|with|pipes'))
        ->toBe(['config:heroes', 'rule|with|pipes'])
        ->and(splitRowId($controller, 'a1b2c3d4-uid|pinned-hero'))
        ->toBe(['a1b2c3d4-uid', 'pinned-hero']);
});
