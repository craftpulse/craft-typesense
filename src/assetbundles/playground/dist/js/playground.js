/**
 * Typesense search playground + document browser.
 *
 * Craft.Typesense.Playground drives the full-viewport query console: it
 * serialises the tunable params, runs them against the server through the Pro
 * controller (the admin key never reaches the browser), and renders ranked hits
 * with a stats bar, per-hit score badges, and collapsible document JSON. Run is
 * a button and Cmd/Ctrl+Enter. Diff mode runs current versus a pending overlay
 * and shows the two rankings side by side. Craft.Typesense.DocumentBrowser pages
 * the actual indexed documents and expands each row's JSON inline.
 *
 * Accessibility: controls are real buttons; document JSON expands through native
 * <details> disclosure (no focus-trapping HUD to leak); destroy() tears the
 * listeners down.
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

function tsJsonDisclosure(doc, label) {
    return '<details class="ts-pg-json"><summary>' + tsEscape(label) + '</summary>' +
        '<pre>' + tsEscape(JSON.stringify(doc, null, 2)) + '</pre></details>';
}

Craft.Typesense.Playground = Garnish.Base.extend({
    $container: null,
    $results: null,
    handle: null,

    init: function (container, settings) {
        this.$container = $(container);
        this.setSettings(settings, {});
        this.handle = this.$container.data('collection');
        this.$results = this.$container.find('.ts-pg-results');

        this.addListener(this.$container.find('.ts-pg-run'), 'activate', 'run');
        this.addListener(this.$container.find('.ts-pg-diff'), 'activate', 'diff');

        // Cmd/Ctrl+Enter runs the query from anywhere in the console.
        this.addListener(this.$container, 'keydown', function (ev) {
            if ((ev.metaKey || ev.ctrlKey) && ev.keyCode === Garnish.RETURN_KEY) {
                ev.preventDefault();
                this.run();
            }
        });
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
        this.renderLoading();
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
        this.renderLoading();
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

    renderLoading: function () {
        this.$results.html('<div class="ts-pg-empty"><div class="spinner"></div></div>');
    },

    renderResults: function (data) {
        var html = this.statsBar(data) + this.hits(data.hits);

        if (data.facetCounts && data.facetCounts.length) {
            html += this.facetsHtml(data.facetCounts);
        }

        this.$results.html(html);
    },

    renderDiff: function (data) {
        var none = Craft.t('typesense', 'None');
        var moved = (data.diff.moved || []).map(function (m) {
            return tsEscape(m.id) + ' (' + m.from + '→' + m.to + ')';
        }).join(', ');

        var html = '<div class="ts-pg-diff-grid">' +
            '<section><h2>' + Craft.t('typesense', 'Current') + '</h2>' +
            this.statsBar(data.current) + this.hits(data.current.hits) + '</section>' +
            '<section><h2>' + Craft.t('typesense', 'Proposed') + '</h2>' +
            this.statsBar(data.proposed) + this.hits(data.proposed.hits) + '</section>' +
            '</div>' +
            '<ul class="ts-pg-diff-summary">' +
            '<li><strong>' + Craft.t('typesense', 'Entered') + ':</strong> ' + (data.diff.entered.map(tsEscape).join(', ') || none) + '</li>' +
            '<li><strong>' + Craft.t('typesense', 'Dropped') + ':</strong> ' + (data.diff.dropped.map(tsEscape).join(', ') || none) + '</li>' +
            '<li><strong>' + Craft.t('typesense', 'Moved') + ':</strong> ' + (moved || none) + '</li>' +
            '</ul>';

        this.$results.html(html);
    },

    statsBar: function (data) {
        var parts = [Craft.t('typesense', '{n} results', {n: data.found || 0})];
        if (data.searchTimeMs !== null && data.searchTimeMs !== undefined) {
            parts.push(Craft.t('typesense', '{ms} ms', {ms: data.searchTimeMs}));
        }
        return '<div class="ts-pg-statsbar">' + parts.join(' · ') + '</div>';
    },

    hits: function (hits) {
        if (!hits || !hits.length) {
            return '<div class="ts-pg-empty"><p class="light">' + Craft.t('typesense', 'No results.') + '</p></div>';
        }
        var rows = hits.map(function (hit) {
            var badge = hit.textMatch !== null && hit.textMatch !== undefined
                ? '<span class="ts-pg-badge" title="' + Craft.t('typesense', 'Text match score') + '">' + tsEscape(hit.textMatch) + '</span>'
                : '';
            return '<li class="ts-pg-hit">' +
                '<span class="ts-pg-hit__rank">' + hit.rank + '</span>' +
                '<code class="ts-pg-hit__id">' + tsEscape(hit.id) + '</code>' +
                badge +
                tsJsonDisclosure(hit.document, Craft.t('typesense', 'JSON')) +
                '</li>';
        }).join('');
        return '<ul class="ts-pg-hits">' + rows + '</ul>';
    },

    facetsHtml: function (facetCounts) {
        var html = '<h2>' + Craft.t('typesense', 'Facets') + '</h2>';
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
        this.$results.html(
            '<div class="ts-pg-empty"><p class="error">' + Craft.t('typesense', 'The query could not be run.') + '</p></div>'
        );
    },
});

Craft.Typesense.DocumentBrowser = Garnish.Base.extend({
    $container: null,
    $rows: null,
    handle: null,
    page: 1,

    init: function (container) {
        this.$container = $(container);
        this.handle = this.$container.data('collection');
        this.$rows = this.$container.find('.ts-db-rows');

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
        this.$rows.html('<div class="ts-pg-empty"><div class="spinner"></div></div>');
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
                self.$rows.html(
                    '<div class="ts-pg-empty"><p class="error">' + Craft.t('typesense', 'The query could not be run.') + '</p></div>'
                );
            });
    },

    render: function (data) {
        var stats = data.stats || {};
        this.$container.find('.ts-db-stats').text(
            Craft.t('typesense', '{n} documents', {n: stats.numDocuments || 0}) +
            ' · ' + Craft.t('typesense', 'Page {p}', {p: this.page})
        );

        if (!data.documents.length) {
            this.$rows.html('<div class="ts-pg-empty"><p class="light">' + Craft.t('typesense', 'No documents.') + '</p></div>');
            return;
        }

        var rows = data.documents.map(function (doc) {
            return '<li class="ts-pg-hit">' +
                '<code class="ts-pg-hit__id">' + tsEscape(doc.id) + '</code>' +
                tsJsonDisclosure(doc, Craft.t('typesense', 'JSON')) +
                '</li>';
        }).join('');

        this.$rows.html('<ul class="ts-pg-hits">' + rows + '</ul>');
    },
});
