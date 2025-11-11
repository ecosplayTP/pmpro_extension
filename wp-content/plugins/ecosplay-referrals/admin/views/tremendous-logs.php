<?php
/**
 * Tremendous logs administration view.
 *
 * @package Ecosplay\Referrals
 * @file    wp-content/plugins/ecosplay-referrals/admin/views/tremendous-logs.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="ecos-referrals-section">
    <form method="get" class="ecos-referrals-filter">
        <input type="hidden" name="page" value="ecosplay-referrals" />
        <input type="hidden" name="tab" value="tremendous-logs" />
        <label for="ecos-tremendous-type"><?php esc_html_e( 'Type d\'événement', 'ecosplay-referrals' ); ?></label>
        <select id="ecos-tremendous-type" name="event_type">
            <option value=""><?php esc_html_e( 'Tous les types', 'ecosplay-referrals' ); ?></option>
            <?php foreach ( $event_types as $event_type ) : ?>
                <option value="<?php echo esc_attr( $event_type ); ?>" <?php selected( $filters['type'], $event_type ); ?>><?php echo esc_html( $event_type ); ?></option>
            <?php endforeach; ?>
        </select>
        <label for="ecos-tremendous-state"><?php esc_html_e( 'État ressource', 'ecosplay-referrals' ); ?></label>
        <input type="text" id="ecos-tremendous-state" name="state" value="<?php echo esc_attr( isset( $filters['state'] ) ? $filters['state'] : '' ); ?>" placeholder="<?php esc_attr_e( 'ex: FULLY_CREDITED', 'ecosplay-referrals' ); ?>" />
        <label for="ecos-tremendous-from"><?php esc_html_e( 'Du', 'ecosplay-referrals' ); ?></label>
        <input type="date" id="ecos-tremendous-from" name="from" value="<?php echo esc_attr( isset( $filters['from'] ) ? $filters['from'] : '' ); ?>" />
        <label for="ecos-tremendous-to"><?php esc_html_e( 'Au', 'ecosplay-referrals' ); ?></label>
        <input type="date" id="ecos-tremendous-to" name="to" value="<?php echo esc_attr( isset( $filters['to'] ) ? $filters['to'] : '' ); ?>" />
        <button type="submit" class="button"><?php esc_html_e( 'Filtrer', 'ecosplay-referrals' ); ?></button>
    </form>

    <?php if ( empty( $logs ) ) : ?>
        <p><?php esc_html_e( 'Aucun webhook Tremendous enregistré pour ces critères.', 'ecosplay-referrals' ); ?></p>
    <?php else : ?>
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Reçu le', 'ecosplay-referrals' ); ?></th>
                    <th><?php esc_html_e( 'Type', 'ecosplay-referrals' ); ?></th>
                    <th><?php esc_html_e( 'État', 'ecosplay-referrals' ); ?></th>
                    <th><?php esc_html_e( 'Statut de traitement', 'ecosplay-referrals' ); ?></th>
                    <th><?php esc_html_e( 'Payload', 'ecosplay-referrals' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $logs as $entry ) :
                    $received = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry->created_at );
                    $payload  = isset( $entry->payload ) ? $entry->payload : '';
                    $excerpt  = wp_strip_all_tags( $payload );
                    $excerpt  = wp_html_excerpt( $excerpt, 200, '&hellip;' );
                    $state    = isset( $entry->resource_state ) ? $entry->resource_state : '';
                    ?>
                    <tr>
                        <td><?php echo esc_html( $received ); ?></td>
                        <td><?php echo esc_html( $entry->event_type ); ?></td>
                        <td><?php echo esc_html( $state ); ?></td>
                        <td><?php echo esc_html( $entry->status ); ?></td>
                        <td><code><?php echo esc_html( $excerpt ); ?></code></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<div class="ecos-referrals-section">
    <h2><?php esc_html_e( 'Référence des événements Tremendous', 'ecosplay-referrals' ); ?></h2>
    <p><?php esc_html_e( 'Les catégories suivantes couvrent les événements clés envoyés par Tremendous pour le suivi des récompenses et de la trésorerie.', 'ecosplay-referrals' ); ?></p>
    <ul>
        <?php foreach ( $event_notes as $code => $description ) : ?>
            <li><strong><?php echo esc_html( $code ); ?></strong> — <?php echo esc_html( $description ); ?></li>
        <?php endforeach; ?>
    </ul>
</div>
