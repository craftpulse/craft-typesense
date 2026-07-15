/**
 * Typesense search playground + document browser.
 *
 * Craft.Typesense.Playground drives the live query console: it serialises the
 * tunable params, runs them against the server through the Pro controller (the
 * admin key never reaches the browser), and renders ranked hits with their
 * text-match scores and timing. Its diff mode runs current versus a pending
 * overlay and renders the ranking change. Craft.Typesense.DocumentBrowser pages
 * the actual indexed documents and opens a JSON detail HUD per row.
 *
 * Accessibility: controls are real buttons; the detail HUD manages focus and
 * closes on Escape through the UI layer manager; destroy() tears listeners and
 * any open HUD down.
 *
 * @author CraftPulse
 * @since 5.9.0
 */

/** global: Garnish */
/** global: Craft */
/** global: $ */

if (typeof Craft.Typesense === 'undefined') {
    Craft.Typesense = {};
}

function tsEscape(value) {
    return $('<div>').text(value === null || value === undefined ? '' : String(value)).html();
}

Craft.Typesense.Playground = Garnish.Base.extend({
    $container: null,
    handle: null,

    init: function (container, settings) {
        this.$container = $(container);
        this.setSettings(settings, {});
        this.handle = this.$container.data('collection');

        this.addListener(this.$container.find('.ts-pg-run'), 'activate', 'run');
        this.addListener(this.$container.find('.ts-pg-diff'), 'activate', 'diff');
    },

    collectParams: function (selector) {
        var params = {};
        this.$container.find(selector).each(function () {
            var $field = $(this);
            var key = $field.data('param') || $field.data('overlay');
            if (!key) {
                return;
            }
            params[key] = $field.attr('type') === 'checkbox' ? $field.prop('checked') : $field.val();
        });
        return params;
    },

    run: function () {
        var self = this;
        Craft.sendActionRequest('POST', 'typesense/playground/run', {
            data: {collection: this.handle, params: this.collectParams('[data-param]')},
        })
            .then(function (response) {
                self.renderResults(response.data);
            })
            .catch(function () {
                self.renderError();
            });
    },

    diff: function () {
        var self = this;
        Craft.sendActionRequest('POST', 'typesense/playground/diff', {
            data: {
                collection: this.handle,
                params: this.collectParams('[data-param]'),
                overlay: this.collectParams('[data-overlay]'),
            },
        })
            .then(function (response) {
                self.renderDiff(response.data);
            })
            .catch(function () {
                self.renderError();
            });
    },

    renderResults: function (data) {
        var html = '<p class="light">' +
            Craft.t('typesense', 'Found {n} results in {ms} ms', {n: data.found, ms: data.searchTimeMs}) +
            '</p>' + this.hitsTable(data.hits);

        if (data.facetCounts && data.facetCounts.length) {
            html += this.facetsHtml(data.facetCounts);
        }

        this.$container.find('.ts-pg-results').html(html);
    },

    renderDiff: function (data) {
        var moved = (data.diff.moved || []).map(function (m) {
            return tsEscape(m.id) + ' (' + m.from + '&rarr;' + m.to + ')';
        }).join(', ');

        var html = '<div class="ts-pg-diff-grid">' +
            '<div><h3>' + Craft.t('typesense', 'Current') + '</h3>' + this.hitsTable(data.current.hits) + '</div>' +
            '<div><h3>' + Craft.t('typesense', 'Proposed') + '</h3>' + this.hitsTable(data.proposed.hits) + '</div>' +
            '</div>' +
            '<ul class="ts-pg-diff-summary">' +
            '<li><strong>' + Craft.t('typesense', 'Entered') + ':</strong> ' + (data.diff.entered.map(tsEscape).join(', ') || '&mdash;') + '</li>' +
            '<li><strong>' + Craft.t('typesense', 'Dropped') + ':</strong> ' + (data.diff.dropped.map(tsEscape).join(', ') || '&mdash;') + '</li>' +
            '<li><strong>' + Craft.t('typesense', 'Moved') + ':</strong> ' + (moved || '&mdash;') + '</li>' +
            '</ul>';

        this.$container.find('.ts-pg-results').html(html);
    },

    hitsTable: function (hits) {
        if (!hits || !hits.length) {
            return '<p class="light">' + Craft.t('typesense', 'No results.') + '</p>';
        }
        var rows = hits.map(function (hit) {
            return '<tr><td>' + hit.rank + '</td><td><code>' + tsEscape(hit.id) + '</code></td><td>' +
                tsEscape(hit.textMatch) + '</td></tr>';
        }).join('');
        return '<table class="data fullwidth"><thead><tr><th>#</th><th>' +
            Craft.t('typesense', 'ID') + '</th><th>' + Craft.t('typesense', 'Text match') +
            '</th></tr></thead><tbody>' + rows + '</tbody></table>';
    },

    facetsHtml: function (facetCounts) {
        var html = '<h3>' + Craft.t('typesense', 'Facets') + '</h3>';
        facetCounts.forEach(function (facet) {
            html += '<p><strong>' + tsEscape(facet.field_name) + '</strong></p><ul>';
            (facet.counts || []).forEach(function (c) {
                html += '<li>' + tsEscape(c.value) + ' (' + tsEscape(c.count) + ')</li>';
            });
            html += '</ul>';
        });
        return html;
    },

    renderError: function () {
        this.$container.find('.ts-pg-results').html(
            '<p class="error">' + Craft.t('typesense', 'The query could not be run.') + '</p>'
        );
    },
});

Craft.Typesense.DocumentBrowser = Garnish.Base.extend({
    $container: null,
    handle: null,
    hud: null,
    page: 1,

    init: function (container) {
        this.$container = $(container);
        this.handle = this.$container.data('collection');

        this.addListener(this.$container.find('.ts-db-apply'), 'activate', function () {
            this.page = 1;
            this.load();
        });
        this.addListener(this.$container.find('.ts-db-prev'), 'activate', function () {
            if (this.page > 1) {
                this.page--;
                this.load();
            }
        });
        this.addListener(this.$container.find('.ts-db-next'), 'activate', function () {
            this.page++;
            this.load();
        });

        this.load();
    },

    load: function () {
        var self = this;
        Craft.sendActionRequest('POST', 'typesense/playground/documents', {
            data: {
                collection: this.handle,
                page: this.page,
                perPage: 25,
                filterBy: this.$container.find('.ts-db-filter').val(),
                sortBy: this.$container.find('.ts-db-sort').val(),
            },
        })
            .then(function (response) {
                self.render(response.data);
            })
            .catch(function () {
                self.$container.find('.ts-db-rows').html(
                    '<p class="error">' + Craft.t('typesense', 'The query could not be run.') + '</p>'
                );
            });
    },

    render: function (data) {
        var self = this;
        var stats = data.stats || {};
        this.$container.find('.ts-db-stats').text(
            Craft.t('typesense', '{n} documents', {n: stats.numDocuments || 0}) +
            ' | ' + Craft.t('typesense', 'Page {p}', {p: this.page})
        );

        if (!data.documents.length) {
            this.$container.find('.ts-db-rows').html('<p class="light">' + Craft.t('typesense', 'No documents.') + '</p>');
            return;
        }

        var rows = data.documents.map(function (doc, i) {
            return '<tr><td><code>' + tsEscape(doc.id) + '</code></td>' +
                '<td><button type="button" class="btn small ts-db-view" data-index="' + i + '">' +
                Craft.t('typesense', 'View JSON') + '</button></td></tr>';
        }).join('');

        this.$container.find('.ts-db-rows').html(
            '<table class="data fullwidth"><thead><tr><th>' + Craft.t('typesense', 'ID') +
            '</th><th></th></tr></thead><tbody>' + rows + '</tbody></table>'
        );

        this.removeListener(this.$container.find('.ts-db-view'), 'activate');
        this.addListener(this.$container.find('.ts-db-view'), 'activate', function (ev) {
            self.showDocument(data.documents[$(ev.currentTarget).data('index')], ev.currentTarget);
        });
    },

    showDocument: function (doc, trigger) {
        var html = '<pre class="ts-db-json">' + tsEscape(JSON.stringify(doc, null, 2)) + '</pre>';
        this.hud = new Garnish.HUD(trigger, html, {hudClass: 'hud ts-db-hud'});
    },

    destroy: function () {
        if (this.hud) {
            this.hud.destroy();
        }
        this.base();
    },
});
