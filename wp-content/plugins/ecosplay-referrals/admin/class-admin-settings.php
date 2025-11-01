<?php
/**
 * Settings controller wiring the referrals configuration screen.
 *
 * @package Ecosplay\Referrals
 * @file    wp-content/plugins/ecosplay-referrals/admin/class-admin-settings.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Manages the Settings API integration and view rendering.
 */
class Ecosplay_Referrals_Admin_Settings {
    /**
     * Option storage key.
     *
     * @var string
     */
    protected $option_name = 'ecosplay_referrals_options';

    /**
     * Default configuration values.
     *
     * @var array<string,mixed>
     */
    protected $defaults = array(
        'discount_amount' => Ecosplay_Referrals_Service::DISCOUNT_EUR,
        'reward_amount'   => Ecosplay_Referrals_Service::REWARD_POINTS,
        'allowed_levels'  => Ecosplay_Referrals_Service::DEFAULT_ALLOWED_LEVELS,
        'notice_message'  => Ecosplay_Referrals_Service::DEFAULT_NOTICE_MESSAGE,
    );

    /**
     * Domain logic dependency.
     *
     * @var Ecosplay_Referrals_Service
     */
    protected $service;

    /**
     * Hooks settings registration and runtime filters.
     *
     * @param Ecosplay_Referrals_Service $service Domain service.
     */
    public function __construct( Ecosplay_Referrals_Service $service ) {
        $this->service = $service;

        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_filter( 'ecosplay_referrals_discount_amount', array( $this, 'filter_discount' ) );
        add_filter( 'ecosplay_referrals_reward_amount', array( $this, 'filter_reward' ) );
        add_filter( 'ecosplay_referrals_allowed_levels', array( $this, 'filter_allowed_levels' ) );
        add_filter( 'ecosplay_referrals_notice_message', array( $this, 'filter_notice_message' ) );
    }

    /**
     * Supplies the tab slug handled here.
     *
     * @return string
     */
    public function get_slug() {
        return 'settings';
    }

    /**
     * Supplies the heading for the settings tab.
     *
     * @return string
     */
    public function get_title() {
        return __( 'Réglages du parrainage', 'ecosplay-referrals' );
    }

    /**
     * Placeholder to respect the controller contract.
     *
     * @return void
     */
    public function handle() {}

    /**
     * Registers option fields with the WordPress Settings API.
     *
     * @return void
     */
    public function register_settings() {
        register_setting( 'ecosplay_referrals', $this->option_name, array( $this, 'sanitize_options' ) );

        add_settings_section(
            'ecos_referrals_amounts',
            __( 'Montants de parrainage', 'ecosplay-referrals' ),
            '__return_false',
            'ecosplay_referrals'
        );

        add_settings_field(
            'ecos_referrals_discount',
            __( 'Remise accordée (€)', 'ecosplay-referrals' ),
            array( $this, 'render_discount_field' ),
            'ecosplay_referrals',
            'ecos_referrals_amounts'
        );

        add_settings_field(
            'ecos_referrals_reward',
            __( 'Crédits gagnés', 'ecosplay-referrals' ),
            array( $this, 'render_reward_field' ),
            'ecosplay_referrals',
            'ecos_referrals_amounts'
        );

        add_settings_section(
            'ecos_referrals_levels',
            __( 'Niveaux éligibles', 'ecosplay-referrals' ),
            '__return_false',
            'ecosplay_referrals'
        );

        add_settings_field(
            'ecos_referrals_allowed_levels',
            __( 'Identifiants ou slugs autorisés', 'ecosplay-referrals' ),
            array( $this, 'render_allowed_levels_field' ),
            'ecosplay_referrals',
            'ecos_referrals_levels'
        );

        add_settings_section(
            'ecos_referrals_notice',
            __( 'Notification front-end', 'ecosplay-referrals' ),
            '__return_false',
            'ecosplay_referrals'
        );

        add_settings_field(
            'ecos_referrals_notice_message',
            __( 'Message affiché', 'ecosplay-referrals' ),
            array( $this, 'render_notice_message_field' ),
            'ecosplay_referrals',
            'ecos_referrals_notice'
        );
    }

    /**
     * Validates and sanitizes option inputs before saving.
     *
     * @param array<string,mixed> $input Raw submitted values.
     *
     * @return array<string,mixed>
     */
    public function sanitize_options( $input ) {
        $sanitized = array();

        $discount = isset( $input['discount_amount'] ) ? $this->sanitize_amount( $input['discount_amount'] ) : $this->defaults['discount_amount'];
        $reward   = isset( $input['reward_amount'] ) ? $this->sanitize_amount( $input['reward_amount'] ) : $this->defaults['reward_amount'];
        $levels   = isset( $input['allowed_levels'] ) ? $this->sanitize_allowed_levels( $input['allowed_levels'] ) : $this->defaults['allowed_levels'];
        $message  = isset( $input['notice_message'] ) ? $this->sanitize_notice_message( $input['notice_message'] ) : $this->defaults['notice_message'];

        $sanitized['discount_amount'] = $discount;
        $sanitized['reward_amount']   = $reward;
        $sanitized['allowed_levels']  = $levels;
        $sanitized['notice_message']  = $message;

        add_settings_error( 'ecosplay_referrals', 'settings_saved', __( 'Réglages enregistrés.', 'ecosplay-referrals' ), 'updated' );

        return $sanitized;
    }

    /**
     * Outputs the settings form view.
     *
     * @return void
     */
    public function render() {
        $options = $this->get_options();

        include ECOSPLAY_REFERRALS_ADMIN . 'views/settings.php';
    }

    /**
     * Displays the discount input field.
     *
     * @return void
     */
    public function render_discount_field() {
        $options  = $this->get_options();
        $discount = isset( $options['discount_amount'] ) ? $options['discount_amount'] : $this->defaults['discount_amount'];

        printf(
            '<input type="number" class="small-text" name="%1$s[discount_amount]" value="%2$s" step="0.01" min="0" />',
            esc_attr( $this->option_name ),
            esc_attr( $discount )
        );
    }

    /**
     * Displays the reward input field.
     *
     * @return void
     */
    public function render_reward_field() {
        $options = $this->get_options();
        $reward  = isset( $options['reward_amount'] ) ? $options['reward_amount'] : $this->defaults['reward_amount'];

        printf(
            '<input type="number" class="small-text" name="%1$s[reward_amount]" value="%2$s" step="0.01" min="0" />',
            esc_attr( $this->option_name ),
            esc_attr( $reward )
        );
    }

    /**
     * Displays the allowed levels textarea field.
     *
     * @return void
     */
    public function render_allowed_levels_field() {
        $options = $this->get_options();
        $levels  = isset( $options['allowed_levels'] ) ? (array) $options['allowed_levels'] : $this->defaults['allowed_levels'];
        $value   = implode( "\n", array_map( 'strval', $levels ) );

        printf(
            '<textarea class="large-text code" rows="4" name="%1$s[allowed_levels]" placeholder="pmpro_role_2">%2$s</textarea>' .
            '<p class="description">%3$s</p>',
            esc_attr( $this->option_name ),
            esc_textarea( $value ),
            esc_html__( 'Un identifiant ou slug par ligne.', 'ecosplay-referrals' )
        );
    }

    /**
     * Displays the floating notice message field.
     *
     * @return void
     */
    public function render_notice_message_field() {
        $options = $this->get_options();
        $message = isset( $options['notice_message'] ) ? (string) $options['notice_message'] : $this->defaults['notice_message'];

        printf(
            '<textarea class="large-text" rows="3" name="%1$s[notice_message]" placeholder="Parrainez vos amis">%2$s</textarea>' .
            '<p class="description">%3$s</p>',
            esc_attr( $this->option_name ),
            esc_textarea( $message ),
            esc_html__( 'Texte présenté dans la notification flottante.', 'ecosplay-referrals' )
        );
    }

    /**
     * Adjusts the discount amount exposed to the front end.
     *
     * @param float $value Default discount.
     *
     * @return float
     */
    public function filter_discount( $value ) {
        $options = $this->get_options();

        if ( isset( $options['discount_amount'] ) ) {
            return (float) $options['discount_amount'];
        }

        return (float) $value;
    }

    /**
     * Adjusts the reward amount exposed to the front end.
     *
     * @param float $value Default reward.
     *
     * @return float
     */
    public function filter_reward( $value ) {
        $options = $this->get_options();

        if ( isset( $options['reward_amount'] ) ) {
            return (float) $options['reward_amount'];
        }

        return (float) $value;
    }

    /**
     * Adjusts the allowed membership level list exposed to the service.
     *
     * @param array<int|string> $values Default allow list.
     *
     * @return array<int|string>
     */
    public function filter_allowed_levels( $values ) {
        $options = $this->get_options();

        if ( isset( $options['allowed_levels'] ) ) {
            return (array) $options['allowed_levels'];
        }

        return (array) $values;
    }

    /**
     * Adjusts the floating notice message exposed to the UI.
     *
     * @param string $value Default message.
     *
     * @return string
     */
    public function filter_notice_message( $value ) {
        $options = $this->get_options();

        if ( isset( $options['notice_message'] ) ) {
            return (string) $options['notice_message'];
        }

        return (string) $value;
    }

    /**
     * Retrieves the persisted options merged with defaults.
     *
     * @return array<string,mixed>
     */
    protected function get_options() {
        $stored = get_option( $this->option_name, array() );

        if ( ! is_array( $stored ) ) {
            $stored = array();
        }

        return array_merge( $this->defaults, $stored );
    }

    /**
     * Normalizes numeric input ensuring positive floats.
     *
     * @param mixed $value Raw input value.
     *
     * @return float
     */
    protected function sanitize_amount( $value ) {
        $amount = floatval( $value );

        if ( $amount < 0 ) {
            $amount = 0.0;
        }

        return round( $amount, 2 );
    }

    /**
     * Normalizes the multiline allow list input.
     *
     * @param mixed $value Raw field content.
     *
     * @return array<int|string>
     */
    protected function sanitize_allowed_levels( $value ) {
        if ( is_array( $value ) ) {
            $lines = $value;
        } else {
            $lines = preg_split( '/\r\n|\r|\n/', (string) $value );
        }

        $sanitized = array();

        foreach ( (array) $lines as $line ) {
            if ( is_numeric( $line ) ) {
                $sanitized[] = (int) $line;
                continue;
            }

            $key = sanitize_key( $line );

            if ( '' !== $key ) {
                $sanitized[] = $key;
            }
        }

        return array_values( array_unique( $sanitized, SORT_REGULAR ) );
    }

    /**
     * Normalizes the floating notice message input.
     *
     * @param mixed $value Raw input value.
     *
     * @return string
     */
    protected function sanitize_notice_message( $value ) {
        $message = sanitize_textarea_field( (string) $value );

        if ( '' === trim( $message ) ) {
            return $this->defaults['notice_message'];
        }

        return $message;
    }
}
