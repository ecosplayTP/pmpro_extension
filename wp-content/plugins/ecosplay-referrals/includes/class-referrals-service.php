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

    /**
     * Storage layer implementation.
     *
     * @var Ecosplay_Referrals_Store
     */
    protected $store;

    /**
     * Wires hooks and stores dependencies.
     *
     * @param Ecosplay_Referrals_Store $store Persistence facade.
     */
    public function __construct( Ecosplay_Referrals_Store $store ) {
        $this->store = $store;

        add_action( 'user_register', array( $this, 'ensure_user_code' ), 10, 1 );
        add_action( 'pmpro_checkout_boxes', array( $this, 'render_checkout_field' ) );
        add_filter( 'pmpro_registration_checks', array( $this, 'validate_referral_code' ) );
        add_filter( 'pmpro_checkout_level', array( $this, 'apply_referral_discount' ) );
        add_action( 'pmpro_after_checkout', array( $this, 'award_referral_rewards' ), 10, 2 );
        add_action( 'init', array( $this, 'prefill_from_query' ) );
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

        if ( $user_id <= 0 ) {
            return;
        }

        $existing = $this->store->get_referral_by_user( $user_id );

        if ( $existing && ! empty( $existing->code ) ) {
            return;
        }

        $this->store->regenerate_code( $user_id );
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
     * Exposes a snapshot of referral codes for admin use.
     *
     * @param bool $only_active Whether to limit to active codes.
     *
     * @return array<int,object>
     */
    public function get_codes_overview( $only_active = true ) {
        return $this->store->get_active_codes( $only_active );
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

        if ( $user_id <= 0 ) {
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
        return null !== $level;
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
}
