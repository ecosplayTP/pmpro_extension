<?php
/**
 * Admin controller summarising referral performance metrics.
 *
 * @package Ecosplay\Referrals
 * @file    wp-content/plugins/ecosplay-referrals/admin/class-admin-stats-page.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Produces aggregated statistics for administrators.
 */
class Ecosplay_Referrals_Admin_Stats_Page {
    /**
     * Domain logic coordinator.
     *
     * @var Ecosplay_Referrals_Service
     */
    protected $service;

    /**
     * Aggregated totals for the view.
     *
     * @var array<string,mixed>
     */
    protected $stats = array();

    /**
     * Injects the referrals service.
     *
     * @param Ecosplay_Referrals_Service $service Domain logic service.
     */
    public function __construct( Ecosplay_Referrals_Service $service ) {
        $this->service = $service;
    }

    /**
     * Identifies the handled tab slug.
     *
     * @return string
     */
    public function get_slug() {
        return 'stats';
    }

    /**
     * Provides the heading to use for the tab.
     *
     * @return string
     */
    public function get_title() {
        return __( 'Statistiques de parrainage', 'ecosplay-referrals' );
    }

    /**
     * No-op hook for interface parity.
     *
     * @return void
     */
    public function handle() {}

    /**
     * Renders the statistics view with aggregated data.
     *
     * @return void
     */
    public function render() {
        $period = isset( $_GET['period'] ) ? sanitize_key( wp_unslash( $_GET['period'] ) ) : 'month';
        $limit  = isset( $_GET['points'] ) ? max( 1, absint( $_GET['points'] ) ) : 6;

        $snapshot = $this->service->get_stats_snapshot( $period, $limit );
        $total    = $this->service->get_total_credits_due();

        $stats  = $snapshot;
        $amount = $total;
        $period = $snapshot['period'];

        include ECOSPLAY_REFERRALS_ADMIN . 'views/stats.php';
    }
}
