/**
 * Typesense field mapping designer.
 *
 * A Garnish component in the FieldLayoutDesigner visual language: one read-only
 * card per mappable field, each with a disclosure HUD that edits the field's
 * Typesense mapping. It never instantiates Craft.FieldLayoutDesigner (which
 * authors and persists FieldLayouts); it only borrows the .fld-* card look and
 * the settings-HUD interaction.
 *
 * Accessibility: the card trigger fires on the `activate` event (click plus
 * Space/Enter), the HUD manages focus (first control on open, trigger restored
 * on close) and closes on Escape through the UI layer manager, and destroy()
 * tears the open HUD down.
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

Craft.Typesense.MappingDesigner = Garnish.Base.extend(
    {
        $container: null,
        hud: null,

        /**
         * @param {*} container the mapping container element
         */
        init: function (container) {
            this.$container = $(container);

            this.addListener(
                this.$container.find('.ts-map-card__trigger'),
                'activate',
                'handleTrigger'
            );
        },

        /**
         * Opens the mapping HUD for the activated card's field.
         *
         * @param {Object} ev the activate event
         */
        handleTrigger: function (ev) {
            var $trigger = $(ev.currentTarget);
            var $card = $trigger.closest('.ts-map-card');
            var self = this;

            // Destroy any previously-opened HUD before reassigning, so opening
            // card after card does not leak Garnish.HUD instances and listeners.
            if (this.hud) {
                this.hud.destroy();
                this.hud = null;
            }

            // Seed the HUD from the card's server-rendered control template.
            var bodyHtml =
                $card.find('.ts-map-card__controls').html() +
                '<div class="hud-footer buttons right">' +
                '<button type="submit" class="btn submit">' +
                Craft.t('typesense', 'Apply') +
                '</button></div>';

            this.hud = new Garnish.HUD($trigger, bodyHtml, {
                hudClass: 'hud ts-map-hud',
                onShow: function () {
                    self.hud.$body.find('input, select, textarea').first().trigger('focus');
                },
                onSubmit: function () {
                    self.applyMapping($card);
                },
            });
        },

        /**
         * Reads the HUD's controls back into the card's hidden mapping input and
         * refreshes the card summary, then closes the HUD.
         *
         * @param {*} $card the card element
         */
        applyMapping: function ($card) {
            var mapping = {};
            var summary = [];

            this.hud.$body.find('[data-map-key]').each(function () {
                var $control = $(this);
                var key = $control.attr('data-map-key');
                var value;

                if ($control.attr('type') === 'checkbox') {
                    value = $control.prop('checked');
                    if (value) {
                        summary.push($control.attr('data-map-label') || key);
                    }
                } else {
                    value = $control.val();
                }

                mapping[key] = value;
            });

            $card.find('.ts-map-card__value').val(JSON.stringify(mapping));
            $card
                .find('.ts-map-card__summary')
                .text(
                    summary.length
                        ? summary.join(', ')
                        : Craft.t('typesense', 'Not indexed')
                );

            this.hud.hide();
        },

        /**
         * @inheritDoc
         */
        destroy: function () {
            if (this.hud) {
                this.hud.destroy();
                this.hud = null;
            }
            this.base();
        },
    }
);
