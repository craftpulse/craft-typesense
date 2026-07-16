/**
 * Typesense search playground (one GraphiQL-style screen).
 *
 * Craft.Typesense.Playground drives the unified playground: a search-params JSON
 * editor (with a line-number gutter) on the left, ranked results on the right,
 * and a docs-explorer side pane carrying the live schema and a pageable,
 * filterable document browser. Run is a header button and Cmd/Ctrl+Enter. A diff
 * drawer runs current versus a pending overlay and shows the two rankings side by
 * side. Every query goes through the Pro controller, so the admin key never
 * reaches the browser.
 *
 * The editor is a plain textarea plus a synced gutter (no CodeMirror or other
 * external editor lib, per the plugin's CSP/asset rules); results render with a
 * stats bar, per-hit score badges, and native <details> JSON disclosures.
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
    $editor: null,
    $overlay: null,
    $results: null,
    $rows: null,
    handle: null,
    page: 1,

    init: function (container) {
        this.$container = $(container);
        this.handle = this.$container.data('collection');
        this.$editor = this.$container.find('.ts-pg__editor .ts-pg-editor__input').first();
        this.$overlay = this.$container.find('.ts-pg-overlay');
        this.$results = this.$container.find('.ts-pg-results');
        this.$rows = this.$container.find('.ts-db-rows');

        var self = this;
        this.$container.find('.ts-pg-editor').each(function () {
            self.syncGutter($(this));
        });

        this.addListener($('.ts-pg-run'), 'activate', 'run');
        this.addListener($('.ts-pg-diff-toggle'), 'activate', function () {
            this.$container.find('.ts-pg-diff-drawer').toggleClass('hidden');
        });
        this.addListener(this.$container.find('.ts-pg-diff-run'), 'activate', 'diff');
        this.addListener(this.$container.find('.ts-db-apply'), 'activate', function () {
            this.page = 1;
            this.loadDocuments();
        });
        this.addListener(this.$container.find('.ts-db-prev'), 'activate', function () {
            if (this.page > 1) {
                this.page--;
                this.loadDocuments();
            }
        });
        this.addListener(this.$container.find('.ts-db-next'), 'activate', function () {
            this.page++;
            this.loadDocuments();
        });

        // The collection picker in the header navigates to that collection.
        this.addListener($('.ts-pg-collection'), 'change', function (ev) {
            window.location.href = Craft.getCpUrl('typesense/playground/' + $(ev.currentTarget).val());
        });

        // Cmd/Ctrl+Enter runs the query from anywhere in the screen.
        this.addListener(this.$container, 'keydown', function (ev) {
            if ((ev.metaKey || ev.ctrlKey) && ev.keyCode === Garnish.RETURN_KEY) {
                ev.preventDefault();
                this.run();
            }
        });

        var initialFilter = this.$container.data('initial-filter');
        if (initialFilter) {
            this.$container.find('.ts-db-filter').val(initialFilter);
        }

        this.loadSchema();
        this.loadDocuments();

        // Deep link: #diff opens the Diff drawer on load (the relevance editor
        // links here so a pending weight/preset change previews straight away).
        // Same class the toggle button flips; the collection is already
        // preselected via the URL.
        if (window.location.hash === '#diff') {
            this.$container.find('.ts-pg-diff-drawer').removeClass('hidden');
        }
    },

    syncGutter: function ($editor) {
        var $input = $editor.find('.ts-pg-editor__input');
        var $gutter = $editor.find('.ts-pg-editor__gutter');

        var update = function () {
            var lines = (String($input.val()).match(/\n/g) || []).length + 1;
            var numbers = [];
            for (var i = 1; i <= lines; i++) {
                numbers.push(i);
            }
            $gutter.text(numbers.join('\n'));
        };

        this.addListener($input, 'input', update);
        this.addListener($input, 'scroll', function () {
            $gutter.scrollTop($input.scrollTop());
        });

        update();
    },

    run: function () {
        var self = this;
        this.renderLoading();
        Craft.sendActionRequest('POST', 'typesense/playground/run', {
            data: {collection: this.handle, body: this.$editor.val()},
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
                body: this.$editor.val(),
                overlay: this.$overlay.val(),
            },
        })
            .then(function (response) {
                self.renderDiff(response.data);
            })
            .catch(function () {
                self.renderError();
            });
    },

    loadSchema: function () {
        var self = this;
        Craft.sendActionRequest('POST', 'typesense/playground/schema', {
            data: {collection: this.handle},
        })
            .then(function (response) {
                var fields = response.data.fields || [];
                var html = fields.length
                    ? fields.map(function (f) {
                        return '<li><code>' + tsEscape(f.name) + '</code> <span class="light">' + tsEscape(f.type) + '</span></li>';
                    }).join('')
                    : '<li class="light">' + Craft.t('typesense', 'No schema available.') + '</li>';
                self.$container.find('.ts-pg-schema').html(html);
            })
            .catch(function () {
                self.$container.find('.ts-pg-schema').html('<li class="error">' + Craft.t('typesense', 'The query could not be run.') + '</li>');
            });
    },

    loadDocuments: function () {
        var self = this;
        this.$rows.html('<div class="ts-pg-empty"><div class="spinner"></div></div>');
        Craft.sendActionRequest('POST', 'typesense/playground/documents', {
            data: {
                collection: this.handle,
                page: this.page,
                perPage: 25,
                filterBy: this.$container.find('.ts-db-filter').val(),
            },
        })
            .then(function (response) {
                self.renderDocuments(response.data);
            })
            .catch(function () {
                self.$rows.html('<div class="ts-pg-empty"><p class="error">' + Craft.t('typesense', 'The query could not be run.') + '</p></div>');
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
            return tsEscape(m.id) + ' (' + m.from + '->' + m.to + ')';
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

    renderDocuments: function (data) {
        var stats = data.stats || {};
        this.$container.find('.ts-db-stats').text(
            Craft.t('typesense', '{n} documents', {n: stats.numDocuments || 0}) +
            ' - ' + Craft.t('typesense', 'Page {p}', {p: this.page})
        );

        if (!data.documents || !data.documents.length) {
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

    statsBar: function (data) {
        var parts = [Craft.t('typesense', '{n} results', {n: data.found || 0})];
        if (data.searchTimeMs !== null && data.searchTimeMs !== undefined) {
            parts.push(Craft.t('typesense', '{ms} ms', {ms: data.searchTimeMs}));
        }
        return '<div class="ts-pg-statsbar">' + parts.join(' - ') + '</div>';
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
