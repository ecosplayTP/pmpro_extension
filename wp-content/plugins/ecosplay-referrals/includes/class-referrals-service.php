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
    const DEFAULT_NOTICE_MESSAGE = '';
    const NOTICE_VERSION_OPTION  = 'ecosplay_referrals_notice_version';
    const NOTICE_CACHE_GROUP     = 'ecosplay_referrals_notice';
    const NOTICE_CACHE_SLUG      = 'notice_seen';
    const NOTICE_CACHE_TTL       = 3600;
    const STRIPE_DISABLED_ERROR  = 'ecosplay_referrals_stripe_disabled';
    const TREMENDOUS_DISABLED_ERROR = 'ecosplay_referrals_tremendous_disabled';

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
     * Tremendous API client helper.
     *
     * @var Ecosplay_Referrals_Tremendous_Client|null
     */
    protected $tremendous_client;

    /**
     * Feature toggle collection passed at construction time.
     *
     * @var array<string,mixed>
     */
    protected $feature_flags = array();

    /**
     * Wires hooks and stores dependencies.
     *
     * @param Ecosplay_Referrals_Store              $store              Persistence facade.
     * @param Ecosplay_Referrals_Stripe_Client      $stripe_client      Stripe HTTP client.
     * @param Ecosplay_Referrals_Tremendous_Client|null $tremendous_client Tremendous HTTP client.
     * @param array<string,mixed>                    $feature_flags      Feature toggles.
     */
    public function __construct( Ecosplay_Referrals_Store $store, Ecosplay_Referrals_Stripe_Client $stripe_client, $tremendous_client = null, array $feature_flags = array() ) {
        $this->store             = $store;
        $this->stripe_client     = $stripe_client;
        $this->tremendous_client = $tremendous_client instanceof Ecosplay_Referrals_Tremendous_Client ? $tremendous_client : null;
        $this->feature_flags     = array_merge(
            array(
                'stripe_enabled'     => null,
                'tremendous_enabled' => null,
            ),
            $feature_flags
        );

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
     * Indicates whether Stripe-specific features should be executed.
     *
     * @return bool
     */
    public function is_stripe_enabled() {
        if ( array_key_exists( 'stripe_enabled', $this->feature_flags ) && null !== $this->feature_flags['stripe_enabled'] ) {
            $enabled = (bool) $this->feature_flags['stripe_enabled'];
        } else {
            $enabled = function_exists( 'ecosplay_referrals_is_stripe_enabled' ) ? ecosplay_referrals_is_stripe_enabled() : false;
        }

        return (bool) apply_filters( 'ecosplay_referrals_service_is_stripe_enabled', $enabled, $this );
    }

    /**
     * Indicates whether Tremendous-specific features should be executed.
     *
     * @return bool
     */
    public function is_tremendous_enabled() {
        if ( array_key_exists( 'tremendous_enabled', $this->feature_flags ) && null !== $this->feature_flags['tremendous_enabled'] ) {
            $enabled = (bool) $this->feature_flags['tremendous_enabled'];
        } else {
            $enabled = function_exists( 'ecosplay_referrals_is_tremendous_enabled' ) ? ecosplay_referrals_is_tremendous_enabled() : false;
        }

        return (bool) apply_filters( 'ecosplay_referrals_service_is_tremendous_enabled', $enabled, $this );
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

        if ( ! $this->is_stripe_enabled() ) {
            return $this->stripe_disabled_error();
        }

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
        if ( ! $this->is_stripe_enabled() ) {
            return $this->stripe_disabled_error();
        }

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

        if ( ! $this->is_stripe_enabled() ) {
            return $this->stripe_disabled_error();
        }

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

        $balance_status = $this->check_platform_balance( $amount, $currency, 'stripe' );

        if ( ! $balance_status['ok'] ) {
            $this->handle_balance_alert(
                'transfer',
                $balance_status,
                array(
                    'user_id'     => $user_id,
                    'referral_id' => (int) $referral->id,
                    'provider'    => 'stripe',
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
     * Vérifie le solde disponible côté fournisseur avant un paiement.
     *
     * @param float  $expected_payout Montant attendu en devise principale.
     * @param string $currency        Devise visée (ISO, minuscule).
     * @param string $provider        Fournisseur ciblé (stripe|tremendous).
     *
     * @return array<string,mixed>
     */
    public function check_platform_balance( $expected_payout, $currency = self::DEFAULT_CURRENCY, $provider = 'stripe' ) {
        $expected = max( 0.0, (float) $expected_payout );
        $currency = strtolower( (string) $currency );
        $provider = strtolower( (string) $provider );

        $result = array(
            'ok'        => true,
            'required'  => $expected,
            'currency'  => $currency,
            'available' => 0.0,
            'provider'  => $provider,
        );

        if ( $expected <= 0 ) {
            return $result;
        }

        if ( 'tremendous' === $provider ) {
            if ( ! $this->is_tremendous_enabled() ) {
                $result['ok']    = false;
                $result['error'] = $this->tremendous_disabled_error();

                return $result;
            }

            if ( ! $this->tremendous_client || ! $this->tremendous_client->is_configured() ) {
                $result['ok']    = false;
                $result['error'] = new WP_Error( 'ecosplay_referrals_tremendous_unconfigured', __( 'Configurez l’accès Tremendous avant de poursuivre.', 'ecosplay-referrals' ) );

                return $result;
            }

            $balance = $this->tremendous_client->get_funding_source_balance();

            if ( is_wp_error( $balance ) ) {
                $result['ok']    = false;
                $result['error'] = $balance;

                return $result;
            }

            if ( isset( $balance['available'] ) ) {
                $result['available'] = max( 0.0, (float) $balance['available'] );
            }

            if ( isset( $balance['currency'] ) && '' !== $balance['currency'] ) {
                $result['currency'] = strtolower( (string) $balance['currency'] );
            }

            if ( isset( $balance['funding_source_id'] ) && '' !== $balance['funding_source_id'] ) {
                $result['funding_source_id'] = (string) $balance['funding_source_id'];
            }

            if ( isset( $balance['method'] ) && '' !== $balance['method'] ) {
                $result['funding_source_method'] = (string) $balance['method'];
            }

            if ( isset( $balance['funding_source'] ) ) {
                $result['funding_source'] = $balance['funding_source'];
            }

            $result['raw'] = isset( $balance['raw'] ) ? $balance['raw'] : $balance;
            $result['ok']  = $result['available'] >= $expected;

            return $result;
        }

        if ( ! $this->is_stripe_enabled() ) {
            $result['ok']    = false;
            $result['error'] = $this->stripe_disabled_error();

            return $result;
        }

        if ( ! $this->stripe_client->is_configured() ) {
            $result['ok']    = false;
            $result['error'] = new WP_Error( 'ecosplay_referrals_stripe_missing_secret', __( 'Configurez la clé Stripe avant de poursuivre.', 'ecosplay-referrals' ) );

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

                $amount               = isset( $entry['amount'] ) ? floatval( $entry['amount'] ) : 0.0;
                $result['available']  = round( $amount / 100, 2 );
                break;
            }
        }

        $result['raw'] = $response;
        $result['ok']  = $result['available'] >= $expected;

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

        if ( ! $this->is_stripe_enabled() ) {
            return $this->stripe_disabled_error();
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

        if ( $this->is_stripe_enabled() ) {
            $balance_status = $this->check_platform_balance( $reward_amount, $currency, 'stripe' );

            if ( ! $balance_status['ok'] ) {
                $this->handle_balance_alert(
                    'reward',
                    $balance_status,
                    array(
                        'user_id'     => (int) $referral->user_id,
                        'referral_id' => (int) $referral->id,
                        'order_id'    => $order_id,
                        'code'        => $code,
                        'provider'    => 'stripe',
                    )
                );
            }
        }

        if ( $this->is_tremendous_enabled() ) {
            $tremendous_status = $this->check_platform_balance( $reward_amount, $currency, 'tremendous' );

            if ( ! $tremendous_status['ok'] ) {
                $this->handle_balance_alert(
                    'reward',
                    $tremendous_status,
                    array(
                        'user_id'     => (int) $referral->user_id,
                        'referral_id' => (int) $referral->id,
                        'order_id'    => $order_id,
                        'code'        => $code,
                        'provider'    => 'tremendous',
                    )
                );
            }
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
     * Fetches webhook logs for a given provider.
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
     * @param string $provider Optional provider filter.
     *
     * @return array<int,string>
     */
    public function get_webhook_event_types( $provider = '' ) {
        return $this->store->get_webhook_event_types( $provider );
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
     * Déclenche la vérification quotidienne planifiée des soldes de paiement.
     *
     * @return void
     */
    public function run_daily_balance_check() {
        $threshold = $this->get_balance_alert_threshold();

        if ( $threshold <= 0 ) {
            return;
        }

        $providers = array();

        if ( $this->is_stripe_enabled() ) {
            $providers[] = 'stripe';
        }

        if ( $this->is_tremendous_enabled() ) {
            $providers[] = 'tremendous';
        }

        if ( empty( $providers ) ) {
            return;
        }

        foreach ( $providers as $provider ) {
            $status = $this->check_platform_balance( $threshold, self::DEFAULT_CURRENCY, $provider );

            if ( $status['ok'] ) {
                continue;
            }

            $this->handle_balance_alert(
                'cron',
                $status,
                array(
                    'threshold' => $threshold,
                    'provider'  => $provider,
                )
            );
        }
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

        $cache_key = $this->get_notice_cache_key( $user_id );
        $found     = false;
        $cached    = $this->get_notice_cache_value( $cache_key, $found );

        if ( $found ) {
            return (bool) $cached;
        }

        $seen = $this->store->has_seen_notification( $user_id );

        $this->set_notice_cache_value( $cache_key, $seen ? 1 : 0 );

        return $seen;
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

        $updated = $this->store->mark_notification_seen( $user_id );

        $this->clear_notice_cache( $user_id );

        if ( $updated ) {
            $this->set_notice_cache_value( $this->get_notice_cache_key( $user_id ), 1 );
        }

        return $updated;
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
            $previous_version = $this->get_notice_version();
            $this->bump_notice_version();
            $this->delete_notice_cache_value( $this->get_notice_cache_key( (int) get_current_user_id(), $previous_version ) );
        } else {
            $this->clear_notice_cache( (int) $user_id );
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
        $default = self::DEFAULT_NOTICE_MESSAGE;

        if ( '' !== $default ) {
            $default = __( $default, 'ecosplay-referrals' );
        }

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
     * Builds a cache key for the notice dismissal state.
     *
     * @param int      $user_id User identifier.
     * @param int|null $version Optional cache version override.
     *
     * @return string
     */
    protected function get_notice_cache_key( $user_id, $version = null ) {
        $version = null === $version ? $this->get_notice_version() : (int) $version;

        return sprintf( 'ecos_ref_notice_seen_%s_%d_%d', self::NOTICE_CACHE_SLUG, (int) $user_id, $version );
    }

    /**
     * Reads a cached value for the notice dismissal state.
     *
     * @param string $key   Cache key.
     * @param bool   $found Whether the value exists.
     *
     * @return mixed
     */
    protected function get_notice_cache_value( $key, &$found ) {
        if ( wp_using_ext_object_cache() ) {
            return wp_cache_get( $key, self::NOTICE_CACHE_GROUP, false, $found );
        }

        $value = get_transient( $key );
        $found = false !== $value;

        return $value;
    }

    /**
     * Stores a cached value for the notice dismissal state.
     *
     * @param string $key   Cache key.
     * @param mixed  $value Cache value.
     *
     * @return void
     */
    protected function set_notice_cache_value( $key, $value ) {
        if ( wp_using_ext_object_cache() ) {
            wp_cache_set( $key, $value, self::NOTICE_CACHE_GROUP, self::NOTICE_CACHE_TTL );
            return;
        }

        set_transient( $key, $value, self::NOTICE_CACHE_TTL );
    }

    /**
     * Removes a cached value for the notice dismissal state.
     *
     * @param string $key Cache key.
     *
     * @return void
     */
    protected function delete_notice_cache_value( $key ) {
        if ( wp_using_ext_object_cache() ) {
            wp_cache_delete( $key, self::NOTICE_CACHE_GROUP );
            return;
        }

        delete_transient( $key );
    }

    /**
     * Clears cached dismissal data for a given user.
     *
     * @param int $user_id User identifier.
     *
     * @return void
     */
    protected function clear_notice_cache( $user_id ) {
        $this->delete_notice_cache_value( $this->get_notice_cache_key( $user_id ) );
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
     * Fournit le statut Stripe Connect pour un membre donné.
     *
     * @param int $user_id Identifiant du membre.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function get_member_stripe_status( $user_id ) {
        $user_id = (int) $user_id;

        if ( $user_id <= 0 || ! $this->is_user_allowed( $user_id ) ) {
            return new WP_Error( 'ecosplay_referrals_stripe_forbidden', __( 'Accès refusé au statut Stripe.', 'ecosplay-referrals' ) );
        }

        $referral = $this->store->get_referral_by_user( $user_id );

        if ( ! $referral ) {
            return new WP_Error( 'ecosplay_referrals_wallet_missing', __( 'Profil de parrainage introuvable.', 'ecosplay-referrals' ) );
        }

        $account_id = ! empty( $referral->stripe_account_id ) ? (string) $referral->stripe_account_id : '';
        $errors     = array();

        if ( ! $this->is_stripe_enabled() ) {
            $message = $this->stripe_disabled_error()->get_error_message();

            return array(
                'stripe_status'          => 'disabled',
                'stripe_label'           => $message,
                'stripe_errors'          => array( $message ),
                'stripe_account_id'      => $account_id,
                'stripe_account_missing' => '' === $account_id,
            );
        }

        if ( ! $this->stripe_client->is_configured() ) {
            $message = __( 'Configurez la clé Stripe avant de poursuivre.', 'ecosplay-referrals' );

            return array(
                'stripe_status'          => 'unconfigured',
                'stripe_label'           => $message,
                'stripe_errors'          => array( $message ),
                'stripe_account_id'      => $account_id,
                'stripe_account_missing' => '' === $account_id,
            );
        }

        $capabilities    = $this->decode_capabilities_field( isset( $referral->stripe_capabilities ) ? $referral->stripe_capabilities : '' );
        $transfers_status = isset( $capabilities['transfers'] ) ? $capabilities['transfers'] : '';
        $account          = null;

        if ( '' !== $account_id ) {
            $account = $this->stripe_client->retrieve_account( $account_id );

            if ( is_wp_error( $account ) ) {
                $errors[] = $account->get_error_message();
                $account  = null;
            } elseif ( is_array( $account ) ) {
                if ( isset( $account['capabilities'] ) && is_array( $account['capabilities'] ) ) {
                    $capabilities     = $account['capabilities'];
                    $transfers_status = isset( $capabilities['transfers'] ) ? $capabilities['transfers'] : $transfers_status;
                    $this->store->save_stripe_account( $user_id, $account_id, $capabilities );
                }

                $errors = array_merge( $errors, $this->extract_requirement_messages( $account ) );
            }
        }

        if ( is_array( $transfers_status ) ) {
            if ( ! empty( $transfers_status['active'] ) ) {
                $transfers_status = 'active';
            } elseif ( ! empty( $transfers_status['pending'] ) ) {
                $transfers_status = 'pending';
            } else {
                $transfers_status = '';
            }
        }

        $state = $this->determine_kyc_state( $referral, $transfers_status, $account );
        $errors = array_values( array_unique( array_filter( array_map( 'wp_strip_all_tags', $errors ) ) ) );

        return array(
            'stripe_status'          => $state['status'],
            'stripe_label'           => $state['label'],
            'stripe_errors'          => $errors,
            'stripe_account_id'      => $account_id,
            'stripe_account_missing' => '' === $account_id,
        );
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

        $currency = apply_filters( 'ecosplay_referrals_wallet_currency', 'EUR', $referral, $this );
        $limit    = (int) apply_filters( 'ecosplay_referrals_wallet_payout_limit', 10, $referral, $this );

        $earned      = isset( $referral->earned_credits ) ? (float) $referral->earned_credits : 0.0;
        $total_paid  = isset( $referral->total_paid ) ? (float) $referral->total_paid : 0.0;
        $available   = max( 0, $earned - $total_paid );
        $tremendous_enabled = $this->is_tremendous_enabled();

        $wallet_base = array(
            'earned_credits'     => $earned,
            'total_paid'         => $total_paid,
            'available_balance'  => $available,
            'currency'           => $currency,
            'payouts'            => $this->store->get_member_payouts( $user_id, max( 1, $limit ) ),
            'tremendous_enabled' => $tremendous_enabled,
            'association_label'  => __( 'Associez votre compte Tremendous pour demander des récompenses.', 'ecosplay-referrals' ),
            'association_status' => 'unlinked',
            'association_errors' => array(),
            'is_associated'      => false,
            'can_request_reward' => false,
            'tremendous_balance' => null,
        );

        if ( $tremendous_enabled ) {
            $state = $this->sync_tremendous_state( $referral );

            if ( is_wp_error( $state ) ) {
                return $state;
            }

            if ( null !== $state['balance'] ) {
                $wallet_base['tremendous_balance'] = max( 0, (float) $state['balance'] );
                $wallet_base['available_balance']   = min( $wallet_base['available_balance'], $wallet_base['tremendous_balance'] );
            }

            $wallet_base['association_label']  = $state['label'];
            $wallet_base['association_status'] = $state['status'];
            $wallet_base['association_errors'] = $state['errors'];
            $wallet_base['is_associated']      = ! empty( $state['organization_id'] );
            $wallet_base['can_request_reward'] = $this->is_tremendous_ready_status( $state['status'] );
        } else {
            if ( ! $this->is_stripe_enabled() ) {
                $wallet_base['association_label']  = __( 'Aucune solution de récompense n’est disponible.', 'ecosplay-referrals' );
                $wallet_base['association_errors'] = array();
            } else {
                $wallet_base['association_errors'] = array( __( 'Activez Tremendous pour permettre les récompenses.', 'ecosplay-referrals' ) );
            }
        }

        $wallet_base['can_transfer'] = $wallet_base['can_request_reward'];
        $stripe_status               = $this->get_member_stripe_status( $user_id );

        if ( ! is_wp_error( $stripe_status ) ) {
            $wallet_base = array_merge( $wallet_base, $stripe_status );
        }

        return $wallet_base;
    }

    /**
     * Démarre ou vérifie l’association Tremendous pour un membre.
     *
     * @param int $user_id Identifiant du membre.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function associate_tremendous_account( $user_id ) {
        $user_id = (int) $user_id;

        if ( $user_id <= 0 || ! $this->is_user_allowed( $user_id ) ) {
            return new WP_Error( 'ecosplay_referrals_tremendous_forbidden', __( 'Accès refusé pour Tremendous.', 'ecosplay-referrals' ) );
        }

        if ( ! $this->is_tremendous_enabled() ) {
            return $this->tremendous_disabled_error();
        }

        $referral = $this->store->get_referral_by_user( $user_id );

        if ( ! $referral ) {
            return new WP_Error( 'ecosplay_referrals_wallet_missing', __( 'Profil de parrainage introuvable.', 'ecosplay-referrals' ) );
        }

        if ( empty( $referral->tremendous_organization_id ) ) {
            if ( ! $this->tremendous_client || ! $this->tremendous_client->is_configured() ) {
                return new WP_Error( 'ecosplay_referrals_tremendous_unconfigured', __( 'Configurez l’accès Tremendous avant de poursuivre.', 'ecosplay-referrals' ) );
            }

            $user = get_userdata( $user_id );

            if ( ! $user ) {
                return new WP_Error( 'ecosplay_referrals_missing_user', __( 'Utilisateur introuvable pour Tremendous.', 'ecosplay-referrals' ) );
            }

            $payload = array(
                'name'        => $user->display_name ? $user->display_name : $user->user_login,
                'email'       => $user->user_email,
                'external_id' => (string) $referral->id,
            );

            $payload = apply_filters( 'ecosplay_referrals_tremendous_association_payload', $payload, $user_id, $referral, $this );

            $response = $this->tremendous_client->create_connected_organization( $payload );

            if ( is_wp_error( $response ) ) {
                return $response;
            }

            $data = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : $response;

            $organization_id = isset( $data['id'] ) ? (string) $data['id'] : '';
            $status          = isset( $data['status'] ) ? strtolower( (string) $data['status'] ) : 'pending';
            $message         = '';

            if ( isset( $data['status_reason'] ) ) {
                $message = (string) $data['status_reason'];
            } elseif ( isset( $data['status_message'] ) ) {
                $message = (string) $data['status_message'];
            }

            if ( '' === $organization_id ) {
                return new WP_Error( 'ecosplay_referrals_tremendous_missing_id', __( 'Tremendous n’a pas renvoyé d’identifiant d’association.', 'ecosplay-referrals' ) );
            }

            $this->store->save_tremendous_state(
                $user_id,
                array(
                    'tremendous_organization_id' => $organization_id,
                    'tremendous_status'          => $status,
                    'tremendous_status_message'  => $message,
                )
            );

            $referral = $this->store->get_referral_by_user( $user_id );
        }

        $state = $this->sync_tremendous_state( $referral );

        if ( ! is_wp_error( $state ) ) {
            do_action( 'ecosplay_referrals_tremendous_associated', $user_id, $state, $referral, $this );
        }

        return $state;
    }

    /**
     * Rafraîchit le statut Tremendous pour un membre.
     *
     * @param int $user_id Identifiant du membre.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function refresh_tremendous_account( $user_id ) {
        $user_id = (int) $user_id;

        if ( $user_id <= 0 || ! $this->is_user_allowed( $user_id ) ) {
            return new WP_Error( 'ecosplay_referrals_tremendous_forbidden', __( 'Accès refusé pour Tremendous.', 'ecosplay-referrals' ) );
        }

        if ( ! $this->is_tremendous_enabled() ) {
            return $this->tremendous_disabled_error();
        }

        $referral = $this->store->get_referral_by_user( $user_id );

        if ( ! $referral ) {
            return new WP_Error( 'ecosplay_referrals_wallet_missing', __( 'Profil de parrainage introuvable.', 'ecosplay-referrals' ) );
        }

        return $this->sync_tremendous_state( $referral );
    }

    /**
     * Soumet une demande de récompense Tremendous pour un membre.
     *
     * @param int                 $user_id  Identifiant du membre.
     * @param float               $amount   Montant souhaité.
     * @param array<string,mixed> $metadata Métadonnées additionnelles.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function request_tremendous_reward( $user_id, $amount, array $metadata = array() ) {
        $user_id = (int) $user_id;
        $amount  = (float) $amount;

        if ( $user_id <= 0 || ! $this->is_user_allowed( $user_id ) ) {
            return new WP_Error( 'ecosplay_referrals_tremendous_forbidden', __( 'Accès refusé pour Tremendous.', 'ecosplay-referrals' ) );
        }

        if ( $amount <= 0 ) {
            return new WP_Error( 'ecosplay_referrals_invalid_amount', __( 'Veuillez indiquer un montant valide.', 'ecosplay-referrals' ) );
        }

        if ( ! $this->is_tremendous_enabled() ) {
            return $this->tremendous_disabled_error();
        }

        if ( ! $this->tremendous_client || ! $this->tremendous_client->is_configured() ) {
            return new WP_Error( 'ecosplay_referrals_tremendous_unconfigured', __( 'Configurez l’accès Tremendous avant de poursuivre.', 'ecosplay-referrals' ) );
        }

        $referral = $this->store->get_referral_by_user( $user_id );

        if ( ! $referral ) {
            return new WP_Error( 'ecosplay_referrals_wallet_missing', __( 'Profil de parrainage introuvable.', 'ecosplay-referrals' ) );
        }

        $state = $this->sync_tremendous_state( $referral );

        if ( is_wp_error( $state ) ) {
            return $state;
        }

        if ( empty( $state['organization_id'] ) ) {
            return new WP_Error( 'ecosplay_referrals_tremendous_unlinked', __( 'Associez votre compte Tremendous avant de demander une récompense.', 'ecosplay-referrals' ) );
        }

        if ( ! $this->is_tremendous_ready_status( $state['status'] ) ) {
            return new WP_Error( 'ecosplay_referrals_tremendous_pending', __( 'Votre compte Tremendous doit être validé avant de demander une récompense.', 'ecosplay-referrals' ) );
        }

        $available_local = max( 0, (float) $referral->earned_credits - (float) $referral->total_paid );

        if ( $amount > $available_local ) {
            return new WP_Error( 'ecosplay_referrals_insufficient_balance', __( 'Le montant demandé dépasse votre solde disponible.', 'ecosplay-referrals' ) );
        }

        if ( null !== $state['balance'] && $amount > (float) $state['balance'] ) {
            return new WP_Error( 'ecosplay_referrals_remote_balance', __( 'Le solde Tremendous disponible est insuffisant.', 'ecosplay-referrals' ) );
        }

        $user = get_userdata( $user_id );

        if ( ! $user ) {
            return new WP_Error( 'ecosplay_referrals_missing_user', __( 'Utilisateur introuvable pour Tremendous.', 'ecosplay-referrals' ) );
        }

        $currency = apply_filters( 'ecosplay_referrals_tremendous_currency', 'EUR', $referral, $this );

        $order_payload = array(
            'campaign_id' => $this->tremendous_client->get_campaign_id(),
            'payment'     => array(
                'amount'   => round( $amount, 2 ),
                'currency' => strtoupper( $currency ),
            ),
            'recipients'  => array(
                array(
                    'name'       => $user->display_name ? $user->display_name : $user->user_login,
                    'email'      => $user->user_email,
                    'identifier' => (string) $referral->id,
                ),
            ),
        );

        if ( ! empty( $metadata ) ) {
            $order_payload['metadata'] = $metadata;
        }

        $order_payload = apply_filters( 'ecosplay_referrals_tremendous_order_payload', $order_payload, $user_id, $amount, $referral, $metadata, $this );

        $response = $this->tremendous_client->create_order( $order_payload );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $data     = isset( $response['data'] ) && is_array( $response['data'] ) ? $response['data'] : $response;
        $order_id = isset( $data['id'] ) ? (string) $data['id'] : '';

        $this->store->record_payout_event(
            array(
                'user_id'     => $user_id,
                'referral_id' => (int) $referral->id,
                'amount'      => $amount,
                'currency'    => strtoupper( $currency ),
                'status'      => 'requested',
                'transfer_id' => $order_id,
                'metadata'    => array_merge( array( 'provider' => 'tremendous' ), $metadata ),
            )
        );

        $this->store->increment_total_paid( (int) $referral->id, $amount );

        $updated_referral = $this->store->get_referral_by_user( $user_id );

        if ( $updated_referral ) {
            $this->sync_tremendous_state( $updated_referral );
        }

        do_action( 'ecosplay_referrals_tremendous_reward_requested', $user_id, $response, $amount, $currency, $metadata, $this );

        return $response;
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
     * Synchronise l’état Tremendous en interrogeant l’API distante.
     *
     * @param object $referral Enregistrement de parrainage.
     *
     * @return array<string,mixed>|WP_Error
     */
    protected function sync_tremendous_state( $referral ) {
        if ( ! is_object( $referral ) || empty( $referral->user_id ) ) {
            return new WP_Error( 'ecosplay_referrals_wallet_missing', __( 'Profil de parrainage introuvable.', 'ecosplay-referrals' ) );
        }

        $user_id               = (int) $referral->user_id;
        $organization_id       = isset( $referral->tremendous_organization_id ) ? (string) $referral->tremendous_organization_id : '';
        $status                = isset( $referral->tremendous_status ) ? (string) $referral->tremendous_status : '';
        $message               = isset( $referral->tremendous_status_message ) ? (string) $referral->tremendous_status_message : '';
        $balance               = isset( $referral->tremendous_balance ) ? $referral->tremendous_balance : null;
        $funding_source_id     = '';
        $funding_source_method = '';
        $errors                = array();

        if ( '' === $organization_id ) {
            return array(
                'status'               => 'unlinked',
                'label'                => $this->format_tremendous_status_label( 'unlinked' ),
                'errors'               => array(),
                'balance'              => null,
                'organization_id'      => '',
                'funding_source_id'    => '',
                'funding_source_method'=> '',
            );
        }

        if ( ! $this->tremendous_client || ! $this->tremendous_client->is_configured() ) {
            if ( '' !== $message ) {
                $errors[] = $message;
            }
            $errors[] = __( 'La clé Tremendous n’est pas configurée.', 'ecosplay-referrals' );

            return array(
                'status'               => $status ? $status : 'unconfigured',
                'label'                => $this->format_tremendous_status_label( $status ? $status : 'unconfigured' ),
                'errors'               => array_values( array_unique( array_filter( array_map( 'wp_strip_all_tags', $errors ) ) ) ),
                'balance'              => is_numeric( $balance ) ? (float) $balance : null,
                'organization_id'      => $organization_id,
                'funding_source_id'    => '',
                'funding_source_method'=> '',
            );
        }

        $details = $this->tremendous_client->get_connected_organization( $organization_id );

        if ( is_wp_error( $details ) ) {
            $error_message = wp_strip_all_tags( $details->get_error_message() );
            $errors[]      = $error_message;
            $this->store->save_tremendous_state( $user_id, array( 'tremendous_status_message' => $error_message ) );

            $status = $status ? $status : 'error';

            return array(
                'status'               => $status,
                'label'                => $this->format_tremendous_status_label( $status ),
                'errors'               => array_values( array_unique( array_filter( array_map( 'wp_strip_all_tags', $errors ) ) ) ),
                'balance'              => is_numeric( $balance ) ? (float) $balance : null,
                'organization_id'      => $organization_id,
                'funding_source_id'    => '',
                'funding_source_method'=> '',
            );
        }

        $data   = isset( $details['data'] ) && is_array( $details['data'] ) ? $details['data'] : $details;
        $status = isset( $data['status'] ) ? strtolower( (string) $data['status'] ) : 'pending';
        $message = '';

        if ( isset( $data['status_reason'] ) ) {
            $message = (string) $data['status_reason'];
        } elseif ( isset( $data['status_message'] ) ) {
            $message = (string) $data['status_message'];
        } elseif ( isset( $data['message'] ) ) {
            $message = (string) $data['message'];
        }

        $this->store->save_tremendous_state(
            $user_id,
            array(
                'tremendous_status'         => $status,
                'tremendous_status_message' => $message,
            )
        );

        $balance_response = $this->tremendous_client->get_funding_source_balance();

        if ( is_wp_error( $balance_response ) ) {
            $errors[] = $balance_response->get_error_message();
        } else {
            if ( isset( $balance_response['available'] ) && is_numeric( $balance_response['available'] ) ) {
                $balance = (float) $balance_response['available'];
                $this->store->save_tremendous_state( $user_id, array( 'tremendous_balance' => $balance ) );
            }

            if ( isset( $balance_response['funding_source_id'] ) ) {
                $funding_source_id = (string) $balance_response['funding_source_id'];
            }

            if ( isset( $balance_response['method'] ) ) {
                $funding_source_method = (string) $balance_response['method'];
            }
        }

        if ( '' !== $message && ! $this->is_tremendous_ready_status( $status ) ) {
            $errors[] = $message;
        }

        return array(
            'status'               => $status,
            'label'                => $this->format_tremendous_status_label( $status ),
            'errors'               => array_values( array_unique( array_filter( array_map( 'wp_strip_all_tags', $errors ) ) ) ),
            'balance'              => is_numeric( $balance ) ? (float) $balance : null,
            'organization_id'      => $organization_id,
            'funding_source_id'    => $funding_source_id,
            'funding_source_method'=> $funding_source_method,
        );
    }

    /**
     * Indique si le statut Tremendous autorise les récompenses.
     *
     * @param string $status Statut brut.
     *
     * @return bool
     */
    protected function is_tremendous_ready_status( $status ) {
        $status = strtolower( (string) $status );

        return in_array( $status, array( 'approved', 'active', 'registered' ), true );
    }

    /**
     * Génère un libellé lisible pour le statut Tremendous.
     *
     * @param string $status Statut brut.
     *
     * @return string
     */
    protected function format_tremendous_status_label( $status ) {
        switch ( strtolower( (string) $status ) ) {
            case 'approved':
            case 'active':
            case 'registered':
                return __( 'Votre compte Tremendous est validé.', 'ecosplay-referrals' );
            case 'pending':
            case 'review':
            case 'submitted':
                return __( 'Votre compte Tremendous est en cours de validation.', 'ecosplay-referrals' );
            case 'unconfigured':
                return __( 'Configurez Tremendous pour poursuivre.', 'ecosplay-referrals' );
            case 'unlinked':
                return __( 'Associez votre compte Tremendous pour demander des récompenses.', 'ecosplay-referrals' );
            default:
                return __( 'Le statut Tremendous nécessite une vérification.', 'ecosplay-referrals' );
        }
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
     * Builds a standardized error when Stripe has been disabled.
     *
     * @return WP_Error
     */
    protected function stripe_disabled_error() {
        return new WP_Error( self::STRIPE_DISABLED_ERROR, __( 'L’intégration Stripe est désactivée.', 'ecosplay-referrals' ) );
    }

    /**
     * Builds a standardized error when Tremendous has been disabled.
     *
     * @return WP_Error
     */
    protected function tremendous_disabled_error() {
        return new WP_Error( self::TREMENDOUS_DISABLED_ERROR, __( 'L’intégration Tremendous est désactivée.', 'ecosplay-referrals' ) );
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

        if ( $user_id <= 0 ) {
            return false;
        }

        $levels = $this->get_allowed_level_ids();

        if ( empty( $levels ) ) {
            return false;
        }

        if ( function_exists( 'pmpro_getMembershipLevelsForUser' ) ) {
            $user_levels = pmpro_getMembershipLevelsForUser( $user_id );

            if ( ! empty( $user_levels ) ) {
                foreach ( $user_levels as $level ) {
                    if ( $this->level_matches_allowed( $level, $levels ) ) {
                        return true;
                    }
                }

                return false;
            }
        }

        if ( ! function_exists( 'pmpro_hasMembershipLevel' ) ) {
            return false;
        }

        $normalized_levels = $this->normalize_allowed_levels( $levels );

        if ( ! empty( $normalized_levels['ids'] ) && pmpro_hasMembershipLevel( $normalized_levels['ids'], $user_id ) ) {
            return true;
        }

        if ( ! empty( $normalized_levels['slugs'] ) && pmpro_hasMembershipLevel( $normalized_levels['slugs'], $user_id ) ) {
            return true;
        }

        return false;
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

        $allowed    = $this->normalize_allowed_levels( $allowed_levels );
        $references = $this->extract_level_references( $level );

        foreach ( $references['ids'] as $id ) {
            if ( in_array( $id, $allowed['ids'], true ) ) {
                return true;
            }
        }

        foreach ( $references['slugs'] as $slug ) {
            if ( '' !== $slug && in_array( $slug, $allowed['slugs'], true ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Builds ID/slug collections from the allowed levels configuration.
     *
     * @param array<mixed> $allowed_levels Raw allowed level values.
     *
     * @return array{ids: array<int>, slugs: array<string>}
     */
    private function normalize_allowed_levels( array $allowed_levels ) {
        $ids   = array();
        $slugs = array();

        foreach ( $allowed_levels as $allowed_level ) {
            $references = $this->extract_level_references( $allowed_level );

            if ( ! empty( $references['ids'] ) ) {
                $ids = array_merge( $ids, $references['ids'] );
            }

            if ( ! empty( $references['slugs'] ) ) {
                $slugs = array_merge( $slugs, $references['slugs'] );
            }
        }

        $ids   = array_values( array_unique( $ids ) );
        $slugs = array_values( array_unique( array_filter( $slugs ) ) );

        return array(
            'ids'   => $ids,
            'slugs' => $slugs,
        );
    }

    /**
     * Extracts membership IDs and slugs from a level reference.
     *
     * @param mixed $level Level reference from PMPro or configuration.
     *
     * @return array{ids: array<int>, slugs: array<string>}
     */
    private function extract_level_references( $level ) {
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
            $key = sanitize_key( $level );

            if ( '' !== $key ) {
                if ( preg_match( '/^pmpro_role_(\d+)$/', $key, $matches ) ) {
                    $ids[] = (int) $matches[1];
                } else {
                    $slugs[] = $key;
                }
            }
        }

        return array(
            'ids'   => array_values( array_unique( $ids ) ),
            'slugs' => array_values( array_unique( array_filter( $slugs ) ) ),
        );
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
     * Consigne et alerte lorsqu’un déficit de solde est détecté.
     *
     * @param string              $source  Origine de la vérification (reward, transfer, cron).
     * @param array<string,mixed> $status  Statut retourné par check_platform_balance().
     * @param array<string,mixed> $context Informations additionnelles pour le log.
     *
     * @return void
     */
    protected function handle_balance_alert( $source, array $status, array $context = array() ) {
        $provider = isset( $status['provider'] ) ? strtolower( (string) $status['provider'] ) : '';

        if ( isset( $context['provider'] ) && '' === $provider ) {
            $provider = strtolower( (string) $context['provider'] );
        }

        if ( '' === $provider ) {
            $provider = 'stripe';
        }

        if ( isset( $status['error'] ) && $status['error'] instanceof WP_Error ) {
            $code = $status['error']->get_error_code();

            if ( in_array( $code, array( self::STRIPE_DISABLED_ERROR, self::TREMENDOUS_DISABLED_ERROR ), true ) ) {
                return;
            }
        }

        $available = isset( $status['available'] ) ? (float) $status['available'] : 0.0;
        $required  = isset( $status['required'] ) ? (float) $status['required'] : 0.0;
        $currency  = isset( $status['currency'] ) ? strtoupper( (string) $status['currency'] ) : strtoupper( self::DEFAULT_CURRENCY );

        if ( isset( $status['funding_source_id'] ) ) {
            $context['funding_source_id'] = (string) $status['funding_source_id'];
        }

        if ( isset( $status['funding_source_method'] ) ) {
            $context['funding_source_method'] = (string) $status['funding_source_method'];
        }

        $payload = array_merge(
            $context,
            array(
                'source'    => $source,
                'required'  => $required,
                'available' => $available,
                'currency'  => $currency,
                'provider'  => $provider,
                'timestamp' => current_time( 'mysql' ),
            )
        );

        $status_label = 'insufficient';

        if ( isset( $status['error'] ) && $status['error'] instanceof WP_Error ) {
            $payload['error_code']    = $status['error']->get_error_code();
            $payload['error_message'] = $status['error']->get_error_message();
            $status_label             = 'error';
        }

        $this->store->log_webhook_event( 'balance_alert', $status_label, $payload, $provider );

        $subject = sprintf(
            /* translators: 1: provider name. 2: currency code. */
            __( '[ECOSplay] Alerte solde %1$s (%2$s)', 'ecosplay-referrals' ),
            ucfirst( $provider ),
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

        if ( 'tremendous' === $provider ) {
            if ( isset( $context['funding_source_method'] ) && '' !== (string) $context['funding_source_method'] ) {
                $lines[] = sprintf( __( 'Méthode Tremendous : %s', 'ecosplay-referrals' ), $context['funding_source_method'] );
            }

            if ( isset( $context['funding_source_id'] ) && '' !== (string) $context['funding_source_id'] ) {
                $lines[] = sprintf( __( 'Funding source ID : %s', 'ecosplay-referrals' ), $context['funding_source_id'] );
            }
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
