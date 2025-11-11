<?php
/**
 * Interface membre pour le portefeuille de parrainage.
 *
 * @package Ecosplay\Referrals
 * @file    wp-content/plugins/ecosplay-referrals/public/class-member-wallet.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Gère le shortcode et les interactions AJAX du portefeuille membre.
 */
class Ecosplay_Referrals_Member_Wallet {
    /**
     * Service métier des parrainages.
     *
     * @var Ecosplay_Referrals_Service
     */
    protected $service;

    /**
     * Indique si les assets doivent être chargés.
     *
     * @var bool
     */
    protected $should_enqueue = false;

    /**
     * Initialise les hooks nécessaires au portefeuille.
     *
     * @param Ecosplay_Referrals_Service $service Service de parrainage.
     */
    public function __construct( Ecosplay_Referrals_Service $service ) {
        $this->service = $service;

        add_shortcode( 'ecos_referral_wallet', array( $this, 'render_wallet' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp', array( $this, 'maybe_flag_shortcode' ) );

        add_action( 'wp_ajax_ecos_referrals_wallet_onboarding', array( $this, 'handle_onboarding_request' ) );
        add_action( 'wp_ajax_ecos_referrals_wallet_dashboard', array( $this, 'handle_dashboard_request' ) );
        add_action( 'wp_ajax_ecos_referrals_request_transfer', array( $this, 'handle_transfer_request' ) );

        add_action( 'wp_ajax_nopriv_ecos_referrals_wallet_onboarding', array( $this, 'reject_unauthenticated_request' ) );
        add_action( 'wp_ajax_nopriv_ecos_referrals_wallet_dashboard', array( $this, 'reject_unauthenticated_request' ) );
        add_action( 'wp_ajax_nopriv_ecos_referrals_request_transfer', array( $this, 'reject_unauthenticated_request' ) );
    }

    /**
     * Affiche le portefeuille pour le membre connecté.
     *
     * @param array<string,mixed> $atts Attributs de shortcode.
     *
     * @return string
     */
    public function render_wallet( $atts = array() ) {
        $user_id = $this->resolve_authorized_user();

        if ( ! $user_id ) {
            return '<p class="ecos-referral-wallet__login-required">' . esc_html__( 'Connectez-vous pour accéder à votre portefeuille de parrainage.', 'ecosplay-referrals' ) . '</p>';
        }

        $wallet = $this->service->get_member_wallet( $user_id );

        if ( is_wp_error( $wallet ) ) {
            return sprintf(
                '<div class="ecos-referral-wallet__error">%s</div>',
                esc_html( $wallet->get_error_message() )
            );
        }

        $this->should_enqueue = true;

        $payload = $this->prepare_wallet_payload( $wallet );

        ob_start();
        ?>
        <div class="ecos-referral-wallet" data-wallet-can-transfer="<?php echo esc_attr( $payload['can_transfer'] ? '1' : '0' ); ?>">
            <div class="ecos-referral-wallet__notice" role="alert"></div>

            <div class="ecos-referral-wallet__summary">
                <div class="ecos-referral-wallet__metric">
                    <span class="ecos-referral-wallet__label"><?php esc_html_e( 'Crédits cumulés', 'ecosplay-referrals' ); ?></span>
                    <span class="ecos-referral-wallet__value" data-wallet-field="earned_credits"><?php echo esc_html( $payload['earned_credits_formatted'] ); ?></span>
                </div>
                <div class="ecos-referral-wallet__metric">
                    <span class="ecos-referral-wallet__label"><?php esc_html_e( 'Montants versés', 'ecosplay-referrals' ); ?></span>
                    <span class="ecos-referral-wallet__value" data-wallet-field="total_paid"><?php echo esc_html( $payload['total_paid_formatted'] ); ?></span>
                </div>
                <div class="ecos-referral-wallet__metric">
                    <span class="ecos-referral-wallet__label"><?php esc_html_e( 'Solde disponible', 'ecosplay-referrals' ); ?></span>
                    <span class="ecos-referral-wallet__value" data-wallet-field="available_balance"><?php echo esc_html( $payload['available_balance_formatted'] ); ?></span>
                </div>
            </div>

            <div class="ecos-referral-wallet__kyc">
                <h4><?php esc_html_e( 'Statut KYC', 'ecosplay-referrals' ); ?></h4>
                <p data-wallet-field="kyc_label"><?php echo esc_html( $payload['kyc_label'] ); ?></p>
                <?php if ( ! empty( $payload['kyc_errors'] ) ) : ?>
                    <ul class="ecos-referral-wallet__kyc-errors">
                        <?php foreach ( $payload['kyc_errors'] as $error ) : ?>
                            <li><?php echo esc_html( $error ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>

            <div class="ecos-referral-wallet__actions">
                <button class="button" data-wallet-action="onboard" type="button"><?php esc_html_e( 'Compléter mon dossier', 'ecosplay-referrals' ); ?></button>
                <button class="button" data-wallet-action="dashboard" type="button" style="<?php echo $payload['can_transfer'] ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Ouvrir mon tableau de bord Stripe', 'ecosplay-referrals' ); ?></button>
            </div>

            <div data-wallet-section="transfer" style="<?php echo $payload['can_transfer'] ? '' : 'display:none;'; ?>">
                <form class="ecos-referral-wallet__transfer" data-wallet-form="transfer">
                    <label for="ecos-referral-wallet-amount"><?php esc_html_e( 'Montant du virement', 'ecosplay-referrals' ); ?></label>
                    <input id="ecos-referral-wallet-amount" type="number" step="0.01" min="1" name="amount" required />
                    <p class="ecos-referral-wallet__hint" data-wallet-field="available_hint"><?php echo esc_html( $payload['available_hint'] ); ?></p>
                    <button type="submit" class="button" data-wallet-action="transfer"><?php esc_html_e( 'Demander un virement', 'ecosplay-referrals' ); ?></button>
                </form>
            </div>

            <div class="ecos-referral-wallet__ledger-wrapper">
                <h4><?php esc_html_e( 'Historique des virements', 'ecosplay-referrals' ); ?></h4>
                <table class="ecos-referral-wallet__ledger" data-wallet-ledger>
                    <thead>
                        <tr>
                            <th><?php esc_html_e( 'Date', 'ecosplay-referrals' ); ?></th>
                            <th><?php esc_html_e( 'Montant', 'ecosplay-referrals' ); ?></th>
                            <th><?php esc_html_e( 'Statut', 'ecosplay-referrals' ); ?></th>
                            <th><?php esc_html_e( 'Informations', 'ecosplay-referrals' ); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ( empty( $payload['payouts'] ) ) : ?>
                            <tr>
                                <td colspan="4"><?php esc_html_e( 'Aucun virement enregistré pour le moment.', 'ecosplay-referrals' ); ?></td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ( $payload['payouts'] as $entry ) : ?>
                                <tr>
                                    <td><?php echo esc_html( $entry['created_at_formatted'] ); ?></td>
                                    <td><?php echo esc_html( $entry['amount_formatted'] ); ?></td>
                                    <td class="ecos-referral-wallet__ledger-status--<?php echo esc_attr( $entry['status_state'] ); ?>"><?php echo esc_html( $entry['status_label'] ); ?></td>
                                    <td><?php echo esc_html( $entry['failure_message'] ); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Charge les assets uniquement si le shortcode a été utilisé.
     *
     * @return void
     */
    public function enqueue_assets() {
        if ( ! $this->should_enqueue ) {
            return;
        }

        wp_enqueue_style(
            'ecosplay-referrals-member-wallet',
            ECOSPLAY_REFERRALS_URL . 'assets/css/member-wallet.css',
            array(),
            ECOSPLAY_REFERRALS_VERSION
        );

        wp_enqueue_script(
            'ecosplay-referrals-member-wallet',
            ECOSPLAY_REFERRALS_URL . 'assets/js/member-wallet.js',
            array(),
            ECOSPLAY_REFERRALS_VERSION,
            true
        );

        wp_localize_script(
            'ecosplay-referrals-member-wallet',
            'ecosReferralWallet',
            array(
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce'   => wp_create_nonce( 'ecos_referral_wallet' ),
                'actions' => array(
                    'onboard'  => 'ecos_referrals_wallet_onboarding',
                    'dashboard'=> 'ecos_referrals_wallet_dashboard',
                    'transfer' => 'ecos_referrals_request_transfer',
                ),
                'i18n'    => array(
                    'genericError'      => __( 'Une erreur est survenue. Veuillez réessayer.', 'ecosplay-referrals' ),
                    'transferRequested' => __( 'Votre demande de virement a été enregistrée.', 'ecosplay-referrals' ),
                    'emptyLedger'       => __( 'Aucun virement enregistré pour le moment.', 'ecosplay-referrals' ),
                    'availableHint'     => __( 'Solde disponible : %s', 'ecosplay-referrals' ),
                ),
            )
        );
    }

    /**
     * Détecte la présence du shortcode sur la page courante.
     *
     * @return void
     */
    public function maybe_flag_shortcode() {
        if ( $this->should_enqueue ) {
            return;
        }

        if ( ! is_singular() ) {
            return;
        }

        global $post;

        if ( ! $post || empty( $post->post_content ) ) {
            return;
        }

        if ( has_shortcode( $post->post_content, 'ecos_referral_wallet' ) ) {
            $this->should_enqueue = true;
        }
    }

    /**
     * Traite la demande de génération d\'un lien d\'onboarding.
     *
     * @return void
     */
    public function handle_onboarding_request() {
        $user_id = $this->resolve_authorized_user( true );

        if ( ! $user_id ) {
            return;
        }

        check_ajax_referer( 'ecos_referral_wallet' );

        $redirect = isset( $_POST['redirect'] ) ? esc_url_raw( wp_unslash( $_POST['redirect'] ) ) : home_url();
        $link     = $this->service->generate_account_link( $user_id, $redirect, $redirect );

        if ( is_wp_error( $link ) ) {
            wp_send_json_error(
                array(
                    'message' => $link->get_error_message(),
                ),
                400
            );
        }

        $url = isset( $link['url'] ) ? esc_url_raw( $link['url'] ) : '';

        if ( '' === $url ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Le lien de redirection Stripe est introuvable.', 'ecosplay-referrals' ),
                ),
                400
            );
        }

        wp_send_json_success(
            array(
                'redirect' => $url,
            )
        );
    }

    /**
     * Traite la demande d\'accès au tableau de bord Stripe.
     *
     * @return void
     */
    public function handle_dashboard_request() {
        $user_id = $this->resolve_authorized_user( true );

        if ( ! $user_id ) {
            return;
        }

        check_ajax_referer( 'ecos_referral_wallet' );

        $link = $this->service->generate_login_link( $user_id );

        if ( is_wp_error( $link ) ) {
            wp_send_json_error(
                array(
                    'message' => $link->get_error_message(),
                ),
                400
            );
        }

        $url = isset( $link['url'] ) ? esc_url_raw( $link['url'] ) : '';

        if ( '' === $url ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Impossible de générer le lien de connexion Stripe.', 'ecosplay-referrals' ),
                ),
                400
            );
        }

        wp_send_json_success(
            array(
                'redirect' => $url,
            )
        );
    }

    /**
     * Traite la demande de virement depuis l\'interface membre.
     *
     * @return void
     */
    public function handle_transfer_request() {
        $user_id = $this->resolve_authorized_user( true );

        if ( ! $user_id ) {
            return;
        }

        check_ajax_referer( 'ecos_referral_wallet' );

        $raw_amount = isset( $_POST['amount'] ) ? wp_unslash( $_POST['amount'] ) : '';
        $amount     = $this->sanitize_amount( $raw_amount );

        if ( $amount <= 0 ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Veuillez indiquer un montant de virement valide.', 'ecosplay-referrals' ),
                ),
                400
            );
        }

        $wallet = $this->service->get_member_wallet( $user_id );

        if ( is_wp_error( $wallet ) ) {
            wp_send_json_error(
                array(
                    'message' => $wallet->get_error_message(),
                ),
                400
            );
        }

        if ( empty( $wallet['can_transfer'] ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Votre compte Stripe doit être validé avant de demander un virement.', 'ecosplay-referrals' ),
                ),
                400
            );
        }

        if ( $amount > (float) $wallet['available_balance'] ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Le montant demandé dépasse votre solde disponible.', 'ecosplay-referrals' ),
                ),
                400
            );
        }

        $result = $this->service->handle_withdraw_request( $user_id, $amount );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error(
                array(
                    'message' => $result->get_error_message(),
                ),
                400
            );
        }

        $updated_wallet = $this->service->get_member_wallet( $user_id );
        $payload        = is_wp_error( $updated_wallet ) ? array() : $this->prepare_wallet_payload( $updated_wallet );

        wp_send_json_success(
            array(
                'message' => __( 'Votre demande de virement a été enregistrée.', 'ecosplay-referrals' ),
                'wallet'  => $payload,
            )
        );
    }

    /**
     * Retourne une erreur JSON pour les requêtes non authentifiées.
     *
     * @return void
     */
    public function reject_unauthenticated_request() {
        wp_send_json_error(
            array(
                'message' => __( 'Veuillez vous connecter pour poursuivre.', 'ecosplay-referrals' ),
            ),
            401
        );
    }

    /**
     * Normalise le montant transmis par l\'utilisateur.
     *
     * @param string $value Valeur brute.
     *
     * @return float
     */
    protected function sanitize_amount( $value ) {
        $value = str_replace( array( ' ', ',' ), array( '', '.' ), (string) $value );

        return round( (float) $value, 2 );
    }

    /**
     * Formate le snapshot du portefeuille pour l\'affichage et l\'AJAX.
     *
     * @param array<string,mixed> $wallet Données brutes renvoyées par le service.
     *
     * @return array<string,mixed>
     */
    protected function prepare_wallet_payload( array $wallet ) {
        $currency = isset( $wallet['currency'] ) ? strtoupper( (string) $wallet['currency'] ) : 'EUR';
        $format   = function( $amount ) use ( $currency ) {
            return sprintf( '%s %s', number_format_i18n( (float) $amount, 2 ), $currency );
        };

        $payload = array(
            'currency'                    => $currency,
            'earned_credits'              => (float) $wallet['earned_credits'],
            'earned_credits_formatted'    => $format( $wallet['earned_credits'] ),
            'total_paid'                  => (float) $wallet['total_paid'],
            'total_paid_formatted'        => $format( $wallet['total_paid'] ),
            'available_balance'           => (float) $wallet['available_balance'],
            'available_balance_formatted' => $format( $wallet['available_balance'] ),
            'available_hint'              => sprintf( __( 'Solde disponible : %s', 'ecosplay-referrals' ), $format( $wallet['available_balance'] ) ),
            'kyc_label'                   => (string) $wallet['kyc_label'],
            'kyc_errors'                  => array_map( 'wp_strip_all_tags', (array) $wallet['kyc_errors'] ),
            'can_transfer'                => ! empty( $wallet['can_transfer'] ),
        );

        $payload['payouts'] = array();

        if ( ! empty( $wallet['payouts'] ) && is_array( $wallet['payouts'] ) ) {
            foreach ( $wallet['payouts'] as $entry ) {
                $payload['payouts'][] = array(
                    'id'                   => isset( $entry->id ) ? (int) $entry->id : 0,
                    'amount_formatted'     => $format( isset( $entry->amount ) ? $entry->amount : 0 ),
                    'status_label'         => $this->format_status_label( isset( $entry->status ) ? $entry->status : '' ),
                    'status_state'         => $this->normalize_status_state( isset( $entry->status ) ? $entry->status : '' ),
                    'created_at_formatted' => $this->format_datetime( isset( $entry->created_at ) ? $entry->created_at : '' ),
                    'failure_message'      => isset( $entry->failure_message ) ? wp_strip_all_tags( (string) $entry->failure_message ) : '',
                );
            }
        }

        return $payload;
    }

    /**
     * Convertit un statut de virement en libellé lisible.
     *
     * @param string $status Statut brut.
     *
     * @return string
     */
    protected function format_status_label( $status ) {
        $state = $this->normalize_status_state( $status );

        switch ( $state ) {
            case 'success':
                return __( 'Réussi', 'ecosplay-referrals' );
            case 'failed':
                return __( 'Échoué', 'ecosplay-referrals' );
            default:
                return __( 'En cours', 'ecosplay-referrals' );
        }
    }

    /**
     * Normalise le statut pour gérer les classes CSS.
     *
     * @param string $status Statut brut.
     *
     * @return string
     */
    protected function normalize_status_state( $status ) {
        $status = strtolower( (string) $status );

        if ( in_array( $status, array( 'paid', 'succeeded', 'completed' ), true ) ) {
            return 'success';
        }

        if ( in_array( $status, array( 'failed', 'canceled', 'cancelled' ), true ) ) {
            return 'failed';
        }

        return 'pending';
    }

    /**
     * Formate une date issue du journal des virements.
     *
     * @param string $datetime Chaîne de date SQL.
     *
     * @return string
     */
    protected function format_datetime( $datetime ) {
        if ( empty( $datetime ) ) {
            return '';
        }

        $timestamp = strtotime( $datetime );

        if ( ! $timestamp ) {
            return $datetime;
        }

        $format = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

        return wp_date( $format, $timestamp );
    }

    /**
     * Valide l\'utilisateur courant et vérifie son éligibilité.
     *
     * @param bool $for_ajax Indique si l\'appel provient d\'une requête AJAX.
     *
     * @return int
     */
    protected function resolve_authorized_user( $for_ajax = false ) {
        if ( ! is_user_logged_in() ) {
            if ( $for_ajax ) {
                wp_send_json_error(
                    array(
                        'message' => __( 'Veuillez vous connecter pour poursuivre.', 'ecosplay-referrals' ),
                    ),
                    401
                );
            }

            return 0;
        }

        $user_id = get_current_user_id();

        if ( $user_id <= 0 || ! $this->service->is_user_allowed( $user_id ) ) {
            if ( $for_ajax ) {
                wp_send_json_error(
                    array(
                        'message' => __( 'Votre abonnement ne permet pas d\'accéder au portefeuille.', 'ecosplay-referrals' ),
                    ),
                    403
                );
            }

            return 0;
        }

        return (int) $user_id;
    }
}
