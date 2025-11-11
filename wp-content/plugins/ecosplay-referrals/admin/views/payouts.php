<?php
/**
 * Payouts administration view.
 *
 * @package Ecosplay\Referrals
 * @file    wp-content/plugins/ecosplay-referrals/admin/views/payouts.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="ecos-referrals-section">
    <p><?php esc_html_e( 'Gérez les comptes Stripe Connect, déclenchez des virements et suivez les soldes restants.', 'ecosplay-referrals' ); ?></p>

    <?php if ( empty( $rows ) ) : ?>
        <p><?php esc_html_e( 'Aucun parrain actif pour le moment.', 'ecosplay-referrals' ); ?></p>
    <?php else : ?>
        <table class="wp-list-table widefat striped">
            <thead>
                <tr>
                    <th><?php esc_html_e( 'Parrain', 'ecosplay-referrals' ); ?></th>
                    <th><?php esc_html_e( 'Compte Stripe', 'ecosplay-referrals' ); ?></th>
                    <th><?php esc_html_e( 'Statut KYC', 'ecosplay-referrals' ); ?></th>
                    <th><?php esc_html_e( 'Total gagné (€)', 'ecosplay-referrals' ); ?></th>
                    <th><?php esc_html_e( 'Total payé (€)', 'ecosplay-referrals' ); ?></th>
                    <th><?php esc_html_e( 'Solde (€)', 'ecosplay-referrals' ); ?></th>
                    <th><?php esc_html_e( 'Dernière activité', 'ecosplay-referrals' ); ?></th>
                    <th><?php esc_html_e( 'Actions', 'ecosplay-referrals' ); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ( $rows as $row ) :
                    $user_link = get_edit_user_link( (int) $row->user_id );
                    $history   = isset( $payouts[ (int) $row->user_id ] ) ? $payouts[ (int) $row->user_id ] : array();
                    ?>
                    <tr>
                        <td>
                            <strong>
                                <?php if ( $user_link ) : ?>
                                    <a href="<?php echo esc_url( $user_link ); ?>">
                                        <?php echo esc_html( $row->display_name ); ?>
                                    </a>
                                <?php else : ?>
                                    <?php echo esc_html( $row->display_name ); ?>
                                <?php endif; ?>
                            </strong>
                            <br />
                            <span class="description"><?php echo esc_html( $row->user_email ); ?></span>
                        </td>
                        <td>
                            <?php echo $row->stripe_account_id ? esc_html( $row->stripe_account_id ) : '&mdash;'; ?>
                            <?php if ( ! empty( $row->pending_amount ) ) : ?>
                                <br />
                                <span class="description"><?php printf( esc_html__( 'En attente : %s€', 'ecosplay-referrals' ), esc_html( number_format_i18n( (float) $row->pending_amount, 2 ) ) ); ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html( $row->kyc_status ); ?></td>
                        <td><?php echo esc_html( number_format_i18n( (float) $row->earned_credits, 2 ) ); ?></td>
                        <td><?php echo esc_html( number_format_i18n( (float) $row->total_paid, 2 ) ); ?></td>
                        <td><?php echo esc_html( number_format_i18n( (float) $row->balance, 2 ) ); ?></td>
                        <td><?php echo esc_html( $row->last_activity_human ); ?></td>
                        <td>
                            <div class="ecos-referrals-actions">
                                <form method="post" class="ecos-referrals-inline-form">
                                    <?php wp_nonce_field( 'ecosplay_referrals_onboard_' . (int) $row->user_id ); ?>
                                    <input type="hidden" name="ecosplay_referrals_action" value="send_onboarding" />
                                    <input type="hidden" name="user_id" value="<?php echo esc_attr( (int) $row->user_id ); ?>" />
                                    <button type="submit" class="button button-secondary"><?php esc_html_e( 'Lien onboarding', 'ecosplay-referrals' ); ?></button>
                                </form>
                                <form method="post" class="ecos-referrals-inline-form">
                                    <?php wp_nonce_field( 'ecosplay_referrals_dashboard_' . (int) $row->user_id ); ?>
                                    <input type="hidden" name="ecosplay_referrals_action" value="open_dashboard" />
                                    <input type="hidden" name="user_id" value="<?php echo esc_attr( (int) $row->user_id ); ?>" />
                                    <button type="submit" class="button button-secondary"><?php esc_html_e( 'Dashboard Express', 'ecosplay-referrals' ); ?></button>
                                </form>
                                <form method="post" class="ecos-referrals-inline-form">
                                    <?php wp_nonce_field( 'ecosplay_referrals_transfer_' . (int) $row->user_id ); ?>
                                    <input type="hidden" name="ecosplay_referrals_action" value="trigger_transfer" />
                                    <input type="hidden" name="user_id" value="<?php echo esc_attr( (int) $row->user_id ); ?>" />
                                    <label class="screen-reader-text" for="ecos-transfer-<?php echo esc_attr( (int) $row->user_id ); ?>"><?php esc_html_e( 'Montant du transfert', 'ecosplay-referrals' ); ?></label>
                                    <input type="number" step="0.01" min="0" id="ecos-transfer-<?php echo esc_attr( (int) $row->user_id ); ?>" name="amount" value="<?php echo esc_attr( number_format( max( 0, (float) $row->balance ), 2, '.', '' ) ); ?>" class="small-text" />
                                    <button type="submit" class="button button-primary"><?php esc_html_e( 'Créer un transfert', 'ecosplay-referrals' ); ?></button>
                                </form>
                                <form method="post" class="ecos-referrals-inline-form">
                                    <?php wp_nonce_field( 'ecosplay_referrals_manual_' . (int) $row->user_id ); ?>
                                    <input type="hidden" name="ecosplay_referrals_action" value="mark_manual" />
                                    <input type="hidden" name="user_id" value="<?php echo esc_attr( (int) $row->user_id ); ?>" />
                                    <label class="screen-reader-text" for="ecos-manual-<?php echo esc_attr( (int) $row->user_id ); ?>"><?php esc_html_e( 'Montant du paiement manuel', 'ecosplay-referrals' ); ?></label>
                                    <input type="number" step="0.01" min="0" id="ecos-manual-<?php echo esc_attr( (int) $row->user_id ); ?>" name="amount" value="<?php echo esc_attr( number_format( max( 0, (float) $row->balance ), 2, '.', '' ) ); ?>" class="small-text" />
                                    <input type="text" name="note" placeholder="<?php esc_attr_e( 'Note', 'ecosplay-referrals' ); ?>" class="regular-text" />
                                    <button type="submit" class="button"><?php esc_html_e( 'Paiement manuel', 'ecosplay-referrals' ); ?></button>
                                </form>
                            </div>
                            <?php if ( ! empty( $history ) ) : ?>
                                <details class="ecos-referrals-history">
                                    <summary><?php esc_html_e( 'Historique des virements', 'ecosplay-referrals' ); ?></summary>
                                    <ul>
                                        <?php foreach ( $history as $entry ) :
                                            $created = mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $entry->created_at );
                                            ?>
                                            <li>
                                                <strong><?php echo esc_html( sprintf( '%s€', number_format_i18n( (float) $entry->amount, 2 ) ) ); ?></strong>
                                                <?php echo esc_html( sprintf( __( '(%1$s) - %2$s', 'ecosplay-referrals' ), strtoupper( $entry->currency ), $entry->status ) ); ?>
                                                <span class="description">&mdash; <?php echo esc_html( $created ); ?></span>
                                                <?php if ( in_array( strtolower( $entry->status ), array( 'pending', 'created' ), true ) && ! empty( $entry->transfer_id ) ) : ?>
                                                    <form method="post" class="ecos-referrals-inline-form">
                                                        <?php wp_nonce_field( 'ecosplay_referrals_cancel_' . (int) $row->user_id ); ?>
                                                        <input type="hidden" name="ecosplay_referrals_action" value="cancel_transfer" />
                                                        <input type="hidden" name="user_id" value="<?php echo esc_attr( (int) $row->user_id ); ?>" />
                                                        <input type="hidden" name="transfer_id" value="<?php echo esc_attr( $entry->transfer_id ); ?>" />
                                                        <button type="submit" class="button-link-delete"><?php esc_html_e( 'Annuler', 'ecosplay-referrals' ); ?></button>
                                                    </form>
                                                <?php endif; ?>
                                                <?php if ( ! empty( $entry->failure_message ) ) : ?>
                                                    <br /><span class="error"><?php echo esc_html( $entry->failure_message ); ?></span>
                                                <?php endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                    </ul>
                                </details>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
