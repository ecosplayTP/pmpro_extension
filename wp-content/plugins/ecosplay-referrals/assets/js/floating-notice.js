/**
 * Floating notice front-end interactions.
 *
 * @file assets/js/floating-notice.js
 */

(function () {
    'use strict';

    if ( 'undefined' === typeof window.ecosplayFloatingNotice ) {
        return;
    }

    var settings = window.ecosplayFloatingNotice;
    var canSend = 'function' === typeof window.fetch;

    function closeNotice(notice) {
        if (!notice || !notice.parentNode) {
            return;
        }

        notice.parentNode.removeChild(notice);
    }

    function sendDismissal() {
        var payload = 'action=' + encodeURIComponent(settings.action) +
            '&nonce=' + encodeURIComponent(settings.nonce);

        if (!canSend) {
            return;
        }

        window.fetch(settings.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: payload
        });
    }

    function rememberDismissal() {
        var version = parseInt(settings.version, 10);
        if (!version || version < 1) {
            version = 1;
        }

        var expires = new Date(Date.now() + 365 * 24 * 60 * 60 * 1000);
        var cookie = settings.cookieName + '=' + version + '; path=/; expires=' + expires.toUTCString() + '; SameSite=Lax';

        if (window.location && window.location.protocol === 'https:') {
            cookie += '; Secure';
        }

        document.cookie = cookie;
    }

    document.addEventListener('DOMContentLoaded', function () {
        var notice = document.querySelector('.ecosplay-floating-notice');

        if (!notice) {
            return;
        }

        var trigger = notice.querySelector('[data-ecosplay-close]');

        if (!trigger) {
            return;
        }

        trigger.addEventListener('click', function (event) {
            event.preventDefault();
            rememberDismissal();
            sendDismissal();
            closeNotice(notice);
        });
    });
})();
