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

        add_shortcode( 'ecos_referral_points', array( $this, 'render_points' ) );
        add_shortcode( 'ecos_referral_link', array( $this, 'render_link' ) );
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
                'url'   => home_url( '/' ),
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
     * Validates if the current user is an eligible premium member.
     *
     * @return int
     */
    protected function resolve_authorized_user() {
        if ( ! is_user_logged_in() ) {
            return 0;
        }

        $user_id = get_current_user_id();

        if ( $user_id <= 0 ) {
            return 0;
        }

        if ( ! function_exists( 'pmpro_hasMembershipLevel' ) ) {
            return 0;
        }

        if ( ! pmpro_hasMembershipLevel( 'premium', $user_id ) ) {
            return 0;
        }

        return (int) $user_id;
    }
}
