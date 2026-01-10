/**
 * Copy helper for referral codes.
 *
 * @file wp-content/plugins/ecosplay-referrals/assets/js/referral-code.js
 */
( function () {
    const containers = document.querySelectorAll( '[data-ecos-referral-code]' );

    if ( ! containers.length ) {
        return;
    }

    const setStatus = ( button, status, message ) => {
        if ( status ) {
            status.textContent = message;
        }

        if ( button ) {
            button.textContent = message;
        }
    };

    const restoreLabel = ( button, status, label ) => {
        if ( status ) {
            status.textContent = '';
        }

        if ( button ) {
            button.textContent = label;
        }
    };

    const copyText = ( value ) => {
        if ( navigator.clipboard && navigator.clipboard.writeText ) {
            return navigator.clipboard.writeText( value );
        }

        return new Promise( ( resolve, reject ) => {
            const helper = document.createElement( 'textarea' );
            helper.value = value;
            helper.setAttribute( 'readonly', '' );
            helper.style.position = 'absolute';
            helper.style.left = '-9999px';
            document.body.appendChild( helper );
            helper.select();

            const success = document.execCommand( 'copy' );
            document.body.removeChild( helper );

            if ( success ) {
                resolve();
                return;
            }

            reject( new Error( 'copy_failed' ) );
        } );
    };

    containers.forEach( ( container ) => {
        const button = container.querySelector( '[data-ecos-referral-copy]' );
        const value = container.querySelector( '[data-ecos-referral-value]' );
        const status = container.querySelector( '[data-ecos-referral-status]' );

        if ( ! button || ! value ) {
            return;
        }

        const defaultLabel = button.dataset.ecosReferralLabel || button.textContent;
        const copiedLabel = button.dataset.ecosReferralCopiedLabel || defaultLabel;

        button.addEventListener( 'click', () => {
            const code = ( value.textContent || '' ).trim();

            if ( ! code ) {
                return;
            }

            copyText( code )
                .then( () => {
                    setStatus( button, status, copiedLabel );
                    window.setTimeout( () => {
                        restoreLabel( button, status, defaultLabel );
                    }, 2000 );
                } )
                .catch( () => {
                    restoreLabel( button, status, defaultLabel );
                } );
        } );
    } );
} )();
