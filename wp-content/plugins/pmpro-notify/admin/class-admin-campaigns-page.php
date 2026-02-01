<?php
/**
 * Campaigns admin page for PMPro Notify.
 *
 * @package Pmpro_Notify
 * @file    wp-content/plugins/pmpro-notify/admin/class-admin-campaigns-page.php
 */

namespace Pmpro_Notify;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Renders and handles the campaigns list and form.
 */
class Admin_Campaigns_Page {
    /**
     * Data store instance.
     *
     * @var Notify_Store
     */
    private $store;

    /**
     * Admin notice message.
     *
     * @var string
     */
    private $notice = '';

    /**
     * Sets the data store dependency.
     *
     * @param Notify_Store $store Data store instance.
     */
    public function __construct( Notify_Store $store ) {
        $this->store = $store;
    }

    /**
     * Outputs the campaigns table and edit form.
     *
     * @return void
     */
    public function render() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Vous n\'avez pas l\'autorisation d\'accéder à cette page.', 'pmpro-notify' ) );
        }

        $this->handle_actions();

        $campaign_id = isset( $_GET['campaign_id'] ) ? absint( $_GET['campaign_id'] ) : 0;
        $campaign    = $campaign_id ? $this->store->get_campaign( $campaign_id ) : null;
        $campaigns   = $this->store->get_campaigns();
        $levels      = $this->get_membership_levels();
        $selected    = $campaign && ! empty( $campaign->level_target ) ? json_decode( $campaign->level_target, true ) : array();
        $selected    = is_array( $selected ) ? array_map( 'absint', $selected ) : array();

        if ( $this->notice ) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( $this->notice ) . '</p></div>';
        }

        echo '<h2>' . esc_html__( 'Campagnes existantes', 'pmpro-notify' ) . '</h2>';
        echo '<table class="widefat striped pmpro-notify-table">';
        echo '<thead><tr>';
        echo '<th>' . esc_html__( 'Titre', 'pmpro-notify' ) . '</th>';
        echo '<th>' . esc_html__( 'Période', 'pmpro-notify' ) . '</th>';
        echo '<th>' . esc_html__( 'Niveaux', 'pmpro-notify' ) . '</th>';
        echo '<th>' . esc_html__( 'Statut', 'pmpro-notify' ) . '</th>';
        echo '<th>' . esc_html__( 'Action', 'pmpro-notify' ) . '</th>';
        echo '</tr></thead><tbody>';

        if ( empty( $campaigns ) ) {
            echo '<tr><td colspan="5">' . esc_html__( 'Aucune campagne enregistrée.', 'pmpro-notify' ) . '</td></tr>';
        } else {
            foreach ( $campaigns as $row ) {
                $level_label = $this->format_levels_label( $row->level_target, $levels );
                $period      = $this->format_period_label( $row->start_at, $row->end_at );
                $status      = $row->is_active ? __( 'Active', 'pmpro-notify' ) : __( 'Inactive', 'pmpro-notify' );
                $edit_url    = admin_url( 'admin.php?page=pmpro-notify&tab=campaigns&campaign_id=' . absint( $row->id ) );

                echo '<tr>';
                echo '<td>' . esc_html( $row->title ) . '</td>';
                echo '<td>' . esc_html( $period ) . '</td>';
                echo '<td>' . esc_html( $level_label ) . '</td>';
                echo '<td>' . esc_html( $status ) . '</td>';
                echo '<td><a class="button button-small" href="' . esc_url( $edit_url ) . '">' . esc_html__( 'Modifier', 'pmpro-notify' ) . '</a></td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';

        $form_title = $campaign ? __( 'Modifier la campagne', 'pmpro-notify' ) : __( 'Créer une campagne', 'pmpro-notify' );
        echo '<h2 class="pmpro-notify-section-title">' . esc_html( $form_title ) . '</h2>';
        echo '<form method="post" class="pmpro-notify-form">';
        wp_nonce_field( 'pmpro_notify_save_campaign', 'pmpro_notify_nonce' );
        echo '<input type="hidden" name="pmpro_notify_action" value="save_campaign" />';
        echo '<input type="hidden" name="campaign_id" value="' . esc_attr( $campaign ? $campaign->id : 0 ) . '" />';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th scope="row"><label for="pmpro-notify-title">' . esc_html__( 'Titre', 'pmpro-notify' ) . '</label></th>';
        echo '<td><input type="text" id="pmpro-notify-title" name="title" class="regular-text" value="' . esc_attr( $campaign ? $campaign->title : '' ) . '" required></td></tr>';
        echo '<tr><th scope="row"><label for="pmpro-notify-message">' . esc_html__( 'Message', 'pmpro-notify' ) . '</label></th>';
        echo '<td><textarea id="pmpro-notify-message" name="message" class="large-text" rows="4" required>' . esc_textarea( $campaign ? $campaign->message : '' ) . '</textarea></td></tr>';
        echo '<tr><th scope="row"><label for="pmpro-notify-levels">' . esc_html__( 'Niveaux ciblés', 'pmpro-notify' ) . '</label></th><td>';
        if ( empty( $levels ) ) {
            echo '<p>' . esc_html__( 'Aucun niveau trouvé.', 'pmpro-notify' ) . '</p>';
        } else {
            echo '<select id="pmpro-notify-levels" name="level_target[]" multiple class="pmpro-notify-multiselect">';
            foreach ( $levels as $level ) {
                $is_selected = in_array( absint( $level->id ), $selected, true ) ? 'selected' : '';
                echo '<option value="' . esc_attr( $level->id ) . '" ' . $is_selected . '>' . esc_html( $level->name ) . '</option>';
            }
            echo '</select><p class="description">' . esc_html__( 'Laisser vide pour cibler tous les membres.', 'pmpro-notify' ) . '</p>';
        }
        echo '</td></tr>';
        echo '<tr><th scope="row"><label for="pmpro-notify-start">' . esc_html__( 'Début', 'pmpro-notify' ) . '</label></th>';
        echo '<td><input type="date" id="pmpro-notify-start" name="start_at" value="' . esc_attr( $campaign ? substr( $campaign->start_at, 0, 10 ) : '' ) . '"></td></tr>';
        echo '<tr><th scope="row"><label for="pmpro-notify-end">' . esc_html__( 'Fin', 'pmpro-notify' ) . '</label></th>';
        echo '<td><input type="date" id="pmpro-notify-end" name="end_at" value="' . esc_attr( $campaign ? substr( $campaign->end_at, 0, 10 ) : '' ) . '"></td></tr>';
        echo '<tr><th scope="row">' . esc_html__( 'Statut', 'pmpro-notify' ) . '</th>';
        echo '<td><label><input type="checkbox" name="is_active" value="1" ' . checked( $campaign ? (int) $campaign->is_active : 1, 1, false ) . '> ' . esc_html__( 'Campagne active', 'pmpro-notify' ) . '</label></td></tr>';
        echo '</tbody></table>';
        submit_button( $campaign ? __( 'Mettre à jour', 'pmpro-notify' ) : __( 'Créer', 'pmpro-notify' ) );
        echo '</form>';
    }

    /**
     * Handles campaign create/update submissions.
     *
     * @return void
     */
    private function handle_actions() {
        if ( empty( $_POST['pmpro_notify_action'] ) || 'save_campaign' !== $_POST['pmpro_notify_action'] ) {
            return;
        }

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( esc_html__( 'Vous n\'avez pas l\'autorisation d\'accéder à cette page.', 'pmpro-notify' ) );
        }

        check_admin_referer( 'pmpro_notify_save_campaign', 'pmpro_notify_nonce' );

        $title   = isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '';
        $message = isset( $_POST['message'] ) ? wp_kses_post( wp_unslash( $_POST['message'] ) ) : '';

        $levels = array();
        if ( ! empty( $_POST['level_target'] ) && is_array( $_POST['level_target'] ) ) {
            $levels = array_map( 'absint', wp_unslash( $_POST['level_target'] ) );
            $levels = array_filter( $levels );
        }

        $start_at = isset( $_POST['start_at'] ) ? sanitize_text_field( wp_unslash( $_POST['start_at'] ) ) : '';
        $end_at   = isset( $_POST['end_at'] ) ? sanitize_text_field( wp_unslash( $_POST['end_at'] ) ) : '';

        $payload = array(
            'id'           => isset( $_POST['campaign_id'] ) ? absint( $_POST['campaign_id'] ) : 0,
            'title'        => $title,
            'message'      => $message,
            'level_target' => empty( $levels ) ? null : wp_json_encode( $levels ),
            'start_at'     => $start_at ? $start_at . ' 00:00:00' : null,
            'end_at'       => $end_at ? $end_at . ' 23:59:59' : null,
            'is_active'    => isset( $_POST['is_active'] ) ? 1 : 0,
        );

        $saved_id = $this->store->save_campaign( $payload );

        if ( false !== $saved_id ) {
            $this->notice = __( 'Campagne enregistrée avec succès.', 'pmpro-notify' );
        }
    }

    /**
     * Retrieves membership levels to populate the selector.
     *
     * @return array
     */
    private function get_membership_levels() {
        if ( function_exists( 'pmpro_getAllLevels' ) ) {
            $levels = pmpro_getAllLevels( true, true );

            return is_array( $levels ) ? $levels : array();
        }

        return array();
    }

    /**
     * Formats a readable label for selected levels.
     *
     * @param string|null $level_target JSON encoded target levels.
     * @param array       $levels       Available levels.
     *
     * @return string
     */
    private function format_levels_label( $level_target, $levels ) {
        if ( empty( $level_target ) ) {
            return __( 'Tous', 'pmpro-notify' );
        }

        $ids = json_decode( $level_target, true );

        if ( ! is_array( $ids ) ) {
            return __( 'Tous', 'pmpro-notify' );
        }

        $labels = array();
        foreach ( $levels as $level ) {
            if ( in_array( absint( $level->id ), $ids, true ) ) {
                $labels[] = $level->name;
            }
        }

        return empty( $labels ) ? __( 'Tous', 'pmpro-notify' ) : implode( ', ', $labels );
    }

    /**
     * Formats the campaign period label.
     *
     * @param string|null $start_at Start date.
     * @param string|null $end_at   End date.
     *
     * @return string
     */
    private function format_period_label( $start_at, $end_at ) {
        $start_at = $start_at ? mysql2date( 'Y-m-d', $start_at ) : '';
        $end_at   = $end_at ? mysql2date( 'Y-m-d', $end_at ) : '';

        if ( $start_at && $end_at ) {
            return $start_at . ' → ' . $end_at;
        }

        if ( $start_at ) {
            return sprintf( __( 'À partir du %s', 'pmpro-notify' ), $start_at );
        }

        if ( $end_at ) {
            return sprintf( __( 'Jusqu\'au %s', 'pmpro-notify' ), $end_at );
        }

        return __( 'Sans date', 'pmpro-notify' );
    }
}
