<?php
/**
 * Renders the referral usage log table.
 *
 * @package Ecosplay\Referrals
 * @file    wp-content/plugins/ecosplay-referrals/admin/views/usage.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<div class="ecos-referrals-filters">
    <form method="get" class="ecos-referrals-inline-form">
        <?php foreach ( $_GET as $key => $value ) :
            if ( in_array( $key, array( 'referral', 'per_page' ), true ) || is_array( $value ) ) {
                continue;
            }
            ?>
            <input type="hidden" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( wp_unslash( $value ) ); ?>" />
        <?php endforeach; ?>
        <label for="ecos-referrals-filter-referral" class="screen-reader-text"><?php esc_html_e( 'Filtrer par identifiant de parrainage', 'ecosplay-referrals' ); ?></label>
        <input type="number" id="ecos-referrals-filter-referral" name="referral" value="<?php echo esc_attr( $referral_id ); ?>" min="0" placeholder="<?php esc_attr_e( 'ID parrainage', 'ecosplay-referrals' ); ?>" />
        <label for="ecos-referrals-filter-count" class="screen-reader-text"><?php esc_html_e( 'Nombre de lignes à afficher', 'ecosplay-referrals' ); ?></label>
        <input type="number" id="ecos-referrals-filter-count" name="per_page" value="<?php echo isset( $_GET['per_page'] ) ? esc_attr( absint( $_GET['per_page'] ) ) : 50; ?>" min="1" />
        <?php submit_button( __( 'Filtrer', 'ecosplay-referrals' ), 'secondary', 'submit', false ); ?>
    </form>
</div>
<table class="wp-list-table widefat fixed striped">
    <thead>
        <tr>
            <th><?php esc_html_e( 'Date', 'ecosplay-referrals' ); ?></th>
            <th><?php esc_html_e( 'Code', 'ecosplay-referrals' ); ?></th>
            <th><?php esc_html_e( 'Commande', 'ecosplay-referrals' ); ?></th>
            <th><?php esc_html_e( 'Utilisateur', 'ecosplay-referrals' ); ?></th>
            <th><?php esc_html_e( 'Remise (€)', 'ecosplay-referrals' ); ?></th>
        </tr>
    </thead>
    <tbody>
        <?php if ( empty( $usage ) ) : ?>
            <tr>
                <td colspan="5"><?php esc_html_e( 'Aucune utilisation n\'a été enregistrée.', 'ecosplay-referrals' ); ?></td>
            </tr>
        <?php else : ?>
            <?php foreach ( $usage as $row ) :
                $ref_code = isset( $codes_index[ $row->referral_id ] ) ? $codes_index[ $row->referral_id ]->code : '';
                $member   = $row->used_by ? get_userdata( (int) $row->used_by ) : null;
                ?>
                <tr>
                    <td><?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $row->created_at ) ); ?></td>
                    <td><code><?php echo esc_html( $ref_code ); ?></code></td>
                    <td><?php echo $row->order_id ? esc_html( '#' . $row->order_id ) : '&mdash;'; ?></td>
                    <td>
                        <?php if ( $member ) : ?>
                            <a href="<?php echo esc_url( get_edit_user_link( (int) $row->used_by ) ); ?>"><?php echo esc_html( $member->display_name ); ?></a>
                        <?php else : ?>
                            &mdash;
                        <?php endif; ?>
                    </td>
                    <td><?php echo esc_html( number_format_i18n( (float) $row->discount_amount, 2 ) ); ?></td>
                </tr>
            <?php endforeach; ?>
        <?php endif; ?>
    </tbody>
</table>
