/**
 * Floating notice interactions for PMPro Notify.
 *
 * @file wp-content/plugins/pmpro-notify/assets/js/floating-notice.js
 */
(function () {
    'use strict';

    /**
     * Returns localized notice data for AJAX requests.
     *
     * @returns {Object|null} Localized data object.
     */
    function getNoticeData() {
        if (typeof window.pmproNotifyFloatingNotice !== 'object') {
            return null;
        }

        return window.pmproNotifyFloatingNotice;
    }

    /**
     * Handles dismiss clicks by hiding the notice and sending an AJAX request.
     *
     * @param {Event} event Click event.
     */
    function handleDismiss(event) {
        var dismissButton = event.target.closest('[data-pmpro-notify-dismiss]');

        if (!dismissButton) {
            return;
        }

        event.preventDefault();

        var notice = dismissButton.closest('.pmpro-notify-floating-notice');

        if (notice) {
            notice.classList.add('is-hidden');
        }

        var noticeData = getNoticeData();

        if (!noticeData || !noticeData.ajaxUrl) {
            return;
        }

        var payload = new URLSearchParams();

        payload.append('action', noticeData.action);
        payload.append('nonce', noticeData.nonce);
        payload.append('campaign_id', noticeData.campaignId);

        fetch(noticeData.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8'
            },
            body: payload.toString()
        });
    }

    document.addEventListener('click', handleDismiss);
}());
