<?php
/**
 * Renders aggregated referral statistics for administrators.
 *
 * @package Ecosplay\Referrals
 * @file    wp-content/plugins/ecosplay-referrals/admin/views/stats.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="ecos-referrals-summary">
    <div class="ecos-referrals-card">
        <h2><?php esc_html_e( 'Récompenses dues (€)', 'ecosplay-referrals' ); ?></h2>
        <p class="ecos-referrals-amount">€<?php echo esc_html( number_format_i18n( (float) $amount, 2 ) ); ?></p>
    </div>
    <div class="ecos-referrals-card">
        <h2><?php echo isset( $labels['discount'] ) ? esc_html( $labels['discount'] ) : esc_html__( 'Remises totales (€)', 'ecosplay-referrals' ); ?></h2>
        <p class="ecos-referrals-amount">€<?php echo esc_html( number_format_i18n( isset( $totals['discount'] ) ? (float) $totals['discount'] : 0.0, 2 ) ); ?></p>
    </div>
    <div class="ecos-referrals-card">
        <h2><?php esc_html_e( 'Période analysée', 'ecosplay-referrals' ); ?></h2>
        <p class="ecos-referrals-period"><?php echo esc_html( 'week' === $period ? __( 'Par semaine', 'ecosplay-referrals' ) : __( 'Par mois', 'ecosplay-referrals' ) ); ?></p>
    </div>
</div>
<table class="wp-list-table widefat fixed striped">
    <thead>
        <tr>
            <th><?php esc_html_e( 'Période', 'ecosplay-referrals' ); ?></th>
            <th><?php esc_html_e( 'Conversions', 'ecosplay-referrals' ); ?></th>
            <th><?php echo isset( $labels['discount'] ) ? esc_html( $labels['discount'] ) : esc_html__( 'Remises totales (€)', 'ecosplay-referrals' ); ?></th>
            <th><?php echo isset( $labels['reward'] ) ? esc_html( $labels['reward'] ) : esc_html__( 'Récompenses totales (€)', 'ecosplay-referrals' ); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php if ( empty( $stats['entries'] ) ) : ?>
            <tr>
                <td colspan="4"><?php esc_html_e( 'Aucune donnée disponible pour la période sélectionnée.', 'ecosplay-referrals' ); ?></td>
            </tr>
        <?php else : ?>
            <?php foreach ( $stats['entries'] as $entry ) : ?>
                <tr>
                    <td><?php echo esc_html( $entry->period_label ); ?></td>
                    <td><?php echo esc_html( (int) $entry->conversions ); ?></td>
                    <td><?php echo esc_html( number_format_i18n( (float) $entry->total_discount, 2 ) ); ?></td>
                    <td><?php echo esc_html( number_format_i18n( (float) $entry->total_reward, 2 ) ); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>
