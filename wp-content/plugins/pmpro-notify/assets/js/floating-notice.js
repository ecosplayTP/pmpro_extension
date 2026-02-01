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

        var data = new FormData();
        data.append('action', settings.action);
        data.append('nonce', settings.nonce);
        data.append('campaign_id', settings.campaignId || 0);

        fetch(settings.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            body: data
        });
    }

    document.addEventListener('click', dismissNotice);
}());
