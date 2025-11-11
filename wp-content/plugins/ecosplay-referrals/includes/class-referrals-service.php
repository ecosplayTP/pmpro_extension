<?php
/**
 * Business logic coordinator for referral operations.
 *
 * @package Ecosplay\Referrals
 * @file    wp-content/plugins/ecosplay-referrals/includes/class-referrals-service.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Encapsulates referral flows while delegating persistence to the store.
 */
class Ecosplay_Referrals_Service {
    const FIELD_NAME    = 'ecos_referral_code';
    const NONCE_NAME    = 'ecos_referral_nonce';
    const NONCE_ACTION  = 'ecos_referral_validate';
    const COOKIE_NAME   = 'ecos_referral_hint';
    const DISCOUNT_EUR  = 10.0;
    const REWARD_POINTS = 10.0;
    const DEFAULT_CURRENCY = 'eur';
    const BALANCE_ALERT_EMAIL = 'ptacien@gmail.com';
    const DEFAULT_ALLOWED_LEVELS = array( 'pmpro_role_2' );
    const DEFAULT_NOTICE_MESSAGE = 'Parrainez vos amis pour cumuler des récompenses ECOSplay.';
    const NOTICE_VERSION_OPTION  = 'ecosplay_referrals_notice_version';

    /**
     * Storage layer implementation.
     *
     * @var Ecosplay_Referrals_Store
     */
    protected $store;

    /**
     * Stripe API client helper.
     *
     * @var Ecosplay_Referrals_Stripe_Client
     */
    protected $stripe_client;

    /**
     * Wires hooks and stores dependencies.
     *
     * @param Ecosplay_Referrals_Store         $store         Persistence facade.
     * @param Ecosplay_Referrals_Stripe_Client $stripe_client Stripe HTTP client.
     */
    public function __construct( Ecosplay_Referrals_Store $store, Ecosplay_Referrals_Stripe_Client $stripe_client ) {
        $this->store         = $store;
        $this->stripe_client = $stripe_client;

        add_action( 'pmpro_after_change_membership_level', array( $this, 'handle_membership_update' ), 10, 3 );
        add_action( 'pmpro_checkout_boxes', array( $this, 'render_checkout_field' ) );
        add_filter( 'pmpro_registration_checks', array( $this, 'validate_referral_code' ) );
        add_filter( 'pmpro_checkout_level', array( $this, 'apply_referral_discount' ) );
        add_action( 'pmpro_after_checkout', array( $this, 'award_referral_rewards' ), 10, 2 );
        add_action( 'init', array( $this, 'prefill_from_query' ) );
        add_action( 'ecosplay_referrals_request_payout', array( $this, 'handle_withdraw_request' ), 10, 4 );
        add_action( 'ecosplay_referrals_admin_batch_payout', array( $this, 'handle_batch_payout' ), 10, 4 );
        add_action( 'ecosplay_referrals_daily_balance_check', array( $this, 'run_daily_balance_check' ) );
    }

    /**
     * Handles membership updates to provision referral codes when needed.
     *
     * @param int        $level_id     New membership level identifier.
     * @param int        $user_id      Affected user identifier.
     * @param int|string $cancel_level Previous level identifier when available.
     *
     * @return void
     */
    public function handle_membership_update( $level_id, $user_id, $cancel_level = 0 ) {
        if ( ! $this->is_user_allowed( $user_id ) ) {
            return;
        }

        $this->ensure_user_code( $user_id );
    }

    /**
     * Ensures a referral code exists for each new user.
     *
     * @param int $user_id Registered user identifier.
     *
     * @return void
     */
    public function ensure_user_code( $user_id ) {
        $user_id = (int) $user_id;

        if ( $user_id <= 0 || ! $this->is_user_allowed( $user_id ) ) {
            return;
        }

        $existing = $this->store->get_referral_by_user( $user_id );

        if ( $existing && ! empty( $existing->code ) ) {
            return;
        }

        $this->store->regenerate_code( $user_id );
    }

    /**
     * Ensures a Stripe Connect Express account exists for the given member.
     *
     * @param int $user_id Target user identifier.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function ensure_stripe_account( $user_id ) {
        $user_id = (int) $user_id;

        if ( $user_id <= 0 ) {
            return new WP_Error( 'ecosplay_referrals_invalid_user', __( 'Utilisateur invalide pour Stripe.', 'ecosplay-referrals' ) );
        }

        if ( ! $this->stripe_client->is_configured() ) {
            return new WP_Error( 'ecosplay_referrals_stripe_missing_secret', __( 'Configurez la clé Stripe avant de poursuivre.', 'ecosplay-referrals' ) );
        }

        $this->ensure_user_code( $user_id );

        $referral = $this->store->get_referral_by_user( $user_id );

        if ( ! $referral ) {
            return new WP_Error( 'ecosplay_referrals_missing_profile', __( 'Aucun profil de parrainage disponible.', 'ecosplay-referrals' ) );
        }

        if ( ! empty( $referral->stripe_account_id ) ) {
            $account = $this->stripe_client->retrieve_account( $referral->stripe_account_id );

            if ( is_wp_error( $account ) ) {
                return $account;
            }

            $capabilities = isset( $account['capabilities'] ) && is_array( $account['capabilities'] ) ? $account['capabilities'] : array();
            $this->store->save_stripe_account( $user_id, $referral->stripe_account_id, $capabilities );

            return $account;
        }

        $user = get_userdata( $user_id );

        if ( ! $user ) {
            return new WP_Error( 'ecosplay_referrals_missing_user', __( 'Utilisateur introuvable pour la création Stripe.', 'ecosplay-referrals' ) );
        }

        $account_args = apply_filters(
            'ecosplay_referrals_stripe_account_args',
            array(
                'type'         => 'express',
                'country'      => $this->guess_country_code(),
                'email'        => $user->user_email,
                'capabilities' => array( 'transfers' => array( 'requested' => true ) ),
                'metadata'     => array( 'user_id' => $user_id, 'site' => home_url() ),
            ),
            $user,
            $this
        );

        $account = $this->stripe_client->create_account( $account_args );

        if ( is_wp_error( $account ) ) {
            return $account;
        }

        if ( empty( $account['id'] ) ) {
            return new WP_Error( 'ecosplay_referrals_stripe_account_missing_id', __( 'Stripe n\'a pas renvoyé d\'identifiant de compte.', 'ecosplay-referrals' ) );
        }

        $capabilities = isset( $account['capabilities'] ) && is_array( $account['capabilities'] ) ? $account['capabilities'] : array();
        $this->store->save_stripe_account( $user_id, $account['id'], $capabilities );

        do_action( 'ecosplay_referrals_stripe_account_created', $user_id, $account );

        return $account;
    }

    /**
     * Builds or refreshes an onboarding link for the connected account.
     *
     * @param int    $user_id     Member identifier.
     * @param string $return_url  Success redirection URL.
     * @param string $refresh_url Refresh URL when onboarding is interrupted.
     * @param string $type        Stripe link type.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function generate_account_link( $user_id, $return_url, $refresh_url, $type = 'account_onboarding' ) {
        $return_url  = esc_url_raw( $return_url );
        $refresh_url = esc_url_raw( $refresh_url );

        if ( '' === $return_url || '' === $refresh_url ) {
            return new WP_Error( 'ecosplay_referrals_missing_urls', __( 'Les URL de redirection Stripe sont obligatoires.', 'ecosplay-referrals' ) );
        }

        $account = $this->ensure_stripe_account( $user_id );

        if ( is_wp_error( $account ) ) {
            return $account;
        }

        $referral = $this->store->get_referral_by_user( (int) $user_id );

        if ( ! $referral || empty( $referral->stripe_account_id ) ) {
            return new WP_Error( 'ecosplay_referrals_missing_account', __( 'Le compte Stripe Connect est introuvable.', 'ecosplay-referrals' ) );
        }

        $args = apply_filters(
            'ecosplay_referrals_stripe_account_link_args',
            array(
                'account'     => $referral->stripe_account_id,
                'type'        => $type,
                'return_url'  => $return_url,
                'refresh_url' => $refresh_url,
            ),
            $user_id,
            $type,
            $this
        );

        $link = $this->stripe_client->create_account_link( $referral->stripe_account_id, $args );

        if ( ! is_wp_error( $link ) ) {
            do_action( 'ecosplay_referrals_stripe_account_link_generated', $user_id, $link );
        }

        return $link;
    }

    /**
     * Generates a login link for the Express dashboard.
     *
     * @param int $user_id Member identifier.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function generate_login_link( $user_id ) {
        $referral = $this->store->get_referral_by_user( (int) $user_id );

        if ( ! $referral || empty( $referral->stripe_account_id ) ) {
            return new WP_Error( 'ecosplay_referrals_missing_account', __( 'Le compte Stripe Connect est introuvable.', 'ecosplay-referrals' ) );
        }

        $link = $this->stripe_client->create_login_link( $referral->stripe_account_id );

        if ( ! is_wp_error( $link ) ) {
            do_action( 'ecosplay_referrals_stripe_login_link_generated', $user_id, $link );
        }

        return $link;
    }

    /**
     * Creates a transfer on Stripe and records it in the ledger.
     *
     * @param int                 $user_id  Beneficiary identifier.
     * @param float               $amount   Amount in major currency units.
     * @param string              $currency ISO currency code.
     * @param array<string,mixed> $metadata Optional metadata forwarded to Stripe.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function create_transfer( $user_id, $amount, $currency = 'eur', array $metadata = array() ) {
        $user_id  = (int) $user_id;
        $amount   = (float) $amount;
        $currency = strtolower( (string) $currency );

        if ( $user_id <= 0 ) {
            return new WP_Error( 'ecosplay_referrals_invalid_user', __( 'Utilisateur invalide pour le transfert Stripe.', 'ecosplay-referrals' ) );
        }

        if ( $amount <= 0 ) {
            return new WP_Error( 'ecosplay_referrals_invalid_amount', __( 'Le montant du transfert doit être positif.', 'ecosplay-referrals' ) );
        }

        if ( ! $this->stripe_client->is_configured() ) {
            return new WP_Error( 'ecosplay_referrals_stripe_missing_secret', __( 'Configurez la clé Stripe avant de poursuivre.', 'ecosplay-referrals' ) );
        }

        $referral = $this->store->get_referral_by_user( $user_id );

        if ( ! $referral || empty( $referral->stripe_account_id ) ) {
            return new WP_Error( 'ecosplay_referrals_missing_account', __( 'Le compte Stripe Connect est introuvable.', 'ecosplay-referrals' ) );
        }

        $balance_status = $this->check_platform_balance( $amount, $currency );

        if ( ! $balance_status['ok'] ) {
            $this->handle_balance_alert(
                'transfer',
                $balance_status,
                array(
                    'user_id'     => $user_id,
                    'referral_id' => (int) $referral->id,
                )
            );

            if ( isset( $balance_status['error'] ) && $balance_status['error'] instanceof WP_Error ) {
                return $balance_status['error'];
            }

            return new WP_Error( 'ecosplay_referrals_insufficient_balance', __( 'Le solde Stripe disponible est insuffisant pour effectuer ce transfert.', 'ecosplay-referrals' ) );
        }

        $payload = apply_filters(
            'ecosplay_referrals_transfer_args',
            array(
                'amount'      => (int) max( 1, round( $amount * 100 ) ),
                'currency'    => $currency,
                'destination' => $referral->stripe_account_id,
                'metadata'    => $metadata,
            ),
            $user_id,
            $amount,
            $currency,
            $metadata,
            $this
        );

        $response = $this->stripe_client->create_transfer( $payload );

        if ( is_wp_error( $response ) ) {
            $this->store->record_payout_event(
                array(
                    'user_id'        => $user_id,
                    'referral_id'    => (int) $referral->id,
                    'amount'         => $amount,
                    'currency'       => $currency,
                    'status'         => 'failed',
                    'failure_code'   => $response->get_error_code(),
                    'failure_message'=> $response->get_error_message(),
                    'metadata'       => $metadata,
                )
            );

            return $response;
        }

        $transfer_id = isset( $response['id'] ) ? $response['id'] : null;
        $payout_id   = isset( $response['destination_payment'] ) ? $response['destination_payment'] : null;

        $this->store->record_payout_event(
            array(
                'user_id'     => $user_id,
                'referral_id' => (int) $referral->id,
                'amount'      => $amount,
                'currency'    => $currency,
                'status'      => 'pending',
                'transfer_id' => $transfer_id,
                'payout_id'   => $payout_id,
                'metadata'    => $metadata,
            )
        );

        do_action( 'ecosplay_referrals_transfer_created', $user_id, $response );

        return $response;
    }

    /**
     * Handles member-triggered withdrawal requests.
     *
     * @param int                 $user_id  Beneficiary identifier.
     * @param float               $amount   Requested amount.
     * @param string              $currency ISO currency code.
     * @param array<string,mixed> $metadata Metadata forwarded to Stripe.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function handle_withdraw_request( $user_id, $amount, $currency = 'eur', array $metadata = array() ) {
        $result = $this->create_transfer( $user_id, $amount, $currency, $metadata );

        do_action( 'ecosplay_referrals_withdraw_processed', $user_id, $result );

        return $result;
    }

    /**
     * Handles administrator-triggered payout batches.
     *
     * @param int                 $user_id  Beneficiary identifier.
     * @param float               $amount   Amount to transfer.
     * @param string              $currency ISO currency code.
     * @param array<string,mixed> $metadata Metadata forwarded to Stripe.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function handle_batch_payout( $user_id, $amount, $currency = 'eur', array $metadata = array() ) {
        $result = $this->create_transfer( $user_id, $amount, $currency, $metadata );

        do_action( 'ecosplay_referrals_batch_processed', $user_id, $result );

        return $result;
    }

    /**
     * Interroge Stripe pour vérifier le solde disponible avant un paiement.
     *
     * @param float  $expected_payout Montant attendu en devise principale.
     * @param string $currency        Devise visée (ISO, minuscule).
     *
     * @return array<string,mixed>
     */
    public function check_platform_balance( $expected_payout, $currency = self::DEFAULT_CURRENCY ) {
        $expected  = max( 0.0, (float) $expected_payout );
        $currency  = strtolower( (string) $currency );
        $available = 0.0;
        $result    = array(
            'ok'        => true,
            'required'  => $expected,
            'currency'  => $currency,
            'available' => $available,
        );

        if ( $expected <= 0 ) {
            return $result;
        }

        if ( ! $this->stripe_client->is_configured() ) {
            $error             = new WP_Error( 'ecosplay_referrals_stripe_missing_secret', __( 'Configurez la clé Stripe avant de poursuivre.', 'ecosplay-referrals' ) );
            $result['ok']      = false;
            $result['error']   = $error;

            return $result;
        }

        $response = $this->stripe_client->get_balance();

        if ( is_wp_error( $response ) ) {
            $result['ok']    = false;
            $result['error'] = $response;

            return $result;
        }

        if ( isset( $response['available'] ) && is_array( $response['available'] ) ) {
            foreach ( $response['available'] as $entry ) {
                if ( ! is_array( $entry ) ) {
                    continue;
                }

                if ( ! isset( $entry['currency'] ) || strtolower( (string) $entry['currency'] ) !== $currency ) {
                    continue;
                }

                $amount    = isset( $entry['amount'] ) ? floatval( $entry['amount'] ) : 0.0;
                $available = round( $amount / 100, 2 );
                break;
            }
        }

        $result['available'] = $available;
        $result['raw']       = $response;
        $result['ok']        = $available >= $expected;

        return $result;
    }

    /**
     * Enregistre un paiement manuel dans le journal des virements.
     *
     * @param int    $user_id  Bénéficiaire.
     * @param float  $amount   Montant crédité.
     * @param string $currency Code devise ISO.
     * @param string $note     Note explicative.
     *
     * @return int|WP_Error
     */
    public function record_manual_payout( $user_id, $amount, $currency = 'eur', $note = '' ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'ecosplay_referrals_forbidden', __( 'Vous ne pouvez pas enregistrer ce paiement.', 'ecosplay-referrals' ) );
        }

        $user_id  = (int) $user_id;
        $amount   = round( (float) $amount, 2 );
        $currency = strtolower( (string) $currency );
        $note     = sanitize_text_field( $note );

        if ( $user_id <= 0 ) {
            return new WP_Error( 'ecosplay_referrals_invalid_user', __( 'Utilisateur invalide pour le paiement manuel.', 'ecosplay-referrals' ) );
        }

        if ( $amount <= 0 ) {
            return new WP_Error( 'ecosplay_referrals_invalid_amount', __( 'Le montant doit être supérieur à zéro.', 'ecosplay-referrals' ) );
        }

        $referral = $this->store->get_referral_by_user( $user_id );

        if ( ! $referral ) {
            return new WP_Error( 'ecosplay_referrals_missing_profile', __( 'Aucun profil de parrainage pour ce membre.', 'ecosplay-referrals' ) );
        }

        $entry_id = $this->store->record_payout_event(
            array(
                'user_id'     => $user_id,
                'referral_id' => (int) $referral->id,
                'amount'      => $amount,
                'currency'    => $currency,
                'status'      => 'manual',
                'metadata'    => array(
                    'note'        => $note,
                    'source'      => 'manual',
                    'recorded_by' => get_current_user_id(),
                ),
            )
        );

        if ( $entry_id <= 0 ) {
            return new WP_Error( 'ecosplay_referrals_manual_failed', __( 'Impossible d\'enregistrer le paiement manuel.', 'ecosplay-referrals' ) );
        }

        $this->store->increment_total_paid( (int) $referral->id, $amount );

        do_action( 'ecosplay_referrals_manual_payout_recorded', $user_id, $amount, $note );

        return $entry_id;
    }

    /**
     * Annule un transfert Stripe en attente.
     *
     * @param string $transfer_id Identifiant du transfert Stripe.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function cancel_transfer( $transfer_id ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return new WP_Error( 'ecosplay_referrals_forbidden', __( 'Vous ne pouvez pas annuler ce transfert.', 'ecosplay-referrals' ) );
        }

        $transfer_id = trim( (string) $transfer_id );

        if ( '' === $transfer_id ) {
            return new WP_Error( 'ecosplay_referrals_missing_transfer', __( 'Transfert introuvable.', 'ecosplay-referrals' ) );
        }

        if ( ! $this->stripe_client->is_configured() ) {
            return new WP_Error( 'ecosplay_referrals_stripe_missing_secret', __( 'Configurez la clé Stripe avant de poursuivre.', 'ecosplay-referrals' ) );
        }

        $response = $this->stripe_client->cancel_transfer( $transfer_id );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $metadata = isset( $response['metadata'] ) ? $response['metadata'] : array();

        $this->store->update_payout_by_transfer( $transfer_id, 'canceled', array( 'metadata' => $metadata ) );

        do_action( 'ecosplay_referrals_transfer_canceled', $transfer_id, $response );

        return $response;
    }

    /**
     * Outputs the referral input during checkout.
     *
     * @return void
     */
    public function render_checkout_field() {
        $level = $this->get_level_at_checkout();

        if ( ! $this->is_level_allowed( $level ) ) {
            return;
        }

        $prefill = $this->get_submitted_code();
        ?>
        <div id="ecos-referral" class="pmpro_checkout">
            <hr />
            <h3><?php esc_html_e( 'Parrainage', 'ecosplay-referrals' ); ?></h3>
            <div class="pmpro_checkout-fields">
                <div class="pmpro_checkout-field pmpro_checkout-field-referral">
                    <label for="<?php echo esc_attr( self::FIELD_NAME ); ?>">
                        <?php esc_html_e( 'Code de parrainage (optionnel)', 'ecosplay-referrals' ); ?>
                    </label>
                    <input
                        type="text"
                        name="<?php echo esc_attr( self::FIELD_NAME ); ?>"
                        id="<?php echo esc_attr( self::FIELD_NAME ); ?>"
                        value="<?php echo esc_attr( $prefill ); ?>"
                        placeholder="ECOS-XXXXXX"
                        class="input"
                    />
                    <?php wp_nonce_field( self::NONCE_ACTION, self::NONCE_NAME ); ?>
                    <p class="pmpro_asterisk">
                        <?php esc_html_e( 'Si le code est valide, une remise sera appliquée et votre parrain sera crédité.', 'ecosplay-referrals' ); ?>
                    </p>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Validates the referral input during checkout processing.
     *
     * @param bool $is_valid Existing Paid Memberships Pro validation state.
     *
     * @return bool
     */
    public function validate_referral_code( $is_valid ) {
        if ( ! $is_valid ) {
            return $is_valid;
        }

        $code = $this->get_request_code();

        if ( '' === $code ) {
            return $is_valid;
        }

        if ( empty( $_REQUEST[ self::NONCE_NAME ] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST[ self::NONCE_NAME ] ) ), self::NONCE_ACTION ) ) {
            return $this->abort_checkout( __( 'Votre session de parrainage a expiré, merci de réessayer.', 'ecosplay-referrals' ) );
        }

        $referral = $this->lookup_referral( $code );

        if ( ! $referral ) {
            return $this->abort_checkout( __( 'Code de parrainage introuvable.', 'ecosplay-referrals' ) );
        }

        if ( empty( $referral->is_active ) ) {
            return $this->abort_checkout( __( 'Ce code de parrainage est désactivé.', 'ecosplay-referrals' ) );
        }

        $email = $this->get_checkout_email();
        $owner = get_userdata( (int) $referral->user_id );

        if ( $owner && $email && strtolower( $owner->user_email ) === strtolower( $email ) ) {
            return $this->abort_checkout( __( 'Vous ne pouvez pas utiliser votre propre code.', 'ecosplay-referrals' ) );
        }

        if ( function_exists( 'pmpro_hasMembershipLevel' ) && ! pmpro_hasMembershipLevel( null, (int) $referral->user_id ) ) {
            return $this->abort_checkout( __( 'Le parrain doit disposer d\'un abonnement actif.', 'ecosplay-referrals' ) );
        }

        return $is_valid;
    }

    /**
     * Applies the referral discount to the initial payment.
     *
     * @param object $level Checkout level object.
     *
     * @return object
     */
    public function apply_referral_discount( $level ) {
        if ( ! $this->is_level_allowed( $level ) ) {
            return $level;
        }

        $code = $this->get_submitted_code();

        if ( '' === $code ) {
            return $level;
        }

        $referral = $this->lookup_referral( $code );

        if ( ! $referral || empty( $referral->is_active ) ) {
            return $level;
        }

        $amount                   = $this->get_discount_amount();
        $level->initial_payment   = max( 0, floatval( $level->initial_payment ) - $amount );

        return $level;
    }

    /**
     * Awards the referrer after a successful checkout.
     *
     * @param int          $user_id Member identifier.
     * @param object|null $order   Related order object when available.
     *
     * @return void
     */
    public function award_referral_rewards( $user_id, $order = null ) {
        $code = $this->get_submitted_code();

        if ( '' === $code ) {
            return;
        }

        $referral = $this->lookup_referral( $code );

        if ( ! $referral || empty( $referral->is_active ) ) {
            return;
        }

        if ( (int) $referral->user_id === (int) $user_id ) {
            return;
        }

        $order_id        = $this->extract_order_id( $order );
        $discount_amount = $this->get_discount_amount();
        $reward_amount   = $this->get_reward_amount();
        $currency        = self::DEFAULT_CURRENCY;

        $balance_status = $this->check_platform_balance( $reward_amount, $currency );

        if ( ! $balance_status['ok'] ) {
            $this->handle_balance_alert(
                'reward',
                $balance_status,
                array(
                    'user_id'     => (int) $referral->user_id,
                    'referral_id' => (int) $referral->id,
                    'order_id'    => $order_id,
                    'code'        => $code,
                )
            );
        }

        $this->store->log_code_use(
            (int) $referral->id,
            $order_id,
            (int) $user_id,
            $discount_amount,
            $reward_amount
        );
    }

    /**
     * Stores referral hints from query string for later use.
     *
     * @return void
     */
    public function prefill_from_query() {
        if ( is_admin() || empty( $_GET['ref'] ) ) {
            return;
        }

        $code = $this->normalize_code( wp_unslash( $_GET['ref'] ) );

        if ( '' === $code ) {
            return;
        }

        setcookie(
            self::COOKIE_NAME,
            $code,
            time() + DAY_IN_SECONDS,
            COOKIEPATH ? COOKIEPATH : '/',
            COOKIE_DOMAIN,
            is_ssl(),
            true
        );

        $_COOKIE[ self::COOKIE_NAME ] = $code;
    }

    /**
     * Exposes a snapshot of referral codes for admin use restricted to eligible members.
     *
     * @param bool $only_active Whether to limit to active codes.
     *
     * @return array<int,object>
     */
    public function get_codes_overview( $only_active = true ) {
        $records = $this->store->get_active_codes( $only_active );

        if ( empty( $records ) ) {
            return array();
        }

        return array_values(
            array_filter(
                $records,
                function ( $record ) {
                    $user_id = isset( $record->user_id ) ? (int) $record->user_id : 0;

                    if ( $user_id <= 0 ) {
                        return false;
                    }

                    return $this->is_user_allowed( $user_id );
                }
            )
        );
    }

    /**
     * Retrieves payout overviews for administrative reporting.
     *
     * @return array<int,object>
     */
    public function get_payouts_overview() {
        return $this->store->get_payouts_overview();
    }

    /**
     * Lists payout ledger rows for a specific member.
     *
     * @param int $user_id User identifier.
     *
     * @return array<int,object>
     */
    public function get_user_payouts( $user_id ) {
        return $this->store->get_user_payouts( $user_id );
    }

    /**
     * Fetches webhook logs for the Stripe integration.
     *
     * @param array<string,mixed> $filters Optional filters.
     *
     * @return array<int,object>
     */
    public function get_webhook_logs( array $filters = array() ) {
        return $this->store->get_webhook_logs( $filters );
    }

    /**
     * Returns the available webhook event types recorded.
     *
     * @return array<int,string>
     */
    public function get_webhook_event_types() {
        return $this->store->get_webhook_event_types();
    }

    /**
     * Retrieves referral usage events for reporting.
     *
     * @param int|null $referral_id Optional referral identifier filter.
     * @param int      $limit       Maximum rows to return.
     * @param bool     $with_labels Whether to include column descriptors.
     *
     * @return array<int,object>|array<string,mixed>
     */
    public function get_usage_history( $referral_id = null, $limit = 20, $with_labels = false ) {
        return $this->store->get_usage_history( $referral_id, $limit, $with_labels );
    }

    /**
     * Déclenche la vérification quotidienne planifiée du solde Stripe.
     *
     * @return void
     */
    public function run_daily_balance_check() {
        $threshold = $this->get_balance_alert_threshold();

        if ( $threshold <= 0 ) {
            return;
        }

        $status = $this->check_platform_balance( $threshold, self::DEFAULT_CURRENCY );

        if ( $status['ok'] ) {
            return;
        }

        $this->handle_balance_alert( 'cron', $status, array( 'threshold' => $threshold ) );
    }

    /**
     * Regenerates every stored referral code when permitted.
     *
     * @return int
     */
    public function force_regenerate_all_codes() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return 0;
        }

        $records = $this->store->get_active_codes( false );
        $updated = 0;

        foreach ( $records as $record ) {
            $result = $this->store->regenerate_code( (int) $record->user_id );

            if ( false !== $result ) {
                $updated++;
            }
        }

        return $updated;
    }

    /**
     * Checks whether the floating notice was already dismissed by the member.
     *
     * @param int $user_id User identifier.
     *
     * @return bool
     */
    public function has_seen_notification( $user_id ) {
        $user_id = (int) $user_id;

        if ( $user_id <= 0 ) {
            return false;
        }

        return $this->store->has_seen_notification( $user_id );
    }

    /**
     * Marks the floating notice dismissal for the given member.
     *
     * @param int $user_id User identifier.
     *
     * @return bool
     */
    public function mark_notification_seen( $user_id ) {
        $user_id = (int) $user_id;

        if ( $user_id <= 0 ) {
            return false;
        }

        return $this->store->mark_notification_seen( $user_id );
    }

    /**
     * Resets notification markers for one or all members.
     *
     * @param int|null $user_id Optional user identifier.
     *
     * @return bool
     */
    public function reset_notifications( $user_id = null ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        $this->store->reset_notification_flag( null === $user_id ? null : (int) $user_id );

        if ( null === $user_id ) {
            $this->bump_notice_version();
        }

        return true;
    }

    /**
     * Forces the regeneration of a member referral code.
     *
     * @param int $user_id Target user identifier.
     *
     * @return string|false
     */
    public function force_regenerate_code( $user_id ) {
        if ( ! current_user_can( 'manage_options' ) ) {
            return false;
        }

        return $this->store->regenerate_code( (int) $user_id );
    }

    /**
     * Returns aggregate stats for the admin dashboard.
     *
     * @param string $period      Period grouping (month|week).
     * @param int    $limit       Number of periods to fetch.
     * @param bool   $with_labels Whether to include aggregation descriptors.
     *
     * @return array<string,mixed>
     */
    public function get_stats_snapshot( $period = 'month', $limit = 6, $with_labels = false ) {
        return $this->store->get_usage_summary( $period, $limit, $with_labels );
    }

    /**
     * Returns the total credits earned across all active referrals.
     *
     * @return float
     */
    public function get_total_credits_due() {
        return $this->store->get_total_credits();
    }

    /**
     * Returns the configured floating notice message.
     *
     * @return string
     */
    public function get_notice_message() {
        $default = __( self::DEFAULT_NOTICE_MESSAGE, 'ecosplay-referrals' );

        return (string) apply_filters( 'ecosplay_referrals_notice_message', $default );
    }

    /**
     * Returns the current dismissal version used for guests.
     *
     * @return int
     */
    public function get_notice_version() {
        $version = (int) get_option( self::NOTICE_VERSION_OPTION, 1 );

        if ( $version < 1 ) {
            $version = 1;
        }

        return $version;
    }

    /**
     * Increments the global notice version to invalidate guest cookies.
     *
     * @return void
     */
    public function bump_notice_version() {
        $next = $this->get_notice_version() + 1;

        update_option( self::NOTICE_VERSION_OPTION, $next, false );
    }

    /**
     * Returns the total referral points earned by a member.
     *
     * @param int $user_id User identifier.
     *
     * @return float
     */
    public function get_member_points( $user_id ) {
        $user_id = (int) $user_id;

        if ( $user_id <= 0 ) {
            return 0.0;
        }

        return $this->store->get_member_credits( $user_id );
    }

    /**
     * Retrieves or provisions the referral code for a member.
     *
     * @param int $user_id User identifier.
     *
     * @return string
     */
    public function get_member_code( $user_id ) {
        $user_id = (int) $user_id;

        if ( $user_id <= 0 || ! $this->is_user_allowed( $user_id ) ) {
            return '';
        }

        $record = $this->store->get_referral_by_user( $user_id );

        if ( $record && ! empty( $record->code ) ) {
            return (string) $record->code;
        }

        $generated = $this->store->regenerate_code( $user_id );

        return false === $generated ? '' : (string) $generated;
    }

    /**
     * Rassemble les informations de portefeuille pour un membre.
     *
     * @param int $user_id Identifiant du membre.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function get_member_wallet( $user_id ) {
        $user_id = (int) $user_id;

        if ( $user_id <= 0 || ! $this->is_user_allowed( $user_id ) ) {
            return new WP_Error( 'ecosplay_referrals_wallet_forbidden', __( 'Accès refusé au portefeuille.', 'ecosplay-referrals' ) );
        }

        $referral = $this->store->get_referral_by_user( $user_id );

        if ( ! $referral ) {
            return new WP_Error( 'ecosplay_referrals_wallet_missing', __( 'Profil de parrainage introuvable.', 'ecosplay-referrals' ) );
        }

        $capabilities = $this->decode_capabilities_field( isset( $referral->stripe_capabilities ) ? $referral->stripe_capabilities : '' );
        $errors       = array();
        $account      = null;

        if ( ! empty( $referral->stripe_account_id ) ) {
            if ( $this->stripe_client->is_configured() ) {
                $account = $this->stripe_client->retrieve_account( $referral->stripe_account_id );

                if ( is_wp_error( $account ) ) {
                    $errors[] = $account->get_error_message();
                } else {
                    if ( isset( $account['capabilities'] ) && is_array( $account['capabilities'] ) ) {
                        $capabilities = $account['capabilities'];
                        $this->store->update_stripe_capabilities( (int) $referral->id, $capabilities );
                    }

                    $errors = array_merge( $errors, $this->extract_requirement_messages( $account ) );
                }
            } else {
                $errors[] = __( 'La clé Stripe n\'est pas configurée.', 'ecosplay-referrals' );
            }
        }

        $transfers_status = isset( $capabilities['transfers'] ) ? strtolower( (string) $capabilities['transfers'] ) : '';
        $kyc              = $this->determine_kyc_state( $referral, $transfers_status, $account );

        $currency = apply_filters( 'ecosplay_referrals_wallet_currency', 'EUR', $referral, $this );
        $limit    = (int) apply_filters( 'ecosplay_referrals_wallet_payout_limit', 10, $referral, $this );

        return array(
            'earned_credits'    => isset( $referral->earned_credits ) ? (float) $referral->earned_credits : 0.0,
            'total_paid'        => isset( $referral->total_paid ) ? (float) $referral->total_paid : 0.0,
            'available_balance' => max( 0, (float) $referral->earned_credits - (float) $referral->total_paid ),
            'currency'          => $currency,
            'kyc_label'         => $kyc['label'],
            'kyc_status'        => $kyc['status'],
            'kyc_errors'        => $errors,
            'can_transfer'      => ( 'active' === $kyc['status'] ),
            'has_account'       => ! empty( $referral->stripe_account_id ),
            'payouts'           => $this->store->get_member_payouts( $user_id, max( 1, $limit ) ),
        );
    }

    /**
     * Normalises referral input to a comparable format.
     *
     * @param string $value Raw referral value.
     *
     * @return string
     */
    protected function normalize_code( $value ) {
        $value = strtoupper( sanitize_text_field( (string) $value ) );

        return trim( $value );
    }

    /**
     * Décodage sécurisé des capacités Stripe stockées.
     *
     * @param string $value Chaîne JSON potentielle.
     *
     * @return array<string,mixed>
     */
    protected function decode_capabilities_field( $value ) {
        if ( empty( $value ) ) {
            return array();
        }

        $decoded = json_decode( (string) $value, true );

        return is_array( $decoded ) ? $decoded : array();
    }

    /**
     * Génère des messages lisibles à partir des exigences Stripe.
     *
     * @param array<string,mixed> $account Données renvoyées par Stripe.
     *
     * @return array<int,string>
     */
    protected function extract_requirement_messages( $account ) {
        if ( ! is_array( $account ) ) {
            return array();
        }

        $messages     = array();
        $requirements = isset( $account['requirements'] ) && is_array( $account['requirements'] ) ? $account['requirements'] : array();

        if ( ! empty( $requirements['disabled_reason'] ) ) {
            $messages[] = sprintf(
                /* translators: %s: Stripe disabled reason. */
                __( 'Stripe a désactivé les transferts : %s', 'ecosplay-referrals' ),
                (string) $requirements['disabled_reason']
            );
        }

        if ( ! empty( $requirements['errors'] ) && is_array( $requirements['errors'] ) ) {
            foreach ( $requirements['errors'] as $error ) {
                if ( ! is_array( $error ) ) {
                    continue;
                }

                $reason      = isset( $error['reason'] ) ? $error['reason'] : '';
                $requirement = isset( $error['requirement'] ) ? $error['requirement'] : '';

                if ( '' === $reason && '' === $requirement ) {
                    continue;
                }

                if ( '' === $reason ) {
                    $messages[] = sprintf( __( 'Élément requis : %s', 'ecosplay-referrals' ), $requirement );
                } elseif ( '' === $requirement ) {
                    $messages[] = $reason;
                } else {
                    $messages[] = sprintf( '%s — %s', $requirement, $reason );
                }
            }
        }

        if ( empty( $messages ) && ! empty( $requirements['currently_due'] ) && is_array( $requirements['currently_due'] ) ) {
            $messages[] = sprintf(
                /* translators: %s: comma separated list of requirements. */
                __( 'À compléter : %s', 'ecosplay-referrals' ),
                implode( ', ', $requirements['currently_due'] )
            );
        }

        return array_filter( array_map( 'trim', $messages ) );
    }

    /**
     * Détermine le statut KYC humainement lisible.
     *
     * @param object             $referral         Enregistrement local du parrain.
     * @param string             $transfers_status Statut brut des transferts Stripe.
     * @param array<string,mixed>|null $account    Données Stripe détaillées.
     *
     * @return array{status:string,label:string}
     */
    protected function determine_kyc_state( $referral, $transfers_status, $account ) {
        if ( empty( $referral->stripe_account_id ) ) {
            return array(
                'status' => 'missing',
                'label'  => __( 'Compte Stripe à configurer.', 'ecosplay-referrals' ),
            );
        }

        $status = strtolower( (string) $transfers_status );

        if ( 'active' === $status ) {
            return array(
                'status' => 'active',
                'label'  => __( 'Compte validé, transferts disponibles.', 'ecosplay-referrals' ),
            );
        }

        if ( 'pending' === $status ) {
            return array(
                'status' => 'pending',
                'label'  => __( 'Vérification Stripe en cours.', 'ecosplay-referrals' ),
            );
        }

        $disabled_reason = '';

        if ( is_array( $account ) && ! empty( $account['requirements']['disabled_reason'] ) ) {
            $disabled_reason = (string) $account['requirements']['disabled_reason'];
        }

        if ( '' !== $disabled_reason ) {
            return array(
                'status' => 'disabled',
                'label'  => sprintf(
                    /* translators: %s: Stripe disabled reason. */
                    __( 'Compte temporairement bloqué : %s', 'ecosplay-referrals' ),
                    $disabled_reason
                ),
            );
        }

        return array(
            'status' => 'inactive',
            'label'  => __( 'Informations supplémentaires requises par Stripe.', 'ecosplay-referrals' ),
        );
    }

    /**
     * Fetches the referral code from the current request only.
     *
     * @return string
     */
    protected function get_request_code() {
        if ( empty( $_REQUEST[ self::FIELD_NAME ] ) ) {
            return '';
        }

        return $this->normalize_code( wp_unslash( $_REQUEST[ self::FIELD_NAME ] ) );
    }

    /**
     * Returns the code submitted by the member, falling back to stored hints.
     *
     * @return string
     */
    protected function get_submitted_code() {
        $code = $this->get_request_code();

        if ( '' !== $code ) {
            return $code;
        }

        if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
            return '';
        }

        return $this->normalize_code( wp_unslash( $_COOKIE[ self::COOKIE_NAME ] ) );
    }

    /**
     * Looks up a referral record using the storage layer.
     *
     * @param string $code Referral code.
     *
     * @return object|null
     */
    protected function lookup_referral( $code ) {
        if ( '' === $code ) {
            return null;
        }

        return $this->store->get_referral_by_code( $code );
    }

    /**
     * Returns the level at checkout for compatibility.
     *
     * @return object|null
     */
    protected function get_level_at_checkout() {
        if ( function_exists( 'pmpro_getLevelAtCheckout' ) ) {
            return pmpro_getLevelAtCheckout();
        }

        global $pmpro_level;

        return isset( $pmpro_level ) ? $pmpro_level : null;
    }

    /**
     * Determines whether the selected level supports referrals.
     *
     * @param object|null $level Level currently being purchased.
     *
     * @return bool
     */
    protected function is_level_allowed( $level ) {
        $allowed_levels = $this->get_allowed_level_ids();

        if ( empty( $allowed_levels ) ) {
            return false;
        }

        return $this->level_matches_allowed( $level, $allowed_levels );
    }

    /**
     * Determines whether the member is entitled to referral privileges.
     *
     * @param int $user_id User identifier.
     *
     * @return bool
     */
    public function is_user_allowed( $user_id ) {
        $user_id = (int) $user_id;

        if ( $user_id <= 0 || ! function_exists( 'pmpro_hasMembershipLevel' ) ) {
            return false;
        }

        $levels = $this->get_allowed_level_ids();

        if ( empty( $levels ) ) {
            return false;
        }

        return pmpro_hasMembershipLevel( $levels, $user_id );
    }

    /**
     * Returns the configured membership level identifiers or slugs eligible for referrals.
     *
     * @return array<int|string>
     */
    private function get_allowed_level_ids() {
        $levels = apply_filters( 'ecosplay_referrals_allowed_levels', self::DEFAULT_ALLOWED_LEVELS );
        $clean  = array();

        foreach ( (array) $levels as $value ) {
            if ( is_numeric( $value ) ) {
                $clean[] = (int) $value;
                continue;
            }

            if ( is_string( $value ) ) {
                $key = sanitize_key( $value );

                if ( '' !== $key ) {
                    $clean[] = $key;
                }
            }
        }

        return array_values( array_unique( $clean, SORT_REGULAR ) );
    }

    /**
     * Evaluates a membership level against the configured allow list.
     *
     * @param mixed        $level          Level reference from PMPro.
     * @param array<mixed> $allowed_levels Normalized configuration values.
     *
     * @return bool
     */
    private function level_matches_allowed( $level, array $allowed_levels ) {
        if ( null === $level ) {
            return false;
        }

        $ids   = array();
        $slugs = array();

        if ( is_object( $level ) ) {
            if ( isset( $level->ID ) ) {
                $ids[] = (int) $level->ID;
            }

            if ( isset( $level->id ) ) {
                $ids[] = (int) $level->id;
            }

            if ( isset( $level->name ) && is_string( $level->name ) ) {
                $slugs[] = sanitize_key( $level->name );
            }
        } elseif ( is_numeric( $level ) ) {
            $ids[] = (int) $level;
        } elseif ( is_string( $level ) ) {
            $slugs[] = sanitize_key( $level );
        }

        foreach ( $ids as $id ) {
            if ( in_array( $id, $allowed_levels, true ) ) {
                return true;
            }
        }

        foreach ( $slugs as $slug ) {
            if ( '' !== $slug && in_array( $slug, $allowed_levels, true ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reads the email submitted at checkout when available.
     *
     * @return string
     */
    protected function get_checkout_email() {
        if ( ! empty( $_REQUEST['bemail'] ) ) {
            return sanitize_email( wp_unslash( $_REQUEST['bemail'] ) );
        }

        if ( ! empty( $_REQUEST['username'] ) ) {
            return sanitize_email( wp_unslash( $_REQUEST['username'] ) );
        }

        return '';
    }

    /**
     * Centralises checkout failure reporting.
     *
     * @param string $message Feedback displayed to the customer.
     *
     * @return bool
     */
    protected function abort_checkout( $message ) {
        global $pmpro_msg, $pmpro_msgt;

        $pmpro_msg  = $message;
        $pmpro_msgt = 'pmpro_error';

        return false;
    }

    /**
     * Attempts to guess a country ISO code suitable for Stripe onboarding.
     *
     * @return string
     */
    protected function guess_country_code() {
        $locale = get_locale();

        if ( preg_match( '/_([A-Z]{2})$/', $locale, $matches ) ) {
            return strtoupper( $matches[1] );
        }

        $configured = get_option( 'pmpro_stripe_country', '' );

        if ( is_string( $configured ) && '' !== $configured ) {
            return strtoupper( substr( $configured, 0, 2 ) );
        }

        return 'FR';
    }

    /**
     * Provides the configured discount amount.
     *
     * @return float
     */
    protected function get_discount_amount() {
        return (float) apply_filters( 'ecosplay_referrals_discount_amount', self::DISCOUNT_EUR );
    }

    /**
     * Provides the configured reward amount.
     *
     * @return float
     */
    protected function get_reward_amount() {
        return (float) apply_filters( 'ecosplay_referrals_reward_amount', self::REWARD_POINTS );
    }

    /**
     * Renvoie le seuil d’alerte défini côté administration.
     *
     * @return float
     */
    protected function get_balance_alert_threshold() {
        return (float) apply_filters( 'ecosplay_referrals_balance_alert_threshold', 0.0 );
    }

    /**
     * Extracts a numeric identifier from Paid Memberships Pro orders.
     *
     * @param object|null $order Checkout order instance.
     *
     * @return int
     */
    protected function extract_order_id( $order ) {
        if ( is_object( $order ) ) {
            if ( isset( $order->id ) ) {
                return (int) $order->id;
            }

            if ( isset( $order->code ) ) {
                return absint( $order->code );
            }
        }

        return 0;
    }

    /**
     * Consigne et alerte lorsqu’un déficit Stripe est détecté.
     *
     * @param string              $source  Origine de la vérification (reward, transfer, cron).
     * @param array<string,mixed> $status  Statut retourné par check_platform_balance().
     * @param array<string,mixed> $context Informations additionnelles pour le log.
     *
     * @return void
     */
    protected function handle_balance_alert( $source, array $status, array $context = array() ) {
        $available = isset( $status['available'] ) ? (float) $status['available'] : 0.0;
        $required  = isset( $status['required'] ) ? (float) $status['required'] : 0.0;
        $currency  = isset( $status['currency'] ) ? strtoupper( (string) $status['currency'] ) : strtoupper( self::DEFAULT_CURRENCY );

        $payload = array_merge(
            $context,
            array(
                'source'    => $source,
                'required'  => $required,
                'available' => $available,
                'currency'  => $currency,
                'timestamp' => current_time( 'mysql' ),
            )
        );

        $status_label = 'insufficient';

        if ( isset( $status['error'] ) && $status['error'] instanceof WP_Error ) {
            $payload['error_code']    = $status['error']->get_error_code();
            $payload['error_message'] = $status['error']->get_error_message();
            $status_label             = 'error';
        }

        $this->store->log_webhook_event( 'balance_alert', $status_label, $payload );

        $subject = sprintf(
            /* translators: %s: currency code. */
            __( '[ECOSplay] Alerte solde Stripe (%s)', 'ecosplay-referrals' ),
            $currency
        );

        $lines = array(
            sprintf( __( 'Contexte : %s', 'ecosplay-referrals' ), $source ),
            sprintf( __( 'Montant requis : %.2f %s', 'ecosplay-referrals' ), $required, $currency ),
            sprintf( __( 'Solde disponible : %.2f %s', 'ecosplay-referrals' ), $available, $currency ),
        );

        if ( isset( $payload['error_message'] ) ) {
            $lines[] = sprintf( __( 'Erreur : %s', 'ecosplay-referrals' ), $payload['error_message'] );
        }

        $details = array();

        foreach ( $context as $key => $value ) {
            if ( is_scalar( $value ) && '' !== (string) $value ) {
                $details[] = sprintf( '%s: %s', $key, $value );
            }
        }

        if ( ! empty( $details ) ) {
            $lines[] = __( 'Détails :', 'ecosplay-referrals' );
            $lines   = array_merge( $lines, $details );
        }

        wp_mail( self::BALANCE_ALERT_EMAIL, $subject, implode( "\n", $lines ) );
    }
}
