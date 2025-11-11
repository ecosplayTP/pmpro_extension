<?php
/**
 * Stripe logs administration view.
 *
 * @package Ecosplay\Referrals
 * @file    wp-content/plugins/ecosplay-referrals/admin/views/logs.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="ecos-referrals-section">
    <form method="get" class="ecos-referrals-filter">
        <input type="hidden" name="page" value="ecosplay-referrals" />
        <input type="hidden" name="tab" value="logs" />
        <label for="ecos-log-type"><?php esc_html_e( 'Type d\'événement', 'ecosplay-referrals' ); ?></label>
        <select id="ecos-log-type" name="event_type">
            <option value=""><?php esc_html_e( 'Tous les types', 'ecosplay-referrals' ); ?></option>
            <?php foreach ( $event_types as $event_type ) : ?>
                <option value="<?php echo esc_attr( $event_type ); ?>" <?php selected( $filters['type'], $event_type ); ?>><?php echo esc_html( $event_type ); ?></option>
            <?php endforeach; ?>
        </select>
        <label for="ecos-log-from"><?php esc_html_e( 'Du', 'ecosplay-referrals' ); ?></label>
        <input type="date" id="ecos-log-from" name="from" value="<?php echo esc_attr( isset( $filters['from'] ) ? $filters['from'] : '' ); ?>" />
        <label for="ecos-log-to"><?php esc_html_e( 'Au', 'ecosplay-referrals' ); ?></label>
        <input type="date" id="ecos-log-to" name="to" value="<?php echo esc_attr( isset( $filters['to'] ) ? $filters['to'] : '' ); ?>" />
        <button type="submit" class="button"><?php esc_html_e( 'Filtrer', 'ecosplay-referrals' ); ?></button>
    </form>

    <?php if ( empty( $logs ) ) : ?>
        <p><?php esc_html_e( 'Aucun webhook enregistré pour ces critères.', 'ecosplay-referrals' ); ?></p>
    <?php else : ?>
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Reçu le', 'ecosplay-referrals' ); ?></th>
                    <th><?php esc_html_e( 'Type', 'ecosplay-referrals' ); ?></th>
                    <th><?php esc_html_e( 'Statut', 'ecosplay-referrals' ); ?></th>
                    <th><?php esc_html_e( 'Payload', 'ecosplay-referrals' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $logs as $entry ) :
                    $received = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry->created_at );
                    $payload  = isset( $entry->payload ) ? $entry->payload : '';
                    $excerpt  = wp_strip_all_tags( $payload );
                    $excerpt  = wp_html_excerpt( $excerpt, 200, '&hellip;' );
                    ?>
                    <tr>
                        <td><?php echo esc_html( $received ); ?></td>
                        <td><?php echo esc_html( $entry->event_type ); ?></td>
                        <td><?php echo esc_html( $entry->status ); ?></td>
                        <td><code><?php echo esc_html( $excerpt ); ?></code></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
