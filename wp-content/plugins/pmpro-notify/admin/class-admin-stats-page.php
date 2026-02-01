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
     * Outputs the statistics table and chart for a selected campaign.
     *
     * @return void
     */
    public function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Vous n\'avez pas l\'autorisation d\'accéder à cette page.', 'pmpro-notify' ) );
        }

        $campaigns   = $this->store->get_campaigns();
        $selected    = $this->get_selected_campaign_id( $campaigns );
        $chart_data  = $this->get_views_dataset( $selected );
        $date_range  = $this->get_date_range( $chart_data );
        $table_data  = $this->filter_dataset_by_date_range( $chart_data, $date_range['start'], $date_range['end'] );

        echo '<h2>' . esc_html__( 'Statistiques des vues', 'pmpro-notify' ) . '</h2>';
        if ( empty( $campaigns ) ) {
            echo '<p>' . esc_html__( 'Aucune campagne disponible pour les statistiques.', 'pmpro-notify' ) . '</p>';

            return;
        }

        echo '<form method="get" class="pmpro-notify-filter">';
        echo '<input type="hidden" name="page" value="pmpro-notify" />';
        echo '<input type="hidden" name="tab" value="stats" />';
        wp_nonce_field( 'pmpro_notify_stats_campaign', 'pmpro_notify_stats_nonce' );
        echo '<label for="pmpro-notify-campaign">' . esc_html__( 'Campagne', 'pmpro-notify' ) . '</label>';
        echo '<select id="pmpro-notify-campaign" name="campaign_id">';
        foreach ( $campaigns as $campaign ) {
            echo '<option value="' . esc_attr( $campaign->id ) . '"' . selected( $selected, (int) $campaign->id, false ) . '>' . esc_html( $campaign->title ) . '</option>';
        }
        echo '</select>';
        echo '<label for="pmpro-notify-start-date">' . esc_html__( 'Du', 'pmpro-notify' ) . '</label>';
        echo '<input type="date" id="pmpro-notify-start-date" name="start_date" value="' . esc_attr( $date_range['start'] ) . '" class="pmpro-notify-date" />';
        echo '<label for="pmpro-notify-end-date">' . esc_html__( 'Au', 'pmpro-notify' ) . '</label>';
        echo '<input type="date" id="pmpro-notify-end-date" name="end_date" value="' . esc_attr( $date_range['end'] ) . '" class="pmpro-notify-date" />';
        submit_button( __( 'Filtrer', 'pmpro-notify' ), 'secondary', '', false );
        echo '</form>';

        $chart = $this->build_svg_chart( $chart_data );
        echo '<div class="pmpro-notify-chart">' . $chart . '</div>';

        echo '<table class="widefat striped pmpro-notify-table">';
        echo '<thead><tr><th>' . esc_html__( 'Date', 'pmpro-notify' ) . '</th><th>' . esc_html__( 'Vues', 'pmpro-notify' ) . '</th></tr></thead><tbody>';
        foreach ( $table_data as $row ) {
            echo '<tr><td>' . esc_html( $row['date'] ) . '</td><td>' . esc_html( $row['count'] ) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    /**
     * Retrieves the campaign identifier requested in the filter.
     *
     * @param array $campaigns List of campaign rows.
     *
     * @return int
     */
    private function get_selected_campaign_id( $campaigns ) {
        if ( empty( $campaigns ) ) {
            return 0;
        }

        $default_id = (int) $campaigns[0]->id;

        if ( empty( $_GET['campaign_id'] ) ) {
            return $default_id;
        }

        if ( empty( $_GET['pmpro_notify_stats_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['pmpro_notify_stats_nonce'] ) ), 'pmpro_notify_stats_campaign' ) ) {
            return $default_id;
        }

        $requested_id = absint( $_GET['campaign_id'] );
        $allowed_ids  = array_map(
            static function( $campaign ) {
                return (int) $campaign->id;
            },
            $campaigns
        );

        if ( ! in_array( $requested_id, $allowed_ids, true ) ) {
            return $default_id;
        }

        return $requested_id;
    }

    /**
     * Builds a normalized dataset of views per day for a campaign.
     *
     * @param int $campaign_id Campaign identifier.
     *
     * @return array
     */
    private function get_views_dataset( $campaign_id ) {
        if ( empty( $campaign_id ) ) {
            return array();
        }

        $raw_rows = $this->store->get_views_by_campaign( $campaign_id );
        $indexed  = array();

        foreach ( $raw_rows as $row ) {
            $indexed[ $row['view_date'] ] = (int) $row['total_views'];
        }

        $data = array();
        foreach ( $indexed as $date => $count ) {
            $data[] = array(
                'date'  => $date,
                'count' => $count,
            );
        }

        return $data;
    }

    /**
     * Defines the date range to display in the table.
     *
     * @param array $data Dataset rows.
     *
     * @return array
     */
    private function get_date_range( $data ) {
        $default_range = $this->get_default_date_range( $data );

        if ( empty( $_GET['start_date'] ) && empty( $_GET['end_date'] ) ) {
            return $default_range;
        }

        if ( empty( $_GET['pmpro_notify_stats_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['pmpro_notify_stats_nonce'] ) ), 'pmpro_notify_stats_campaign' ) ) {
            return $default_range;
        }

        $start = $this->normalize_date_value( $_GET['start_date'] ?? '' );
        $end   = $this->normalize_date_value( $_GET['end_date'] ?? '' );

        if ( empty( $start ) ) {
            $start = $default_range['start'];
        }

        if ( empty( $end ) ) {
            $end = $default_range['end'];
        }

        if ( $start && $end && $start > $end ) {
            $swap  = $start;
            $start = $end;
            $end   = $swap;
        }

        return array(
            'start' => $start,
            'end'   => $end,
        );
    }

    /**
     * Builds the default date range based on the last 10 activity days.
     *
     * @param array $data Dataset rows.
     *
     * @return array
     */
    private function get_default_date_range( $data ) {
        if ( empty( $data ) ) {
            return array(
                'start' => '',
                'end'   => '',
            );
        }

        $slice = array_slice( $data, -10 );

        return array(
            'start' => $slice[0]['date'] ?? '',
            'end'   => $slice[ count( $slice ) - 1 ]['date'] ?? '',
        );
    }

    /**
     * Filters a dataset between the provided dates.
     *
     * @param array  $data  Dataset rows.
     * @param string $start Start date (Y-m-d).
     * @param string $end   End date (Y-m-d).
     *
     * @return array
     */
    private function filter_dataset_by_date_range( $data, $start, $end ) {
        if ( empty( $start ) && empty( $end ) ) {
            return $data;
        }

        $filtered = array();

        foreach ( $data as $row ) {
            if ( $start && $row['date'] < $start ) {
                continue;
            }

            if ( $end && $row['date'] > $end ) {
                continue;
            }

            $filtered[] = $row;
        }

        return $filtered;
    }

    /**
     * Normalizes a date input to Y-m-d when valid.
     *
     * @param string $value Raw date input.
     *
     * @return string
     */
    private function normalize_date_value( $value ) {
        $value = sanitize_text_field( wp_unslash( $value ) );

        if ( empty( $value ) ) {
            return '';
        }

        $date = date_create_from_format( 'Y-m-d', $value );

        if ( false === $date ) {
            return '';
        }

        return $date->format( 'Y-m-d' );
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

}
