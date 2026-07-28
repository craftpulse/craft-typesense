<?php
/**
 * Typesense plugin for Craft CMS 5.x
 *
 * The dashboard health widget: its body renders the per-collection health
 * (document count, last sync, drift) from the same sources as the utility. The
 * widget type is registered on the dashboard.
 *
 * @link      https://craft-pulse.com
 * @copyright Copyright (c) 2026 CraftPulse
 */

use craftpulse\typesense\Typesense;
use craftpulse\typesense\widgets\HealthWidget;

it('registers the health widget type on the dashboard', function() {
    $types = Craft::$app->getDashboard()->getAllWidgetTypes();

    expect($types)->toContain(HealthWidget::class);
});

it('renders per-collection health in the widget body', function() {
    $widget = new HealthWidget();
    $html = (string)$widget->getBodyHtml();

    // The fixture "heroes" collection's real document count (see
    // tests/Support/typesense-fixtures.php), read fresh rather than
    // hardcoded - a hardcoded count is exactly what drifted against the
    // shared live "heroes" collection before this suite owned its own
    // Typesense-side fixture.
    $registry = Typesense::$plugin->getCollectionRegistry();
    $collection = $registry->get('heroes');
    $target = $registry->resolveName($collection, Craft::$app->getSites()->getPrimarySite()->id);
    $numDocuments = Typesense::$plugin->getClient()->client()->collections[$target]->retrieve()['num_documents'];

    expect($html)->toContain('heroes')
        ->and($html)->toContain((string)$numDocuments);
});
