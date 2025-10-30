<?php
/**
 * Front-end floating notice controller.
 *
 * @package Ecosplay\Referrals
 * @file    wp-content/plugins/ecosplay-referrals/public/class-floating-notice.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Displays and manages the floating referral notice on the front-end.
 */
class Ecosplay_Referrals_Floating_Notice {
    const COOKIE_NAME   = 'ecos_referrals_notice_seen';
    const AJAX_ACTION   = 'ecosplay_referrals_notice_dismiss';
    const NONCE_ACTION  = 'ecosplay_referrals_notice';
    const COOKIE_TTL    = WEEK_IN_SECONDS;

    /**
     * Referrals service dependency.
     *
     * @var Ecosplay_Referrals_Service
     */
    protected $service;

    /**
     * Cached decision on whether to render the notice.
     *
     * @var bool|null
     */
    protected $should_render = null;

    /**
     * Wires hooks necessary for displaying the floating notice.
     *
     * @param Ecosplay_Referrals_Service $service Domain service instance.
     */
    public function __construct( Ecosplay_Referrals_Service $service ) {
        $this->service = $service;

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_footer', array( $this, 'render_notice' ) );
        add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_dismiss' ) );
        add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'handle_dismiss' ) );
    }

    /**
     * Enqueues the front-end assets when the notice is visible.
     *
     * @return void
     */
    public function enqueue_assets() {
        if ( ! $this->should_display_notice() ) {
            return;
        }

        wp_enqueue_style(
            'ecosplay-referrals-floating-notice',
            ECOSPLAY_REFERRALS_URL . 'assets/css/floating-notice.css',
            array(),
            ECOSPLAY_REFERRALS_VERSION
        );

        wp_enqueue_script(
            'ecosplay-referrals-floating-notice',
            ECOSPLAY_REFERRALS_URL . 'assets/js/floating-notice.js',
            array(),
            ECOSPLAY_REFERRALS_VERSION,
            true
        );

        wp_localize_script(
            'ecosplay-referrals-floating-notice',
            'ecosplayFloatingNotice',
            array(
                'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
                'action'     => self::AJAX_ACTION,
                'nonce'      => wp_create_nonce( self::NONCE_ACTION ),
                'cookieName' => self::COOKIE_NAME,
            )
        );
    }

    /**
     * Outputs the floating notice HTML in the footer when required.
     *
     * @return void
     */
    public function render_notice() {
        if ( ! $this->should_display_notice() ) {
            return;
        }
        ?>
        <div class="ecosplay-floating-notice" role="dialog" aria-live="polite" aria-label="<?php esc_attr_e( 'Notification de parrainage', 'ecosplay-referrals' ); ?>">
            <div class="ecosplay-floating-notice__body">
                <p class="ecosplay-floating-notice__text">
                    <?php esc_html_e( 'Parrainez vos amis pour cumuler des récompenses ECOSplay.', 'ecosplay-referrals' ); ?>
                </p>
                <button type="button" class="ecosplay-floating-notice__close" data-ecosplay-close aria-label="<?php esc_attr_e( 'Fermer la notification', 'ecosplay-referrals' ); ?>">
                    &times;
                </button>
            </div>
        </div>
        <?php
    }

    /**
     * Handles the dismissal AJAX request from the front-end script.
     *
     * @return void
     */
    public function handle_dismiss() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        $user_id = get_current_user_id();

        if ( $user_id > 0 ) {
            $this->service->mark_notification_seen( $user_id );
        }

        $this->set_notice_cookie();

        wp_send_json_success( array( 'status' => 'dismissed' ) );
    }

    /**
     * Resets the dismissal state for one member or globally.
     *
     * @param int|null $user_id Optional user identifier.
     *
     * @return bool
     */
    public function reset_notice_state( $user_id = null ) {
        return $this->service->reset_notifications( $user_id );
    }

    /**
     * Determines whether the notice needs to be displayed.
     *
     * @return bool
     */
    protected function should_display_notice() {
        if ( null !== $this->should_render ) {
            return $this->should_render;
        }

        if ( is_admin() ) {
            $this->should_render = false;

            return false;
        }

        $should_show = apply_filters( 'ecosplay_referrals_should_display_notice', true );

        if ( ! $should_show ) {
            $this->should_render = false;

            return false;
        }

        $this->should_render = ! $this->has_user_seen_notice();

        return $this->should_render;
    }

    /**
     * Indicates if the current visitor has already dismissed the notice.
     *
     * @return bool
     */
    protected function has_user_seen_notice() {
        $user_id = get_current_user_id();

        if ( $user_id > 0 ) {
            return $this->service->has_seen_notification( $user_id );
        }

        return ! empty( $_COOKIE[ self::COOKIE_NAME ] );
    }

    /**
     * Persists a short-lived cookie marking the notice as viewed.
     *
     * @return void
     */
    protected function set_notice_cookie() {
        setcookie(
            self::COOKIE_NAME,
            '1',
            time() + self::COOKIE_TTL,
            COOKIEPATH ? COOKIEPATH : '/',
            COOKIE_DOMAIN,
            is_ssl(),
            false
        );

        $_COOKIE[ self::COOKIE_NAME ] = '1';
    }
}
