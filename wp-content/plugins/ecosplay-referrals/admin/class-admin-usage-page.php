<?php
/**
 * Admin controller exposing referral usage history.
 *
 * @package Ecosplay\Referrals
 * @file    wp-content/plugins/ecosplay-referrals/admin/class-admin-usage-page.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Displays referral usage entries for reporting.
 */
class Ecosplay_Referrals_Admin_Usage_Page {
    /**
     * Domain service instance.
     *
     * @var Ecosplay_Referrals_Service
     */
    protected $service;

    /**
     * Optional referral filter.
     *
     * @var int|null
     */
    protected $referral_id = null;

    /**
     * Collected usage rows.
     *
     * @var array<int,object>
     */
    protected $usage = array();

    /**
     * Injects dependencies required to read usage data.
     *
     * @param Ecosplay_Referrals_Service $service Domain logic service.
     */
    public function __construct( Ecosplay_Referrals_Service $service ) {
        $this->service = $service;
    }

    /**
     * Returns the tab slug handled here.
     *
     * @return string
     */
    public function get_slug() {
        return 'usage';
    }

    /**
     * Returns the page heading for the current tab.
     *
     * @return string
     */
    public function get_title() {
        return __( 'Historique des utilisations', 'ecosplay-referrals' );
    }

    /**
     * Reads filter parameters before rendering the view.
     *
     * @return void
     */
    public function handle() {
        if ( isset( $_GET['referral'] ) ) {
            $this->referral_id = absint( wp_unslash( $_GET['referral'] ) );
        }
    }

    /**
     * Renders the usage history template.
     *
     * @return void
     */
    public function render() {
        $limit = isset( $_GET['per_page'] ) ? max( 1, absint( $_GET['per_page'] ) ) : 50;

        $this->usage = $this->service->get_usage_history( $this->referral_id, $limit );
        $codes       = $this->service->get_codes_overview( false );
        $codes_index = array();

        foreach ( $codes as $code ) {
            $codes_index[ $code->id ] = $code;
        }

        $usage       = $this->usage;
        $referral_id = $this->referral_id;

        include ECOSPLAY_REFERRALS_ADMIN . 'views/usage.php';
    }
}
