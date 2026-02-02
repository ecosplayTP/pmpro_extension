/**
 * Floating notice interactions for PMPro Notify.
 *
 * @file wp-content/plugins/pmpro-notify/assets/js/floating-notice.js
 */
(function () {
    'use strict';

    /**
     * Sets a dismissal cookie for the floating notice.
     *
     * @param {Object} settings Cookie settings for the notice.
     */
    function setNoticeCookie(settings) {
        if (!settings || !settings.cookieName) {
            return;
        }

        var ttl = parseInt(settings.cookieTtl, 10) || 0;
        var expires = '';

        if (ttl > 0) {
            var date = new Date();
            date.setTime(date.getTime() + ttl * 1000);
            expires = '; expires=' + date.toUTCString();
        }

        var cookieValue = String(settings.campaignId || 0);
        var cookie = settings.cookieName + '=' + encodeURIComponent(cookieValue);
        cookie += expires;
        cookie += '; path=' + (settings.cookiePath || '/');

        if (settings.cookieSecure) {
            cookie += '; secure';
        }

        document.cookie = cookie;
    }

    /**
     * Dismisses the floating notice and persists the user action.
     *
     * @param {Event} event Click event from the document.
     */
    function dismissNotice(event) {
        var button = event.target.closest('[data-pmpro-notify-dismiss]');
        if (!button) {
            return;
        }

        var notice = button.closest('.pmpro-notify-floating-notice');
        if (notice) {
            notice.classList.add('is-hidden');
        }

        if (typeof window.pmproNotifyFloatingNotice === 'undefined') {
            return;
        }

        var settings = window.pmproNotifyFloatingNotice;
        setNoticeCookie(settings);
        sendDismissRequest(settings, false);
    }

    /**
     * Sends the dismiss request and retries on nonce failure.
     *
     * @param {Object} settings Notice settings from localization.
     * @param {boolean} hasRetried Whether a retry already occurred.
     */
    function sendDismissRequest(settings, hasRetried) {
        var data = new FormData();
        data.append('action', settings.action);
        data.append('nonce', settings.nonce);
        data.append('campaign_id', settings.campaignId || 0);

        fetch(settings.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: data
        })
            .then(function (response) {
                return response.json().catch(function () {
                    return null;
                });
            })
            .then(function (payload) {
                if (!payload) {
                    return;
                }

                if (payload.success) {
                    setNoticeCookie(settings);
                    return;
                }

                if (
                    !hasRetried &&
                    payload.data &&
                    payload.data.code === 'invalid_nonce'
                ) {
                    refreshNonce(settings).then(function (nonce) {
                        if (!nonce) {
                            return;
                        }
                        settings.nonce = nonce;
                        sendDismissRequest(settings, true);
                    });
                }
            });
    }

    /**
     * Requests a fresh nonce from the server.
     *
     * @param {Object} settings Notice settings from localization.
     * @return {Promise} Promise resolving with the new nonce or null.
     */
    function refreshNonce(settings) {
        if (!settings.refreshNonce) {
            return Promise.resolve(null);
        }

        var data = new FormData();
        data.append('action', settings.refreshNonce);

        return fetch(settings.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: data
        })
            .then(function (response) {
                return response.json().catch(function () {
                    return null;
                });
            })
            .then(function (payload) {
                if (
                    payload &&
                    payload.success &&
                    payload.data &&
                    payload.data.nonce
                ) {
                    return payload.data.nonce;
                }

                return null;
            });
    }

    document.addEventListener('click', dismissNotice);
}());
