<?php
/**
 * Statistics admin page for PMPro Notify.
 *
 * @package Pmpro_Notify
 * @file    wp-content/plugins/pmpro-notify/admin/class-admin-stats-page.php
 */

namespace Pmpro_Notify;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders statistics about campaign views.
 */
class Admin_Stats_Page {
    /**
     * Data store instance.
     *
     * @var Notify_Store
     */
    private $store;

    /**
     * Sets the data store dependency.
     *
     * @param Notify_Store $store Data store instance.
     */
    public function __construct( Notify_Store $store ) {
        $this->store = $store;
    }

    /**
     * Outputs the statistics table and chart.
     *
     * @return void
     */
    public function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Vous n\'avez pas l\'autorisation d\'accéder à cette page.', 'pmpro-notify' ) );
        }

        $range = $this->get_date_range();
        $data  = $this->get_views_dataset( $range['start'], $range['end'] );

        echo '<h2>' . esc_html__( 'Statistiques des vues', 'pmpro-notify' ) . '</h2>';
        echo '<form method="get" class="pmpro-notify-filter">';
        echo '<input type="hidden" name="page" value="pmpro-notify" />';
        echo '<input type="hidden" name="tab" value="stats" />';
        wp_nonce_field( 'pmpro_notify_stats_range', 'pmpro_notify_stats_nonce' );
        echo '<label>' . esc_html__( 'Début', 'pmpro-notify' ) . ' <input type="date" name="start" value="' . esc_attr( $range['start'] ) . '"></label>';
        echo '<label>' . esc_html__( 'Fin', 'pmpro-notify' ) . ' <input type="date" name="end" value="' . esc_attr( $range['end'] ) . '"></label>';
        submit_button( __( 'Filtrer', 'pmpro-notify' ), 'secondary', '', false );
        echo '</form>';

        $chart = $this->build_svg_chart( $data );
        echo '<div class="pmpro-notify-chart">' . $chart . '</div>';

        echo '<table class="widefat striped pmpro-notify-table">';
        echo '<thead><tr><th>' . esc_html__( 'Date', 'pmpro-notify' ) . '</th><th>' . esc_html__( 'Vues', 'pmpro-notify' ) . '</th></tr></thead><tbody>';
        foreach ( $data as $row ) {
            echo '<tr><td>' . esc_html( $row['date'] ) . '</td><td>' . esc_html( $row['count'] ) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    /**
     * Retrieves the filtered date range for the report.
     *
     * @return array
     */
    private function get_date_range() {
        $default_end   = current_time( 'Y-m-d' );
        $default_start = gmdate( 'Y-m-d', strtotime( $default_end . ' -13 days' ) );

        $start = isset( $_GET['start'] ) ? sanitize_text_field( wp_unslash( $_GET['start'] ) ) : $default_start;
        $end   = isset( $_GET['end'] ) ? sanitize_text_field( wp_unslash( $_GET['end'] ) ) : $default_end;

        if ( empty( $_GET['start'] ) && empty( $_GET['end'] ) ) {
            return array( 'start' => $default_start, 'end' => $default_end );
        }

        if ( empty( $_GET['pmpro_notify_stats_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['pmpro_notify_stats_nonce'] ) ), 'pmpro_notify_stats_range' ) ) {
            return array( 'start' => $default_start, 'end' => $default_end );
        }

        if ( ! $this->is_valid_date( $start ) || ! $this->is_valid_date( $end ) ) {
            return array( 'start' => $default_start, 'end' => $default_end );
        }

        if ( $start > $end ) {
            return array( 'start' => $default_start, 'end' => $default_end );
        }

        return array( 'start' => $start, 'end' => $end );
    }

    /**
     * Builds a normalized dataset of views per day.
     *
     * @param string $start Start date.
     * @param string $end   End date.
     *
     * @return array
     */
    private function get_views_dataset( $start, $end ) {
        $raw_rows = $this->store->get_views_by_day( $start, $end );
        $indexed  = array();

        foreach ( $raw_rows as $row ) {
            $indexed[ $row['view_date'] ] = (int) $row['total_views'];
        }

        $data = array();
        $date = $start;
        while ( $date <= $end ) {
            $data[] = array(
                'date'  => $date,
                'count' => isset( $indexed[ $date ] ) ? $indexed[ $date ] : 0,
            );
            $date = gmdate( 'Y-m-d', strtotime( $date . ' +1 day' ) );
        }

        return $data;
    }

    /**
     * Builds a simple SVG line chart from the dataset.
     *
     * @param array $data Dataset rows.
     *
     * @return string
     */
    private function build_svg_chart( $data ) {
        if ( empty( $data ) ) {
            return '';
        }

        $width  = 640;
        $height = 180;
        $max    = max( array_column( $data, 'count' ) );
        $max    = $max > 0 ? $max : 1;
        $step   = $width / max( 1, count( $data ) - 1 );
        $points = array();

        foreach ( $data as $index => $row ) {
            $x      = $index * $step;
            $ratio  = $row['count'] / $max;
            $y      = $height - ( $ratio * $height );
            $points[] = round( $x, 2 ) . ',' . round( $y, 2 );
        }

        return sprintf(
            '<svg viewBox="0 0 %1$d %2$d" role="img" aria-label="%3$s"><polyline fill="none" stroke="#2271b1" stroke-width="3" points="%4$s" /><polyline fill="none" stroke="#dcdcde" stroke-width="1" points="0,%2$d %1$d,%2$d" /></svg>',
            $width,
            $height,
            esc_attr__( 'Évolution des vues', 'pmpro-notify' ),
            esc_attr( implode( ' ', $points ) )
        );
    }

    /**
     * Validates a Y-m-d date.
     *
     * @param string $date Date string.
     *
     * @return bool
     */
    private function is_valid_date( $date ) {
        $parsed = date_create_from_format( 'Y-m-d', $date );

        return $parsed && $parsed->format( 'Y-m-d' ) === $date;
    }
}
