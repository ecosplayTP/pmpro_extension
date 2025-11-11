<?php
/**
 * Admin controller displaying Stripe webhook logs.
 *
 * @package Ecosplay\Referrals
 * @file    wp-content/plugins/ecosplay-referrals/admin/class-admin-stripe-logs-page.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Offers filtering and display of webhook log entries.
 */
class Ecosplay_Referrals_Admin_Stripe_Logs_Page {
    /**
     * Referrals domain service.
     *
     * @var Ecosplay_Referrals_Service
     */
    protected $service;

    /**
     * Filtered log entries.
     *
     * @var array<int,object>
     */
    protected $logs = array();

    /**
     * Applied filters.
     *
     * @var array<string,string>
     */
    protected $filters = array();

    /**
     * Available event types.
     *
     * @var array<int,string>
     */
    protected $event_types = array();

    /**
     * Wires the service dependency.
     *
     * @param Ecosplay_Referrals_Service $service Domain logic service.
     */
    public function __construct( Ecosplay_Referrals_Service $service ) {
        $this->service = $service;
    }

    /**
     * Returns the handled tab slug.
     *
     * @return string
     */
    public function get_slug() {
        return 'logs';
    }

    /**
     * Returns the page title.
     *
     * @return string
     */
    public function get_title() {
        return __( 'Logs Stripe', 'ecosplay-referrals' );
    }

    /**
     * Captures filter parameters from the query string.
     *
     * @return void
     */
    public function handle() {
        $type = isset( $_GET['event_type'] ) ? sanitize_text_field( wp_unslash( $_GET['event_type'] ) ) : '';
        $from = isset( $_GET['from'] ) ? $this->normalise_date( wp_unslash( $_GET['from'] ) ) : '';
        $to   = isset( $_GET['to'] ) ? $this->normalise_date( wp_unslash( $_GET['to'] ) ) : '';

        $this->filters = array(
            'type'  => $type,
            'from'  => $from,
            'to'    => $to,
            'limit' => 100,
        );
    }

    /**
     * Renders the logs table.
     *
     * @return void
     */
    public function render() {
        if ( empty( $this->filters ) ) {
            $this->handle();
        }

        $this->event_types = $this->service->get_webhook_event_types();
        $this->logs        = $this->service->get_webhook_logs( $this->filters );

        $logs        = $this->logs;
        $filters     = $this->filters;
        $event_types = $this->event_types;

        include ECOSPLAY_REFERRALS_ADMIN . 'views/logs.php';
    }

    /**
     * Normalises a date string into Y-m-d format.
     *
     * @param string $value Raw date string.
     *
     * @return string
     */
    protected function normalise_date( $value ) {
        $value = trim( (string) $value );

        if ( '' === $value ) {
            return '';
        }

        $timestamp = strtotime( $value );

        if ( false === $timestamp ) {
            return '';
        }

        return gmdate( 'Y-m-d', $timestamp );
    }
}
