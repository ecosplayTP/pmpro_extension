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

    /**
     * Envoie une requête AJAX vers l'endpoint WordPress.
     */
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

    /**
     * Affiche un message de notification dans le portefeuille.
     */
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

    /**
     * Met à jour les informations de portefeuille dans l'interface.
     */
    const updateMetrics = (container, wallet) => {
        const mapping = {
            earned_credits: 'earned_credits_formatted',
            total_paid: 'total_paid_formatted',
            available_balance: 'available_balance_formatted',
            association_label: 'association_label',
            tremendous_balance_label: 'tremendous_balance_label',
        };

        Object.keys(mapping).forEach((key) => {
            const target = container.querySelector('[data-wallet-field="' + key + '"]');

            if (target && wallet[mapping[key]] !== undefined) {
                target.textContent = wallet[mapping[key]];

                if (key === 'tremendous_balance_label') {
                    target.style.display = wallet[mapping[key]] ? '' : 'none';
                }
            }
        });

        const hint = container.querySelector('[data-wallet-field="available_hint"]');

        if (hint && wallet.available_balance_formatted) {
            hint.textContent = config.i18n.availableHint.replace('%s', wallet.available_balance_formatted);
        }

        container.dataset.walletCanRequest = wallet.can_request_reward ? '1' : '0';
        container.dataset.walletAssociationStatus = wallet.association_status || '';
        container.dataset.walletTremendousBalance = wallet.tremendous_balance_formatted || '';
        const rewardSection = container.querySelector('[data-wallet-section="reward"]');

        if (rewardSection) {
            rewardSection.style.display = wallet.can_request_reward ? '' : 'none';
        }

        const associateButton = container.querySelector('[data-wallet-action="associate"]');

        if (associateButton) {
            associateButton.style.display = wallet.is_associated ? 'none' : '';
            associateButton.disabled = false;
        }

        const refreshButton = container.querySelector('[data-wallet-action="refresh"]');

        if (refreshButton) {
            refreshButton.style.display = wallet.is_associated ? '' : 'none';
            refreshButton.disabled = false;
        }

        updateAssociationErrors(container, wallet);
    };

    /**
     * Rafraîchit la liste des erreurs d'association Tremendous.
     */
    const updateAssociationErrors = (container, wallet) => {
        const list = container.querySelector('[data-wallet-field="association_errors"]');

        if (!list) {
            return;
        }

        list.innerHTML = '';

        if (!wallet.association_errors || !wallet.association_errors.length) {
            list.style.display = 'none';
            return;
        }

        wallet.association_errors.forEach((message) => {
            const item = document.createElement('li');
            item.textContent = message;
            list.appendChild(item);
        });

        list.style.display = 'block';
    };

    /**
     * Ré-affiche l'historique des virements.
     */
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
        const associationSection = wallet.querySelector('.ecos-referral-wallet__association');
        const associateBtn = wallet.querySelector('[data-wallet-action="associate"]');
        const refreshBtn = wallet.querySelector('[data-wallet-action="refresh"]');
        const rewardForm = wallet.querySelector('[data-wallet-form="reward"]');

        if (associationSection && associateBtn) {
            associateBtn.addEventListener('click', (event) => {
                event.preventDefault();
                associateBtn.disabled = true;
                renderNotice(wallet, 'success', '');

                request(config.actions.associate)
                    .then((response) => {
                        associateBtn.disabled = false;

                        const message = response && response.data && response.data.message ? response.data.message : config.i18n.genericError;

                        if (!response || !response.success) {
                            renderNotice(wallet, 'error', message);
                            return;
                        }

                        renderNotice(wallet, 'success', message || config.i18n.associationLinked);

                        if (response.data && response.data.wallet) {
                            updateMetrics(wallet, response.data.wallet);
                            updateLedger(wallet, response.data.wallet);
                        }
                    })
                    .catch(() => {
                        associateBtn.disabled = false;
                        renderNotice(wallet, 'error', config.i18n.genericError);
                    });
            });
        }

        if (associationSection && refreshBtn) {
            refreshBtn.addEventListener('click', (event) => {
                event.preventDefault();
                refreshBtn.disabled = true;
                renderNotice(wallet, 'success', '');

                request(config.actions.refresh)
                    .then((response) => {
                        refreshBtn.disabled = false;

                        if (!response) {
                            renderNotice(wallet, 'error', config.i18n.genericError);
                            return;
                        }

                        const message = response.data && response.data.message ? response.data.message : '';

                        if (!response.success) {
                            renderNotice(wallet, 'error', message || config.i18n.genericError);
                            return;
                        }

                        renderNotice(wallet, 'success', message || config.i18n.balanceRefreshed);

                        if (response.data && response.data.wallet) {
                            updateMetrics(wallet, response.data.wallet);
                            updateLedger(wallet, response.data.wallet);
                        }
                    })
                    .catch(() => {
                        refreshBtn.disabled = false;
                        renderNotice(wallet, 'error', config.i18n.genericError);
                    });
            });
        }

        if (rewardForm) {
            rewardForm.addEventListener('submit', (event) => {
                event.preventDefault();
                const submitButton = rewardForm.querySelector('[type="submit"]');
                const amountInput = rewardForm.querySelector('[name="amount"]');

                if (!amountInput || !submitButton) {
                    return;
                }

                const amountValue = amountInput.value;

                submitButton.disabled = true;
                renderNotice(wallet, 'success', '');

                request(config.actions.reward, { amount: amountValue })
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

                        renderNotice(wallet, 'success', message || config.i18n.rewardRequested);
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
