<?php
/**
 * Front-end shortcodes exposing referral data.
 *
 * @package Ecosplay\Referrals
 * @file    wp-content/plugins/ecosplay-referrals/includes/class-referrals-shortcodes.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers referral-related shortcodes for premium members.
 */
class Ecosplay_Referrals_Shortcodes {
    /**
     * Domain service access point.
     *
     * @var Ecosplay_Referrals_Service
     */
    protected $service;

    /**
     * Hooks shortcode registrations on instantiation.
     *
     * @param Ecosplay_Referrals_Service $service Referral service instance.
     */
    public function __construct( Ecosplay_Referrals_Service $service ) {
        $this->service = $service;

        add_shortcode( 'ecos_referral_code', array( $this, 'render_code' ) );
        add_shortcode( 'ecos_referral_points', array( $this, 'render_points' ) );
        add_shortcode( 'ecos_referral_link', array( $this, 'render_link' ) );
    }

    /**
     * Renders the referral code with a copy button for premium members.
     *
     * @param array<string,mixed> $atts Shortcode attributes.
     *
     * @return string
     */
    public function render_code( $atts = array() ) {
        $user_id = $this->resolve_authorized_user();

        if ( ! $user_id ) {
            return '';
        }

        $code = $this->service->get_member_code( $user_id );

        if ( '' === $code ) {
            return '';
        }

        $atts = shortcode_atts(
            array(
                'button_text' => __( 'Copier le code', 'ecosplay-referrals' ),
                'copied_text' => __( 'Code copié', 'ecosplay-referrals' ),
            ),
            $atts,
            'ecos_referral_code'
        );

        $button_label = wp_strip_all_tags( (string) $atts['button_text'] );
        $copied_label = wp_strip_all_tags( (string) $atts['copied_text'] );
        $button_text  = esc_html( $button_label );
        $button_attr  = esc_attr( $button_label );
        $copied_attr  = esc_attr( $copied_label );

        $this->enqueue_code_assets();

        return sprintf(
            '<div class="ecos-referral-code" data-ecos-referral-code><span class="ecos-referral-code__value" data-ecos-referral-value>%1$s</span><button type="button" class="ecos-referral-code__copy" data-ecos-referral-copy data-ecos-referral-label="%2$s" data-ecos-referral-copied-label="%3$s">%4$s</button><span class="ecos-referral-code__status screen-reader-text" data-ecos-referral-status aria-live="polite"></span></div>',
            esc_html( $code ),
            $button_attr,
            $copied_attr,
            $button_text
        );
    }

    /**
     * Renders the referral points for the current premium member.
     *
     * @param array<string,mixed> $atts Shortcode attributes.
     *
     * @return string
     */
    public function render_points( $atts = array() ) {
        $user_id = $this->resolve_authorized_user();

        if ( ! $user_id ) {
            return '';
        }

        $atts = shortcode_atts(
            array(
                'decimals' => 0,
            ),
            $atts,
            'ecos_referral_points'
        );

        $decimals = absint( $atts['decimals'] );
        $points   = $this->service->get_member_points( $user_id );
        $display  = number_format_i18n( $points, $decimals );

        return sprintf(
            '<span class="ecos-referral-points">%s</span>',
            esc_html( $display )
        );
    }

    /**
     * Outputs the referral link for the current premium member.
     *
     * @param array<string,mixed> $atts Shortcode attributes.
     *
     * @return string
     */
    public function render_link( $atts = array() ) {
        if ( ! is_user_logged_in() ) {
            return '';
        }

        $user_id = get_current_user_id();

        if ( $user_id <= 0 ) {
            return '';
        }

        if ( ! $this->service->is_user_allowed( $user_id ) ) {
            return sprintf(
                '<span class="ecos-referral-link ecos-referral-link--unauthorized">%s</span>',
                esc_html__( "Votre niveau d'adhésion ne permet pas le parrainage.", 'ecosplay-referrals' )
            );
        }

        $code = $this->service->get_member_code( $user_id );

        if ( '' === $code ) {
            return '';
        }

        $atts = shortcode_atts(
            array(
                'url'   => home_url( '/paiement-dadhesion/' ),
                'text'  => '',
                'param' => 'ref',
            ),
            $atts,
            'ecos_referral_link'
        );

        $base_url = esc_url_raw( $atts['url'] );

        if ( '' === $base_url ) {
            $base_url = home_url( '/' );
        }

        $param = sanitize_key( $atts['param'] );

        if ( '' === $param ) {
            $param = 'ref';
        }

        $link  = add_query_arg( $param, rawurlencode( $code ), $base_url );
        $label = trim( wp_strip_all_tags( (string) $atts['text'] ) );

        if ( '' === $label ) {
            $label = sprintf(
                /* translators: %s: referral code. */
                __( 'Mon lien de parrainage (%s)', 'ecosplay-referrals' ),
                $code
            );
        }

        return sprintf(
            '<a class="ecos-referral-link" href="%s" rel="nofollow noopener">%s</a>',
            esc_url( $link ),
            esc_html( $label )
        );
    }

    /**
     * Validates if the current user is an eligible premium member via the service helper.
     *
     * @return int Authorized user identifier or zero when unavailable.
     */
    protected function resolve_authorized_user() {
        if ( ! is_user_logged_in() ) {
            return 0;
        }

        $user_id = get_current_user_id();

        if ( $user_id <= 0 ) {
            return 0;
        }

        if ( ! $this->service->is_user_allowed( $user_id ) ) {
            return 0;
        }

        return (int) $user_id;
    }

    /**
     * Enqueues the front-end asset used to copy referral codes.
     *
     * @return void
     */
    protected function enqueue_code_assets() {
        if ( wp_script_is( 'ecosplay-referrals-code-copy', 'enqueued' ) ) {
            return;
        }

        wp_enqueue_script(
            'ecosplay-referrals-code-copy',
            ECOSPLAY_REFERRALS_URL . 'assets/js/referral-code.js',
            array(),
            ecosplay_referrals_get_asset_version( 'assets/js/referral-code.js' ),
            true
        );
    }
}
