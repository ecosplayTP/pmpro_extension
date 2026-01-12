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
    ?>
    <div class="ecos-referrals-stripe-diagnostic">
        <h2><?php esc_html_e( 'Diagnostic Stripe', 'ecosplay-referrals' ); ?></h2>
        <p>
            <button type="button" class="button ecos-referrals-stripe-test-trigger">
                <?php esc_html_e( 'Tester l’intégration Stripe', 'ecosplay-referrals' ); ?>
            </button>
        </p>
        <div class="ecos-referrals-stripe-test-report" aria-live="polite"></div>
    </div>
    <?php
    submit_button();
    ?>
</form>
