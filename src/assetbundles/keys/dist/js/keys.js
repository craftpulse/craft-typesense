/**
 * Typesense API key show-once modal.
 *
 * After a key is created, its full value is available exactly once. This opens a
 * Garnish modal that surfaces the value with a copy button and an explicit
 * "never shown again" warning. The value lives only in the server-rendered
 * markup for this one response; the plugin never stores it.
 *
 * Accessibility: Garnish.Modal manages focus and closes on Escape through the UI
 * layer manager. The copy control fires on the `activate` event (click plus
 * Space/Enter).
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

Craft.Typesense.KeyModal = Garnish.Base.extend(
    {
        $modal: null,
        modal: null,

        /**
         * @param {*} container the modal container element
         */
        init: function (container) {
            this.$modal = $(container);

            if (!this.$modal.length) {
                return;
            }

            this.modal = new Garnish.Modal(this.$modal, {
                hideOnEsc: true,
                hideOnShadeClick: true,
                closeOtherModals: true,
            });

            this.addListener(
                this.$modal.find('.ts-key-modal__copy'),
                'activate',
                'handleCopy'
            );

            this.addListener(
                this.$modal.find('.ts-key-modal__done'),
                'activate',
                'handleDone'
            );
        },

        /**
         * Copies the key value to the clipboard.
         *
         * @param {Object} ev the activate event
         */
        handleCopy: function (ev) {
            ev.preventDefault();

            var value = this.$modal.find('.ts-key-modal__value').val();
            var $btn = $(ev.currentTarget);

            var done = function () {
                $btn.text(Craft.t('typesense', 'Copied'));
            };

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(value).then(done, done);
                return;
            }

            this.$modal.find('.ts-key-modal__value').select();
            document.execCommand('copy');
            done();
        },

        /**
         * Closes the modal.
         */
        handleDone: function () {
            this.modal.hide();
        },
    }
);

Garnish.$doc.ready(function () {
    var el = document.getElementById('ts-key-modal');

    if (el) {
        new Craft.Typesense.KeyModal(el);
    }
});
