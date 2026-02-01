<?php
/**
 * Front-end floating notice controller for PMPro Notify.
 *
 * @package Pmpro_Notify
 * @file    wp-content/plugins/pmpro-notify/public/class-floating-notice.php
 */

namespace Pmpro_Notify;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Displays campaign notifications and records dismissals.
 */
class Floating_Notice {
    const COOKIE_NAME  = 'pmpro_notify_notice_seen';
    const AJAX_ACTION  = 'pmpro_notify_notice_dismiss';
    const NONCE_ACTION = 'pmpro_notify_notice';
    const COOKIE_TTL   = WEEK_IN_SECONDS;

    /**
     * Data store for campaigns and views.
     *
     * @var Notify_Store
     */
    private $store;

    /**
     * Cached campaign row.
     *
     * @var object|null
     */
    private $campaign = null;

    /**
     * Cached decision on whether to render.
     *
     * @var bool|null
     */
    private $should_render = null;

    /**
     * Sets up hooks for the floating notice.
     *
     * @param Notify_Store $store Data store instance.
     */
    public function __construct( Notify_Store $store ) {
        $this->store = $store;

        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
        add_action( 'wp_footer', array( $this, 'render_notice' ) );
        add_action( 'wp_ajax_' . self::AJAX_ACTION, array( $this, 'handle_dismiss' ) );
        add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( $this, 'handle_dismiss' ) );
    }

    /**
     * Enqueues the assets when a campaign should be displayed.
     *
     * @return void
     */
    public function enqueue_assets() {
        if ( ! $this->should_display_notice() ) {
            return;
        }

        wp_enqueue_style(
            'pmpro-notify-floating-notice',
            PMPRO_NOTIFY_URL . 'assets/css/floating-notice.css',
            array(),
            PMPRO_NOTIFY_VERSION
        );

        wp_enqueue_script(
            'pmpro-notify-floating-notice',
            PMPRO_NOTIFY_URL . 'assets/js/floating-notice.js',
            array(),
            PMPRO_NOTIFY_VERSION,
            true
        );

        wp_localize_script(
            'pmpro-notify-floating-notice',
            'pmproNotifyFloatingNotice',
            array(
                'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
                'action'       => self::AJAX_ACTION,
                'nonce'        => wp_create_nonce( self::NONCE_ACTION ),
                'campaignId'   => $this->campaign ? absint( $this->campaign->id ) : 0,
                'cookieName'   => self::COOKIE_NAME,
                'cookieTtl'    => self::COOKIE_TTL,
                'cookiePath'   => COOKIEPATH ? COOKIEPATH : '/',
                'cookieSecure' => is_ssl(),
            )
        );
    }

    /**
     * Renders the floating notice markup.
     *
     * @return void
     */
    public function render_notice() {
        if ( ! $this->should_display_notice() ) {
            return;
        }

        $message = $this->campaign ? trim( $this->campaign->message ) : '';

        if ( '' === $message ) {
            return;
        }

        $message = make_clickable( $message );
        $message = nl2br( $message );
        $message = wp_kses(
            $message,
            array(
                'a'      => array(
                    'href'   => true,
                    'target' => true,
                    'rel'    => true,
                ),
                'br'     => array(),
                'strong' => array(),
                'em'     => array(),
            )
        );
        ?>
        <div class="pmpro-notify-floating-notice" role="dialog" aria-live="polite" aria-label="<?php esc_attr_e( 'Notification', 'pmpro-notify' ); ?>">
            <div class="pmpro-notify-floating-notice__body">
                <p class="pmpro-notify-floating-notice__text">
                    <?php echo $message; ?>
                </p>
                <button type="button" class="pmpro-notify-floating-notice__close" data-pmpro-notify-dismiss aria-label="<?php esc_attr_e( 'Fermer la notification', 'pmpro-notify' ); ?>">
                    &times;
                </button>
            </div>
        </div>
        <?php
    }

    /**
     * Handles the dismiss action and records the view.
     *
     * @return void
     */
    public function handle_dismiss() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        $campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
        $user_id     = get_current_user_id();

        if ( $campaign_id > 0 && $user_id > 0 ) {
            $this->store->insert_view( $campaign_id, $user_id );
        }

        $this->set_notice_cookie( $campaign_id );

        wp_send_json_success( array( 'status' => 'dismissed' ) );
    }

    /**
     * Determines whether the notice should be displayed.
     *
     * @return bool
     */
    private function should_display_notice() {
        if ( null !== $this->should_render ) {
            return $this->should_render;
        }

        if ( is_admin() ) {
            $this->should_render = false;

            return false;
        }

        $campaign = $this->get_active_campaign();

        if ( ! $campaign ) {
            $this->should_render = false;

            return false;
        }

        if ( ! $this->is_user_allowed( $campaign ) ) {
            $this->should_render = false;

            return false;
        }

        $this->should_render = ! $this->has_user_seen_notice( $campaign );

        return $this->should_render;
    }

    /**
     * Retrieves and caches the active campaign.
     *
     * @return object|null
     */
    private function get_active_campaign() {
        if ( null !== $this->campaign ) {
            return $this->campaign;
        }

        $campaigns = $this->store->get_active_campaigns();

        foreach ( $campaigns as $campaign ) {
            if ( $this->is_user_allowed( $campaign ) ) {
                $this->campaign = $campaign;
                break;
            }
        }

        return $this->campaign;
    }

    /**
     * Checks if the campaign targets the current member level.
     *
     * @param object $campaign Campaign row.
     *
     * @return bool
     */
    private function is_user_allowed( $campaign ) {
        if ( empty( $campaign->level_target ) ) {
            return true;
        }

        if ( ! function_exists( 'pmpro_hasMembershipLevel' ) ) {
            return false;
        }

        $levels = json_decode( $campaign->level_target, true );

        if ( ! is_array( $levels ) ) {
            return false;
        }

        return pmpro_hasMembershipLevel( array_map( 'absint', $levels ) );
    }

    /**
     * Indicates if the current user already dismissed the notice.
     *
     * @param object $campaign Campaign row.
     *
     * @return bool
     */
    private function has_user_seen_notice( $campaign ) {
        $user_id = get_current_user_id();

        if ( $user_id > 0 ) {
            return $this->store->has_seen_campaign( $campaign->id, $user_id );
        }

        if ( empty( $_COOKIE[ self::COOKIE_NAME ] ) ) {
            return false;
        }

        return absint( $_COOKIE[ self::COOKIE_NAME ] ) >= absint( $campaign->id );
    }

    /**
     * Persists a cookie to avoid repeating the notice for guests.
     *
     * @param int $campaign_id Campaign identifier.
     *
     * @return void
     */
    private function set_notice_cookie( $campaign_id ) {
        setcookie(
            self::COOKIE_NAME,
            (string) absint( $campaign_id ),
            time() + self::COOKIE_TTL,
            COOKIEPATH ? COOKIEPATH : '/',
            COOKIE_DOMAIN,
            is_ssl(),
            false
        );

        $_COOKIE[ self::COOKIE_NAME ] = (string) absint( $campaign_id );
    }
}
