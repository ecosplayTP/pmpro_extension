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
 * Displays campaign notifications.
 */
class Floating_Notice {
    const CACHE_TTL    = 600;

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
        add_action( 'wp_ajax_pmpro_notify_notice_seen', array( $this, 'handle_notice_seen' ) );
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
            $this->get_asset_version( 'assets/js/floating-notice.js' ),
            true
        );
        wp_localize_script(
            'pmpro-notify-floating-notice',
            'pmproNotifyFloatingNotice',
            array(
                'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
                'action'     => 'pmpro_notify_notice_seen',
                'nonce'      => wp_create_nonce( 'pmpro_notify_notice_seen' ),
                'campaignId' => $this->campaign ? (int) $this->campaign->id : 0,
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
     * Returns a cache-busting version for an asset file.
     *
     * @param string $relative_path Asset path relative to the plugin root.
     *
     * @return string Version string for asset enqueueing.
     */
    private function get_asset_version( $relative_path ) {
        $path = PMPRO_NOTIFY_DIR . ltrim( $relative_path, '/' );

        if ( file_exists( $path ) ) {
            $mtime = filemtime( $path );

            if ( false !== $mtime ) {
                return (string) $mtime;
            }
        }

        return PMPRO_NOTIFY_VERSION;
    }

    /**
     * Records a notice view in the data store.
     *
     * @file wp-content/plugins/pmpro-notify/public/class-floating-notice.php
     *
     * @param int $campaign_id Campaign identifier.
     * @param int $user_id User identifier.
     *
     * @return void
     */
    public function record_view( $campaign_id, $user_id ) {
        $this->store->insert_view( $campaign_id, $user_id );
    }

    /**
     * Handles the AJAX request to mark the notice as seen.
     *
     * @return void
     */
    public function handle_notice_seen() {
        $user_id     = get_current_user_id();
        $campaign_id = isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0;
        $nonce       = isset( $_POST['nonce'] ) ? sanitize_text_field( wp_unslash( $_POST['nonce'] ) ) : '';

        if ( $user_id <= 0 ) {
            $this->log_notice_debug( 'Dismiss request rejected: user not logged in.', array( 'campaign_id' => $campaign_id ) );
            wp_send_json_error();
        }

        if ( ! wp_verify_nonce( $nonce, 'pmpro_notify_notice_seen' ) ) {
            $this->log_notice_debug(
                'Dismiss request rejected: invalid nonce.',
                array(
                    'campaign_id' => $campaign_id,
                    'user_id'     => $user_id,
                    'nonce'       => $nonce ? 'provided' : 'missing',
                )
            );
            wp_send_json_error();
        }

        if ( $campaign_id <= 0 ) {
            $this->log_notice_debug(
                'Dismiss request rejected: invalid campaign id.',
                array(
                    'campaign_id' => $campaign_id,
                    'user_id'     => $user_id,
                )
            );
            wp_send_json_error();
        }

        $this->record_view( $campaign_id, $user_id );
        $this->log_notice_debug(
            'Dismiss request accepted.',
            array(
                'campaign_id' => $campaign_id,
                'user_id'     => $user_id,
            )
        );
        wp_send_json_success();
    }

    /**
     * Logs debug information to debug.log when enabled.
     *
     * @param string $message Log message.
     * @param array  $context Contextual data for the log entry.
     *
     * @return void
     */
    private function log_notice_debug( $message, array $context = array() ) {
        if ( ! defined( 'WP_DEBUG_LOG' ) || ! WP_DEBUG_LOG ) {
            return;
        }

        $payload = $context ? wp_json_encode( $context ) : '';
        $line    = '[pmpro-notify] ' . $message;

        if ( $payload ) {
            $line .= ' ' . $payload;
        }

        error_log( $line );
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

        $cache_key = $this->get_active_campaign_cache_key();
        $cached    = get_transient( $cache_key );

        if ( false !== $cached ) {
            $this->campaign = $cached ? $cached : null;

            return $this->campaign;
        }

        $campaigns = $this->store->get_active_campaigns();

        foreach ( $campaigns as $campaign ) {
            if ( $this->is_user_allowed( $campaign ) ) {
                $this->campaign = $campaign;
                break;
            }
        }

        set_transient( $cache_key, $this->campaign ? $this->campaign : 0, self::CACHE_TTL );

        return $this->campaign;
    }

    /**
     * Builds a cache key for the active campaign and user segment.
     *
     * @return string
     */
    private function get_active_campaign_cache_key() {
        $version = (int) get_option( 'pmpro_notify_active_campaign_version', 1 );
        $segment = $this->get_active_campaign_cache_segment();

        return sprintf( 'pmpro_notify_active_campaign_%s_v%d', $segment, $version );
    }

    /**
     * Defines a cache segment based on the visitor membership state.
     *
     * @return string
     */
    private function get_active_campaign_cache_segment() {
        $user_id = get_current_user_id();

        if ( $user_id <= 0 ) {
            return 'guest';
        }

        if ( function_exists( 'pmpro_getMembershipLevelsForUser' ) ) {
            $levels = pmpro_getMembershipLevelsForUser( $user_id );
            $ids    = is_array( $levels ) ? wp_list_pluck( $levels, 'id' ) : array();

            if ( $ids ) {
                sort( $ids );

                return md5( wp_json_encode( array_map( 'absint', $ids ) ) );
            }
        }

        return 'member';
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

        return false;
    }
}
