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

use craftpulse\typesense\widgets\HealthWidget;

it('registers the health widget type on the dashboard', function() {
    $types = Craft::$app->getDashboard()->getAllWidgetTypes();

    expect($types)->toContain(HealthWidget::class);
});

it('renders per-collection health in the widget body', function() {
    $widget = new HealthWidget();
    $html = (string)$widget->getBodyHtml();

    // The playground declares the heroes collection with 488 documents.
    expect($html)->toContain('heroes')
        ->and($html)->toContain('488');
});
