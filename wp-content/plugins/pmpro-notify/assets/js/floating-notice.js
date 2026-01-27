/**
 * Floating notice interactions for PMPro Notify.
 *
 * @file wp-content/plugins/pmpro-notify/assets/js/floating-notice.js
 */
(function () {
    'use strict';

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
