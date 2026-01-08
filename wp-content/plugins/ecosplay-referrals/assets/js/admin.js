/*
 * Admin helpers for ECOSplay referrals management.
 *
 * @file wp-content/plugins/ecosplay-referrals/assets/js/admin.js
 */
(function ($) {
    'use strict';

    /**
     * Confirms destructive submissions where appropriate.
     */
    $( function () {
        var strings = window.ecosReferralsAdmin || {};

        /**
         * Reveals and enables the Stripe secret editor on demand.
         */
        $( '.ecos-referrals-stripe-secret-toggle' ).on( 'click', function () {
            var $cell = $( this ).closest( 'td' );
            var $container = $cell.find( '.ecos-referrals-stripe-secret' );
            var $input = $container.find( '.ecos-referrals-stripe-secret-input' );
            var $editField = $cell.find( '.ecos-referrals-stripe-secret-edit' );

            $container.removeClass( 'is-hidden' );
            $input.prop( 'disabled', false ).val( '' );
            $editField.val( '1' );
        } );

        $( '.ecos-referrals-actions form' ).on( 'submit', function ( event ) {
            var action = $( 'input[name="ecosplay_referrals_action"]', this ).val();

            if ( 'regenerate_all' === action && strings.confirmRegenerateAll ) {
                if ( ! window.confirm( strings.confirmRegenerateAll ) ) {
                    event.preventDefault();
                }
            }

            if ( 'reset_notifications' === action && strings.confirmResetNotifications ) {
                if ( ! window.confirm( strings.confirmResetNotifications ) ) {
                    event.preventDefault();
                }
            }
        } );
    } );
})( jQuery );
