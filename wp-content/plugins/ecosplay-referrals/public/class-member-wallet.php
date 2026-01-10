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

        add_action( 'wp_ajax_ecos_referrals_wallet_associate', array( $this, 'handle_association_request' ) );
        add_action( 'wp_ajax_ecos_referrals_request_reward', array( $this, 'handle_reward_request' ) );
        add_action( 'wp_ajax_ecos_referrals_wallet_refresh', array( $this, 'handle_refresh_request' ) );
        add_action( 'wp_ajax_ecos_referrals_wallet_stripe_link', array( $this, 'handle_stripe_link_request' ) );

        add_action( 'wp_ajax_nopriv_ecos_referrals_wallet_associate', array( $this, 'reject_unauthenticated_request' ) );
        add_action( 'wp_ajax_nopriv_ecos_referrals_request_reward', array( $this, 'reject_unauthenticated_request' ) );
        add_action( 'wp_ajax_nopriv_ecos_referrals_wallet_refresh', array( $this, 'reject_unauthenticated_request' ) );
        add_action( 'wp_ajax_nopriv_ecos_referrals_wallet_stripe_link', array( $this, 'reject_unauthenticated_request' ) );
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
        <div class="ecos-referral-wallet" data-wallet-can-request="<?php echo esc_attr( $payload['can_request_reward'] ? '1' : '0' ); ?>" data-wallet-association-status="<?php echo esc_attr( $payload['association_status'] ); ?>" data-wallet-tremendous-balance="<?php echo esc_attr( $payload['tremendous_balance_formatted'] ); ?>">
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

            <?php if ( $payload['tremendous_enabled'] ) : ?>
                <div class="ecos-referral-wallet__association">
                    <h4><?php esc_html_e( 'Compte Tremendous', 'ecosplay-referrals' ); ?></h4>
                    <p data-wallet-field="association_label"><?php echo esc_html( $payload['association_label'] ); ?></p>
                    <p class="ecos-referral-wallet__tremendous-balance" data-wallet-field="tremendous_balance_label" style="<?php echo '' === $payload['tremendous_balance_label'] ? 'display:none;' : ''; ?>"><?php echo esc_html( $payload['tremendous_balance_label'] ); ?></p>
                    <ul class="ecos-referral-wallet__association-errors" data-wallet-field="association_errors" style="<?php echo empty( $payload['association_errors'] ) ? 'display:none;' : ''; ?>">
                        <?php foreach ( $payload['association_errors'] as $error ) : ?>
                            <li><?php echo esc_html( $error ); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>

                <div class="ecos-referral-wallet__actions">
                    <button class="button" data-wallet-action="associate" type="button" style="<?php echo $payload['is_associated'] ? 'display:none;' : ''; ?>"><?php esc_html_e( 'Associer mon compte Tremendous', 'ecosplay-referrals' ); ?></button>
                    <button class="button" data-wallet-action="refresh" type="button" style="<?php echo $payload['is_associated'] ? '' : 'display:none;'; ?>"><?php esc_html_e( 'Rafraîchir mon solde Tremendous', 'ecosplay-referrals' ); ?></button>
                </div>
            <?php endif; ?>

            <div class="ecos-referral-wallet__association ecos-referral-wallet__association--stripe">
                <h4><?php esc_html_e( 'Compte Stripe', 'ecosplay-referrals' ); ?></h4>
                <p data-wallet-field="stripe_label"><?php echo esc_html( $payload['stripe_label'] ); ?></p>
                <ul class="ecos-referral-wallet__association-errors" data-wallet-field="stripe_errors" style="<?php echo empty( $payload['stripe_errors'] ) ? 'display:none;' : ''; ?>">
                    <?php foreach ( $payload['stripe_errors'] as $error ) : ?>
                        <li><?php echo esc_html( $error ); ?></li>
                    <?php endforeach; ?>
                </ul>
                <div class="ecos-referral-wallet__actions">
                    <button class="button" data-wallet-action="stripe-link" type="button"><?php esc_html_e( 'Connecter/Configurer mon compte Stripe', 'ecosplay-referrals' ); ?></button>
                </div>
            </div>

            <div data-wallet-section="reward" style="<?php echo $payload['can_request_reward'] ? '' : 'display:none;'; ?>">
                <form class="ecos-referral-wallet__transfer" data-wallet-form="reward">
                    <label for="ecos-referral-wallet-amount"><?php esc_html_e( 'Montant de la récompense', 'ecosplay-referrals' ); ?></label>
                    <input id="ecos-referral-wallet-amount" type="number" step="0.01" min="1" name="amount" required />
                    <p class="ecos-referral-wallet__hint" data-wallet-field="available_hint"><?php echo esc_html( $payload['available_hint'] ); ?></p>
                    <button type="submit" class="button" data-wallet-action="reward"><?php esc_html_e( 'Demander une récompense', 'ecosplay-referrals' ); ?></button>
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
                    'associate' => 'ecos_referrals_wallet_associate',
                    'reward'    => 'ecos_referrals_request_reward',
                    'refresh'   => 'ecos_referrals_wallet_refresh',
                    'stripeLink' => 'ecos_referrals_wallet_stripe_link',
                ),
                'i18n'    => array(
                    'genericError'      => __( 'Une erreur est survenue. Veuillez réessayer.', 'ecosplay-referrals' ),
                    'rewardRequested'   => __( 'Votre demande de récompense a été enregistrée.', 'ecosplay-referrals' ),
                    'associationLinked' => __( 'Votre compte Tremendous est désormais associé.', 'ecosplay-referrals' ),
                    'balanceRefreshed'  => __( 'Le solde Tremendous a été actualisé.', 'ecosplay-referrals' ),
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
     * Génère un lien Stripe Connect pour l\'onboarding du membre.
     *
     * @return void
     */
    public function handle_stripe_link_request() {
        $user_id = $this->resolve_authorized_user( true );

        if ( ! $user_id ) {
            return;
        }

        check_ajax_referer( 'ecos_referral_wallet' );

        $return_url  = isset( $_POST['return_url'] ) ? esc_url_raw( wp_unslash( $_POST['return_url'] ) ) : '';
        $refresh_url = isset( $_POST['refresh_url'] ) ? esc_url_raw( wp_unslash( $_POST['refresh_url'] ) ) : '';
        $fallback_url = wp_get_referer();

        if ( '' === $return_url && $fallback_url ) {
            $return_url = esc_url_raw( $fallback_url );
        }

        if ( '' === $refresh_url && $fallback_url ) {
            $refresh_url = esc_url_raw( $fallback_url );
        }

        if ( '' === $return_url ) {
            $return_url = home_url( '/' );
        }

        if ( '' === $refresh_url ) {
            $refresh_url = $return_url;
        }

        $link = $this->service->generate_account_link( $user_id, $return_url, $refresh_url );

        if ( is_wp_error( $link ) ) {
            wp_send_json_error(
                array(
                    'message' => $link->get_error_message(),
                ),
                400
            );
        }

        $link_url = isset( $link['url'] ) ? esc_url_raw( $link['url'] ) : '';

        if ( '' === $link_url ) {
            wp_send_json_error(
                array(
                    'message' => __( 'Stripe n’a pas renvoyé de lien valide.', 'ecosplay-referrals' ),
                ),
                400
            );
        }

        wp_send_json_success(
            array(
                'url' => $link_url,
            )
        );
    }

    /**
     * Traite la demande d'association au programme Tremendous.
     *
     * @return void
     */
    public function handle_association_request() {
        $user_id = $this->resolve_authorized_user( true );

        if ( ! $user_id ) {
            return;
        }

        check_ajax_referer( 'ecos_referral_wallet' );

        $result = $this->service->associate_tremendous_account( $user_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error(
                array(
                    'message' => $result->get_error_message(),
                ),
                400
            );
        }

        $wallet  = $this->service->get_member_wallet( $user_id );
        $payload = is_wp_error( $wallet ) ? array() : $this->prepare_wallet_payload( $wallet );

        wp_send_json_success(
            array(
                'message' => __( 'Votre compte Tremendous est désormais associé.', 'ecosplay-referrals' ),
                'wallet'  => $payload,
            )
        );
    }

    /**
     * Rafraîchit le solde et le statut Tremendous pour le membre courant.
     *
     * @return void
     */
    public function handle_refresh_request() {
        $user_id = $this->resolve_authorized_user( true );

        if ( ! $user_id ) {
            return;
        }

        check_ajax_referer( 'ecos_referral_wallet' );

        $result = $this->service->refresh_tremendous_account( $user_id );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error(
                array(
                    'message' => $result->get_error_message(),
                ),
                400
            );
        }

        $wallet  = $this->service->get_member_wallet( $user_id );
        $payload = is_wp_error( $wallet ) ? array() : $this->prepare_wallet_payload( $wallet );

        wp_send_json_success(
            array(
                'message' => __( 'Le solde Tremendous a été actualisé.', 'ecosplay-referrals' ),
                'wallet'  => $payload,
            )
        );
    }

    /**
     * Traite la demande de récompense Tremendous depuis l\'interface membre.
     *
     * @return void
     */
    public function handle_reward_request() {
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
                    'message' => __( 'Veuillez indiquer un montant de récompense valide.', 'ecosplay-referrals' ),
                ),
                400
            );
        }

        $result = $this->service->request_tremendous_reward( $user_id, $amount );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error(
                array(
                    'message' => $result->get_error_message(),
                ),
                400
            );
        }

        $wallet  = $this->service->get_member_wallet( $user_id );
        $payload = is_wp_error( $wallet ) ? array() : $this->prepare_wallet_payload( $wallet );

        wp_send_json_success(
            array(
                'message' => __( 'Votre demande de récompense a été enregistrée.', 'ecosplay-referrals' ),
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

        $tremendous_balance = isset( $wallet['tremendous_balance'] ) && null !== $wallet['tremendous_balance'] ? (float) $wallet['tremendous_balance'] : null;

        $payload = array(
            'currency'                    => $currency,
            'earned_credits'              => (float) $wallet['earned_credits'],
            'earned_credits_formatted'    => $format( $wallet['earned_credits'] ),
            'total_paid'                  => (float) $wallet['total_paid'],
            'total_paid_formatted'        => $format( $wallet['total_paid'] ),
            'available_balance'           => (float) $wallet['available_balance'],
            'available_balance_formatted' => $format( $wallet['available_balance'] ),
            'available_hint'              => sprintf( __( 'Solde disponible : %s', 'ecosplay-referrals' ), $format( $wallet['available_balance'] ) ),
            'association_label'           => isset( $wallet['association_label'] ) ? (string) $wallet['association_label'] : '',
            'association_errors'          => array_map( 'wp_strip_all_tags', isset( $wallet['association_errors'] ) ? (array) $wallet['association_errors'] : array() ),
            'association_status'          => isset( $wallet['association_status'] ) ? (string) $wallet['association_status'] : '',
            'can_request_reward'          => ! empty( $wallet['can_request_reward'] ),
            'is_associated'               => ! empty( $wallet['is_associated'] ),
            'tremendous_enabled'          => ! empty( $wallet['tremendous_enabled'] ),
            'tremendous_balance'          => $tremendous_balance,
            'tremendous_balance_formatted'=> null === $tremendous_balance ? '' : $format( $tremendous_balance ),
            'tremendous_balance_label'    => null === $tremendous_balance ? '' : sprintf( __( 'Solde Tremendous disponible : %s', 'ecosplay-referrals' ), $format( $tremendous_balance ) ),
            'stripe_status'               => isset( $wallet['stripe_status'] ) ? (string) $wallet['stripe_status'] : '',
            'stripe_label'                => isset( $wallet['stripe_label'] ) ? (string) $wallet['stripe_label'] : '',
            'stripe_errors'               => array_map( 'wp_strip_all_tags', isset( $wallet['stripe_errors'] ) ? (array) $wallet['stripe_errors'] : array() ),
            'stripe_account_id'           => isset( $wallet['stripe_account_id'] ) ? (string) $wallet['stripe_account_id'] : '',
            'stripe_account_missing'      => ! empty( $wallet['stripe_account_missing'] ),
        );

        $payload['can_transfer'] = isset( $wallet['can_transfer'] ) ? ! empty( $wallet['can_transfer'] ) : $payload['can_request_reward'];

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
