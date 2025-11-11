/**
 * Logique interactive du portefeuille membre.
 *
 * @file    wp-content/plugins/ecosplay-referrals/assets/js/member-wallet.js
 */
(function () {
    if (!window.ecosReferralWallet) {
        return;
    }

    const config = window.ecosReferralWallet;
    const wallets = document.querySelectorAll('.ecos-referral-wallet');

    if (!wallets.length) {
        return;
    }

    const request = (action, payload = {}) => {
        const body = new URLSearchParams();
        body.append('action', action);
        body.append('_ajax_nonce', config.nonce);

        Object.keys(payload).forEach((key) => {
            if (payload[key] !== undefined && payload[key] !== null) {
                body.append(key, payload[key]);
            }
        });

        return fetch(config.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            },
            body: body.toString(),
        }).then((response) => response.json());
    };

    const renderNotice = (container, type, message) => {
        const notice = container.querySelector('.ecos-referral-wallet__notice');

        if (!notice) {
            return;
        }

        notice.textContent = message;
        notice.classList.remove('ecos-referral-wallet__notice--error', 'ecos-referral-wallet__notice--success');

        if (!message) {
            notice.style.display = 'none';
            return;
        }

        notice.classList.add(
            type === 'success' ? 'ecos-referral-wallet__notice--success' : 'ecos-referral-wallet__notice--error'
        );
        notice.style.display = 'block';
    };

    const updateMetrics = (container, wallet) => {
        const mapping = {
            earned_credits: 'earned_credits_formatted',
            total_paid: 'total_paid_formatted',
            available_balance: 'available_balance_formatted',
            kyc_label: 'kyc_label',
        };

        Object.keys(mapping).forEach((key) => {
            const target = container.querySelector('[data-wallet-field="' + key + '"]');

            if (target && wallet[mapping[key]] !== undefined) {
                target.textContent = wallet[mapping[key]];
            }
        });

        const hint = container.querySelector('[data-wallet-field="available_hint"]');

        if (hint && wallet.available_balance_formatted) {
            hint.textContent = config.i18n.availableHint.replace('%s', wallet.available_balance_formatted);
        }

        container.dataset.walletCanTransfer = wallet.can_transfer ? '1' : '0';
        const transferSection = container.querySelector('[data-wallet-section="transfer"]');

        if (transferSection) {
            transferSection.style.display = wallet.can_transfer ? '' : 'none';
        }

        const dashboardButton = container.querySelector('[data-wallet-action="dashboard"]');

        if (dashboardButton) {
            dashboardButton.style.display = wallet.can_transfer ? '' : 'none';
        }
    };

    const updateLedger = (container, wallet) => {
        const tableBody = container.querySelector('[data-wallet-ledger] tbody');

        if (!tableBody) {
            return;
        }

        tableBody.innerHTML = '';

        if (!wallet.payouts || !wallet.payouts.length) {
            const row = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = 4;
            cell.textContent = config.i18n.emptyLedger;
            row.appendChild(cell);
            tableBody.appendChild(row);
            return;
        }

        wallet.payouts.forEach((entry) => {
            const row = document.createElement('tr');

            const dateCell = document.createElement('td');
            dateCell.textContent = entry.created_at_formatted;
            row.appendChild(dateCell);

            const amountCell = document.createElement('td');
            amountCell.textContent = entry.amount_formatted;
            row.appendChild(amountCell);

            const statusCell = document.createElement('td');
            statusCell.textContent = entry.status_label;
            statusCell.classList.add('ecos-referral-wallet__ledger-status--' + entry.status_state);
            row.appendChild(statusCell);

            const infoCell = document.createElement('td');
            infoCell.textContent = entry.failure_message || '';
            row.appendChild(infoCell);

            tableBody.appendChild(row);
        });
    };

    wallets.forEach((wallet) => {
        const onboardingBtn = wallet.querySelector('[data-wallet-action="onboard"]');
        const dashboardBtn = wallet.querySelector('[data-wallet-action="dashboard"]');
        const transferForm = wallet.querySelector('[data-wallet-form="transfer"]');

        if (onboardingBtn) {
            onboardingBtn.addEventListener('click', (event) => {
                event.preventDefault();
                onboardingBtn.disabled = true;
                renderNotice(wallet, 'success', '');

                request(config.actions.onboard, { redirect: window.location.href })
                    .then((response) => {
                        if (response.success && response.data && response.data.redirect) {
                            window.location.href = response.data.redirect;
                            return;
                        }

                        const message = response.data && response.data.message ? response.data.message : config.i18n.genericError;
                        renderNotice(wallet, 'error', message);
                        onboardingBtn.disabled = false;
                    })
                    .catch(() => {
                        renderNotice(wallet, 'error', config.i18n.genericError);
                        onboardingBtn.disabled = false;
                    });
            });
        }

        if (dashboardBtn) {
            dashboardBtn.addEventListener('click', (event) => {
                event.preventDefault();
                dashboardBtn.disabled = true;
                renderNotice(wallet, 'success', '');

                request(config.actions.dashboard)
                    .then((response) => {
                        if (response.success && response.data && response.data.redirect) {
                            window.location.href = response.data.redirect;
                            return;
                        }

                        const message = response.data && response.data.message ? response.data.message : config.i18n.genericError;
                        renderNotice(wallet, 'error', message);
                        dashboardBtn.disabled = false;
                    })
                    .catch(() => {
                        renderNotice(wallet, 'error', config.i18n.genericError);
                        dashboardBtn.disabled = false;
                    });
            });
        }

        if (transferForm) {
            transferForm.addEventListener('submit', (event) => {
                event.preventDefault();
                const submitButton = transferForm.querySelector('[type="submit"]');
                const amountInput = transferForm.querySelector('[name="amount"]');

                if (!amountInput || !submitButton) {
                    return;
                }

                const amountValue = amountInput.value;

                submitButton.disabled = true;
                renderNotice(wallet, 'success', '');

                request(config.actions.transfer, { amount: amountValue })
                    .then((response) => {
                        submitButton.disabled = false;

                        if (!response) {
                            renderNotice(wallet, 'error', config.i18n.genericError);
                            return;
                        }

                        const message = response.data && response.data.message ? response.data.message : '';

                        if (!response.success) {
                            renderNotice(wallet, 'error', message || config.i18n.genericError);
                            return;
                        }

                        renderNotice(wallet, 'success', message || config.i18n.transferRequested);
                        amountInput.value = '';

                        if (response.data && response.data.wallet) {
                            updateMetrics(wallet, response.data.wallet);
                            updateLedger(wallet, response.data.wallet);
                        }
                    })
                    .catch(() => {
                        submitButton.disabled = false;
                        renderNotice(wallet, 'error', config.i18n.genericError);
                    });
            });
        }
    });
})();
