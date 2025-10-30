<?php
/**
 * Renders the referrals settings form.
 *
 * @package Ecosplay\Referrals
 * @file    wp-content/plugins/ecosplay-referrals/admin/views/settings.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<form method="post" action="options.php" class="ecos-referrals-settings">
    <?php
    settings_fields( 'ecosplay_referrals' );
    do_settings_sections( 'ecosplay_referrals' );
    submit_button();
    ?>
</form>
