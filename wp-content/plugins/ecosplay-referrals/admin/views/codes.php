<?php
/**
 * Renders the referrals codes administration table.
 *
 * @package Ecosplay\Referrals
 * @file    wp-content/plugins/ecosplay-referrals/admin/views/codes.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="ecos-referrals-actions">
    <form method="post" class="ecos-referrals-inline-form">
        <?php wp_nonce_field( 'ecosplay_referrals_regenerate_all' ); ?>
        <input type="hidden" name="ecosplay_referrals_action" value="regenerate_all" />
        <?php submit_button( __( 'Régénérer tous les codes', 'ecosplay-referrals' ), 'secondary', 'submit', false ); ?>
    </form>
    <form method="post" class="ecos-referrals-inline-form">
        <?php wp_nonce_field( 'ecosplay_referrals_reset_all' ); ?>
        <input type="hidden" name="ecosplay_referrals_action" value="reset_notifications" />
        <?php submit_button( __( 'Réinitialiser les notifications', 'ecosplay-referrals' ), 'secondary', 'submit', false ); ?>
    </form>
</div>
<table class="wp-list-table widefat fixed striped">
    <thead>
        <tr>
            <th><?php esc_html_e( 'Utilisateur', 'ecosplay-referrals' ); ?></th>
            <th><?php esc_html_e( 'Code', 'ecosplay-referrals' ); ?></th>
            <th><?php esc_html_e( 'Crédits gagnés', 'ecosplay-referrals' ); ?></th>
            <th><?php esc_html_e( 'Statut', 'ecosplay-referrals' ); ?></th>
            <th class="column-actions"><?php esc_html_e( 'Actions', 'ecosplay-referrals' ); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php if ( empty( $codes ) ) : ?>
            <tr>
                <td colspan="5"><?php esc_html_e( 'Aucun code n\'est disponible pour le moment.', 'ecosplay-referrals' ); ?></td>
            </tr>
        <?php else : ?>
            <?php foreach ( $codes as $code ) :
                $user      = get_userdata( (int) $code->user_id );
                $user_name = $user ? $user->display_name : sprintf( __( 'Utilisateur #%d', 'ecosplay-referrals' ), (int) $code->user_id );
                $edit_url  = $user ? get_edit_user_link( (int) $code->user_id ) : '';
                ?>
                <tr>
                    <td>
                        <?php if ( $edit_url ) : ?>
                            <a href="<?php echo esc_url( $edit_url ); ?>"><?php echo esc_html( $user_name ); ?></a>
                        <?php else : ?>
                            <?php echo esc_html( $user_name ); ?>
                        <?php endif; ?>
                    </td>
                    <td><code><?php echo esc_html( $code->code ); ?></code></td>
                    <td><?php echo esc_html( number_format_i18n( (float) $code->earned_credits, 2 ) ); ?></td>
                    <td>
                        <?php if ( ! empty( $code->is_active ) ) : ?>
                            <span class="status-enabled"><?php esc_html_e( 'Actif', 'ecosplay-referrals' ); ?></span>
                        <?php else : ?>
                            <span class="status-disabled"><?php esc_html_e( 'Inactif', 'ecosplay-referrals' ); ?></span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <form method="post" class="ecos-referrals-inline-form">
                            <?php wp_nonce_field( 'ecosplay_referrals_regenerate_' . (int) $code->user_id ); ?>
                            <input type="hidden" name="ecosplay_referrals_action" value="regenerate_single" />
                            <input type="hidden" name="user_id" value="<?php echo esc_attr( (int) $code->user_id ); ?>" />
                            <?php submit_button( __( 'Régénérer', 'ecosplay-referrals' ), 'link', 'submit', false ); ?>
                        </form>
                        <form method="post" class="ecos-referrals-inline-form">
                            <?php wp_nonce_field( 'ecosplay_referrals_reset_' . (int) $code->user_id ); ?>
                            <input type="hidden" name="ecosplay_referrals_action" value="reset_notifications" />
                            <input type="hidden" name="user_id" value="<?php echo esc_attr( (int) $code->user_id ); ?>" />
                            <?php submit_button( __( 'Réinitialiser la notif.', 'ecosplay-referrals' ), 'link', 'submit', false ); ?>
                        </form>
                    </td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>
