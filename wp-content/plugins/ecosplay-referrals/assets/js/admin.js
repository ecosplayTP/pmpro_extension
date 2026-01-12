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
         * Renders the Stripe diagnostic report list.
         *
         * @param {jQuery} $container Report wrapper.
         * @param {Array}  checks     List of check objects.
         */
        function renderStripeDiagnosticReport( $container, checks ) {
            $container.empty();

            if ( ! Array.isArray( checks ) || ! checks.length ) {
                $container.text( strings.stripeDiagnosticError || '' );
                return;
            }

            var $list = $( '<ul />', { class: 'ecos-referrals-stripe-test-list' } );

            checks.forEach( function ( check ) {
                var isOk = !! check.ok;
                var statusText = isOk ? 'OK' : 'KO';
                var labelText = check.label || '';
                var messageText = check.message ? ' – ' + check.message : '';
                var $item = $( '<li />', { class: isOk ? 'is-ok' : 'is-ko' } );

                $item
                    .append( $( '<strong />' ).text( statusText ) )
                    .append( document.createTextNode( ' ' + labelText + messageText ) );

                $list.append( $item );
            } );

            $container.append( $list );
        }

        /**
         * Toggles the Stripe diagnostic button state.
         *
         * @param {jQuery} $button  Trigger button.
         * @param {boolean} loading Whether the request is pending.
         */
        function toggleStripeDiagnosticButton( $button, loading ) {
            var defaultText = $button.data( 'default-text' );

            if ( ! defaultText ) {
                defaultText = $button.text();
                $button.data( 'default-text', defaultText );
            }

            if ( loading ) {
                $button.text( strings.stripeDiagnosticRunning || defaultText );
            } else {
                $button.text( defaultText );
            }

            $button.prop( 'disabled', loading );
        }

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

        /**
         * Launches the Stripe diagnostic request.
         */
        $( '.ecos-referrals-stripe-test-trigger' ).on( 'click', function () {
            var $button = $( this );
            var $report = $( '.ecos-referrals-stripe-test-report' );

            if ( ! strings.ajaxUrl || ! strings.stripeDiagnosticAction || ! strings.stripeDiagnosticNonce ) {
                $report.text( strings.stripeDiagnosticError || '' );
                return;
            }

            toggleStripeDiagnosticButton( $button, true );
            $report.text( strings.stripeDiagnosticRunning || '' );

            $.post( strings.ajaxUrl, {
                action: strings.stripeDiagnosticAction,
                nonce: strings.stripeDiagnosticNonce
            } )
                .done( function ( response ) {
                    if ( response && response.success && response.data ) {
                        renderStripeDiagnosticReport( $report, response.data.checks );
                        return;
                    }

                    var errorMessage = strings.stripeDiagnosticError || '';

                    if ( response && response.data && response.data.message ) {
                        errorMessage = response.data.message;
                    }

                    $report.text( errorMessage );
                } )
                .fail( function () {
                    $report.text( strings.stripeDiagnosticError || '' );
                } )
                .always( function () {
                    toggleStripeDiagnosticButton( $button, false );
                } );
        } );
    } );
})( jQuery );
