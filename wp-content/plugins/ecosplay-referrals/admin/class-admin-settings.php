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
     * AJAX action used for Stripe diagnostics.
     */
    const STRIPE_DIAGNOSTIC_ACTION = 'ecosplay_referrals_stripe_test';

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
        'discount_amount'      => Ecosplay_Referrals_Service::DISCOUNT_EUR,
        'reward_amount'        => Ecosplay_Referrals_Service::REWARD_POINTS,
        'allowed_levels'       => Ecosplay_Referrals_Service::DEFAULT_ALLOWED_LEVELS,
        'notice_message'       => Ecosplay_Referrals_Service::DEFAULT_NOTICE_MESSAGE,
        'stripe_secret_key'       => '',
        'stripe_secret_exists'    => false,
        'stripe_enabled'          => false,
        'tremendous_secret_key'   => '',
        'tremendous_secret_exists'=> false,
        'tremendous_enabled'      => false,
        'tremendous_campaign_id'  => '',
        'tremendous_environment'  => 'production',
        'balance_alert_threshold' => 0.0,
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

        $this->ensure_option_not_autoloaded();

        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'wp_ajax_' . self::STRIPE_DIAGNOSTIC_ACTION, array( $this, 'handle_stripe_diagnostic' ) );
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

        add_settings_section(
            'ecos_referrals_stripe',
            __( 'Stripe', 'ecosplay-referrals' ),
            '__return_false',
            'ecosplay_referrals'
        );

        add_settings_field(
            'ecos_referrals_stripe_secret_key',
            __( 'Clé secrète Stripe', 'ecosplay-referrals' ),
            array( $this, 'render_stripe_secret_field' ),
            'ecosplay_referrals',
            'ecos_referrals_stripe'
        );

        add_settings_field(
            'ecos_referrals_balance_alert_threshold',
            __( 'Seuil d’alerte solde Stripe (€)', 'ecosplay-referrals' ),
            array( $this, 'render_balance_threshold_field' ),
            'ecosplay_referrals',
            'ecos_referrals_stripe'
        );

        add_settings_field(
            'ecos_referrals_stripe_enabled',
            __( 'Activer l’intégration Stripe', 'ecosplay-referrals' ),
            array( $this, 'render_stripe_enabled_field' ),
            'ecosplay_referrals',
            'ecos_referrals_stripe'
        );

        add_settings_section(
            'ecos_referrals_tremendous',
            __( 'Tremendous', 'ecosplay-referrals' ),
            '__return_false',
            'ecosplay_referrals'
        );

        add_settings_field(
            'ecos_referrals_tremendous_enabled',
            __( 'Activer l’intégration Tremendous', 'ecosplay-referrals' ),
            array( $this, 'render_tremendous_enabled_field' ),
            'ecosplay_referrals',
            'ecos_referrals_tremendous'
        );

        add_settings_field(
            'ecos_referrals_tremendous_secret_key',
            __( 'Clé API Tremendous', 'ecosplay-referrals' ),
            array( $this, 'render_tremendous_secret_field' ),
            'ecosplay_referrals',
            'ecos_referrals_tremendous'
        );

        add_settings_field(
            'ecos_referrals_tremendous_campaign_id',
            __( 'Identifiant de campagne Tremendous', 'ecosplay-referrals' ),
            array( $this, 'render_tremendous_campaign_field' ),
            'ecosplay_referrals',
            'ecos_referrals_tremendous'
        );

        add_settings_field(
            'ecos_referrals_tremendous_environment',
            __( 'Environnement Tremendous', 'ecosplay-referrals' ),
            array( $this, 'render_tremendous_environment_field' ),
            'ecosplay_referrals',
            'ecos_referrals_tremendous'
        );
    }

    /**
     * Handles the Stripe diagnostic AJAX request.
     *
     * @return void
     */
    public function handle_stripe_diagnostic() {
        check_ajax_referer( self::STRIPE_DIAGNOSTIC_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Accès refusé.', 'ecosplay-referrals' ) ) );
        }

        $report = $this->service->run_stripe_diagnostics();

        wp_send_json_success( $report );
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
        $stored    = get_option( $this->option_name, array() );

        if ( ! is_array( $stored ) ) {
            $stored = array();
        }

        $discount          = isset( $input['discount_amount'] ) ? $this->sanitize_amount( $input['discount_amount'] ) : $this->defaults['discount_amount'];
        $reward            = isset( $input['reward_amount'] ) ? $this->sanitize_amount( $input['reward_amount'] ) : $this->defaults['reward_amount'];
        $levels            = isset( $input['allowed_levels'] ) ? $this->sanitize_allowed_levels( $input['allowed_levels'] ) : $this->defaults['allowed_levels'];
        $message           = isset( $input['notice_message'] ) ? $this->sanitize_notice_message( $input['notice_message'] ) : $this->defaults['notice_message'];
        $stripe_edit       = ! empty( $input['stripe_secret_edit'] ) && '1' === (string) $input['stripe_secret_edit'];
        $secret            = $stripe_edit && isset( $input['stripe_secret_key'] ) ? $this->sanitize_stripe_secret( $input['stripe_secret_key'] ) : null;
        $tremendous_secret = isset( $input['tremendous_secret_key'] ) ? $this->sanitize_tremendous_secret( $input['tremendous_secret_key'] ) : null;
        $threshold         = isset( $input['balance_alert_threshold'] ) ? $this->sanitize_amount( $input['balance_alert_threshold'] ) : $this->defaults['balance_alert_threshold'];
        $campaign          = isset( $input['tremendous_campaign_id'] ) ? $this->sanitize_tremendous_campaign_id( $input['tremendous_campaign_id'] ) : $this->defaults['tremendous_campaign_id'];
        $environment       = isset( $input['tremendous_environment'] ) ? $this->sanitize_tremendous_environment( $input['tremendous_environment'] ) : $this->defaults['tremendous_environment'];
        $stored_message    = array_key_exists( 'notice_message', $stored ) ? (string) $stored['notice_message'] : null;
        $message_changed   = null === $stored_message || $message !== $stored_message;

        $sanitized['discount_amount'] = $discount;
        $sanitized['reward_amount']   = $reward;
        $sanitized['allowed_levels']  = $levels;
        $sanitized['notice_message']  = $message;
        $sanitized['balance_alert_threshold'] = $threshold;
        $sanitized['stripe_enabled']          = ! empty( $input['stripe_enabled'] );
        $sanitized['tremendous_enabled']      = ! empty( $input['tremendous_enabled'] );
        $sanitized['tremendous_campaign_id']  = $campaign;
        $sanitized['tremendous_environment']  = $environment;

        if ( $message_changed ) {
            $this->service->reset_notifications( null );
        }

        if ( null !== $secret ) {
            $sanitized['stripe_secret_key'] = $secret['cipher'];
            $sanitized['stripe_secret_exists'] = $secret['exists'];
        } else {
            if ( array_key_exists( 'stripe_secret_key', $stored ) ) {
                $sanitized['stripe_secret_key']    = (string) $stored['stripe_secret_key'];
                $sanitized['stripe_secret_exists'] = ! empty( $stored['stripe_secret_key'] );
            }
        }

        if ( ! isset( $sanitized['stripe_secret_exists'] ) ) {
            $sanitized['stripe_secret_exists'] = false;
        }

        if ( null !== $tremendous_secret ) {
            $sanitized['tremendous_secret_key']    = $tremendous_secret['cipher'];
            $sanitized['tremendous_secret_exists'] = $tremendous_secret['exists'];
        } else {
            if ( array_key_exists( 'tremendous_secret_key', $stored ) ) {
                $sanitized['tremendous_secret_key']    = (string) $stored['tremendous_secret_key'];
                $sanitized['tremendous_secret_exists'] = ! empty( $stored['tremendous_secret_key'] );
            }
        }

        if ( ! isset( $sanitized['tremendous_secret_exists'] ) ) {
            $sanitized['tremendous_secret_exists'] = false;
        }

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
     * Displays the balance threshold input.
     *
     * @return void
     */
    public function render_balance_threshold_field() {
        $options   = $this->get_options();
        $threshold = isset( $options['balance_alert_threshold'] ) ? $options['balance_alert_threshold'] : $this->defaults['balance_alert_threshold'];

        printf(
            '<input type="number" class="small-text" name="%1$s[balance_alert_threshold]" value="%2$s" step="0.01" min="0" />' .
            '<p class="description">%3$s</p>',
            esc_attr( $this->option_name ),
            esc_attr( $threshold ),
            esc_html__( 'Une alerte quotidienne est envoyée lorsque le solde disponible passe sous ce seuil.', 'ecosplay-referrals' )
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
        $recognized = $this->get_recognized_allowed_levels( $levels );
        $recognized_markup = '';

        if ( ! empty( $recognized ) ) {
            $recognized_markup = sprintf(
                '<p class="description"><strong>%1$s</strong> %2$s</p>',
                esc_html__( 'Identifiants reconnus :', 'ecosplay-referrals' ),
                esc_html( implode( ', ', array_map( 'strval', $recognized ) ) )
            );
        }

        printf(
            '<textarea class="large-text code" rows="4" name="%1$s[allowed_levels]" placeholder="pmpro_role_2">%2$s</textarea>' .
            '<p class="description">%3$s</p>%4$s',
            esc_attr( $this->option_name ),
            esc_textarea( $value ),
            esc_html__( 'Un identifiant numérique, un slug ou pmpro_role_{id} par ligne.', 'ecosplay-referrals' ),
            $recognized_markup
        );
    }

    /**
     * Builds the list of configured identifiers that match existing levels.
     *
     * @param array<int|string> $allowed_levels Normalized allow list entries.
     *
     * @return array<int|string>
     */
    protected function get_recognized_allowed_levels( array $allowed_levels ) {
        if ( empty( $allowed_levels ) || ! function_exists( 'pmpro_getAllLevels' ) ) {
            return array();
        }

        $levels = pmpro_getAllLevels();

        if ( ! is_array( $levels ) ) {
            return array();
        }

        $level_ids   = array();
        $level_slugs = array();

        foreach ( $levels as $level ) {
            if ( is_object( $level ) ) {
                if ( isset( $level->id ) ) {
                    $level_ids[ (int) $level->id ] = true;
                }

                if ( isset( $level->ID ) ) {
                    $level_ids[ (int) $level->ID ] = true;
                }

                if ( isset( $level->name ) && is_string( $level->name ) ) {
                    $slug = sanitize_key( $level->name );

                    if ( '' !== $slug ) {
                        $level_slugs[ $slug ] = true;
                    }
                }
            }
        }

        $recognized = array();

        foreach ( $allowed_levels as $level ) {
            if ( is_string( $level ) && 0 === strpos( $level, 'pmpro_role_' ) ) {
                if ( preg_match( '/^pmpro_role_(\d+)$/', $level, $matches ) ) {
                    $level_id = (int) $matches[1];

                    if ( isset( $level_ids[ $level_id ] ) ) {
                        $recognized[] = 'pmpro_role_' . $level_id;
                    }
                }

                continue;
            }

            if ( is_numeric( $level ) ) {
                $level_id = (int) $level;

                if ( isset( $level_ids[ $level_id ] ) ) {
                    $recognized[] = $level_id;
                }

                continue;
            }

            if ( is_string( $level ) ) {
                $slug = sanitize_key( $level );

                if ( '' !== $slug && isset( $level_slugs[ $slug ] ) ) {
                    $recognized[] = $slug;
                }
            }
        }

        return array_values( array_unique( $recognized, SORT_REGULAR ) );
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
            esc_html__( 'Texte présenté dans la notification flottante. Vous pouvez inclure des liens HTML.', 'ecosplay-referrals' )
        );
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

        $merged = array_merge( $this->defaults, $stored );

        $merged['stripe_enabled'] = ! empty( $merged['stripe_enabled'] );
        $merged['tremendous_enabled'] = ! empty( $merged['tremendous_enabled'] );

        if ( ! empty( $merged['stripe_secret_key'] ) ) {
            $merged['stripe_secret_exists'] = true;
            $merged['stripe_secret_key']    = '';
        } else {
            $merged['stripe_secret_exists'] = false;
            $merged['stripe_secret_key']    = '';
        }

        if ( ! empty( $merged['tremendous_secret_key'] ) ) {
            $merged['tremendous_secret_exists'] = true;
            $merged['tremendous_secret_key']    = '';
        } else {
            $merged['tremendous_secret_exists'] = false;
            $merged['tremendous_secret_key']    = '';
        }

        if ( ! in_array( $merged['tremendous_environment'], array( 'production', 'sandbox' ), true ) ) {
            $merged['tremendous_environment'] = $this->defaults['tremendous_environment'];
        }

        $merged['tremendous_campaign_id'] = is_string( $merged['tremendous_campaign_id'] ) ? $merged['tremendous_campaign_id'] : '';

        return $merged;
    }

    /**
     * Displays the Stripe enable toggle checkbox.
     *
     * @return void
     */
    public function render_stripe_enabled_field() {
        $options = $this->get_options();
        $checked = ! empty( $options['stripe_enabled'] );

        printf(
            '<label><input type="checkbox" name="%1$s[stripe_enabled]" value="1" %2$s /> %3$s</label>',
            esc_attr( $this->option_name ),
            checked( $checked, true, false ),
            esc_html__( 'Activer l’intégration Stripe', 'ecosplay-referrals' )
        );
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
        $message = trim( (string) $value );

        if ( '' === trim( $message ) ) {
            return '';
        }

        $message = make_clickable( $message );

        return wp_kses(
            $message,
            array(
                'a'      => array(
                    'href'   => true,
                    'target' => true,
                    'rel'    => true,
                ),
                'br'     => array(),
                'strong' => array(),
                'em'     => array(),
            )
        );
    }

    /**
     * Encrypts the Stripe secret value while preserving existing storage.
     *
     * @param string $value Raw secret submitted.
     *
     * @return array{cipher:string,exists:bool}|null
     */
    protected function sanitize_stripe_secret( $value ) {
        $submitted = trim( (string) $value );

        if ( '' === $submitted ) {
            $stored = get_option( $this->option_name, array() );

            if ( is_array( $stored ) && ! empty( $stored['stripe_secret_key'] ) ) {
                return array(
                    'cipher' => (string) $stored['stripe_secret_key'],
                    'exists' => true,
                );
            }

            return array(
                'cipher' => '',
                'exists' => false,
            );
        }

        $cipher = Ecosplay_Referrals_Stripe_Secrets::encrypt( $submitted );

        if ( '' === $cipher ) {
            add_settings_error( 'ecosplay_referrals', 'stripe_secret_error', __( 'La clé Stripe n’a pas pu être chiffrée.', 'ecosplay-referrals' ), 'error' );

            return null;
        }

        return array(
            'cipher' => $cipher,
            'exists' => true,
        );
    }

    /**
     * Encrypts the Tremendous secret value while preserving existing storage.
     *
     * @param string $value Raw secret submitted.
     *
     * @return array{cipher:string,exists:bool}|null
     */
    protected function sanitize_tremendous_secret( $value ) {
        $submitted = trim( (string) $value );

        if ( '' === $submitted ) {
            $stored = get_option( $this->option_name, array() );

            if ( is_array( $stored ) && ! empty( $stored['tremendous_secret_key'] ) ) {
                return array(
                    'cipher' => (string) $stored['tremendous_secret_key'],
                    'exists' => true,
                );
            }

            return array(
                'cipher' => '',
                'exists' => false,
            );
        }

        $cipher = Ecosplay_Referrals_Tremendous_Secrets::encrypt( $submitted );

        if ( '' === $cipher ) {
            add_settings_error( 'ecosplay_referrals', 'tremendous_secret_error', __( 'La clé Tremendous n’a pas pu être chiffrée.', 'ecosplay-referrals' ), 'error' );

            return null;
        }

        return array(
            'cipher' => $cipher,
            'exists' => true,
        );
    }

    /**
     * Normalizes the Tremendous campaign identifier input.
     *
     * @param mixed $value Raw input value.
     *
     * @return string
     */
    protected function sanitize_tremendous_campaign_id( $value ) {
        $campaign = sanitize_text_field( (string) $value );

        return substr( $campaign, 0, 100 );
    }

    /**
     * Normalizes the Tremendous environment selector.
     *
     * @param mixed $value Raw input value.
     *
     * @return string
     */
    protected function sanitize_tremendous_environment( $value ) {
        $environment = strtolower( (string) $value );

        if ( ! in_array( $environment, array( 'production', 'sandbox' ), true ) ) {
            return $this->defaults['tremendous_environment'];
        }

        return $environment;
    }

    /**
     * Outputs the Stripe secret input while keeping it masked.
     *
     * @return void
     */
    public function render_stripe_secret_field() {
        $options      = $this->get_options();
        $has_existing = ! empty( $options['stripe_secret_exists'] );
        $description  = $has_existing
            ? __( 'Une clé est déjà enregistrée. Laissez vide pour la conserver.', 'ecosplay-referrals' )
            : __( 'Saisissez la clé secrète Stripe à chiffrer et stocker.', 'ecosplay-referrals' );

        echo '<button type="button" class="button ecos-referrals-stripe-secret-toggle">' .
            esc_html__( 'Modifier la clé secrète Stripe', 'ecosplay-referrals' ) .
            '</button>';

        printf(
            '<input type="hidden" class="ecos-referrals-stripe-secret-edit" name="%1$s[stripe_secret_edit]" value="0" />',
            esc_attr( $this->option_name )
        );

        echo '<div class="ecos-referrals-stripe-secret is-hidden">';

        printf(
            '<input type="password" class="regular-text ecos-referrals-stripe-secret-input" name="%1$s[stripe_secret_key]" value="" autocomplete="new-password" autocapitalize="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true" placeholder="sk_live_********" disabled />',
            esc_attr( $this->option_name )
        );

        echo '<p class="description">' . esc_html( $description ) . '</p>';
        echo '</div>';
    }

    /**
     * Displays the Tremendous enable toggle checkbox.
     *
     * @return void
     */
    public function render_tremendous_enabled_field() {
        $options = $this->get_options();
        $checked = ! empty( $options['tremendous_enabled'] );

        printf(
            '<label><input type="checkbox" name="%1$s[tremendous_enabled]" value="1" %2$s /> %3$s</label>',
            esc_attr( $this->option_name ),
            checked( $checked, true, false ),
            esc_html__( 'Activer l’intégration Tremendous', 'ecosplay-referrals' )
        );
    }

    /**
     * Outputs the Tremendous secret input while keeping it masked.
     *
     * @return void
     */
    public function render_tremendous_secret_field() {
        $options      = $this->get_options();
        $has_existing = ! empty( $options['tremendous_secret_exists'] );

        printf(
            '<input type="password" class="regular-text" name="%1$s[tremendous_secret_key]" value="" autocomplete="new-password" autocapitalize="off" spellcheck="false" data-lpignore="true" data-1p-ignore="true" placeholder="trm_live_********" />',
            esc_attr( $this->option_name )
        );

        if ( $has_existing ) {
            echo '<p class="description">' . esc_html__( 'Une clé Tremendous est enregistrée. Laissez vide pour la conserver.', 'ecosplay-referrals' ) . '</p>';
        } else {
            echo '<p class="description">' . esc_html__( 'Saisissez la clé API Tremendous à chiffrer et stocker.', 'ecosplay-referrals' ) . '</p>';
        }
    }

    /**
     * Displays the Tremendous campaign identifier field.
     *
     * @return void
     */
    public function render_tremendous_campaign_field() {
        $options  = $this->get_options();
        $campaign = isset( $options['tremendous_campaign_id'] ) ? (string) $options['tremendous_campaign_id'] : $this->defaults['tremendous_campaign_id'];

        printf(
            '<input type="text" class="regular-text" name="%1$s[tremendous_campaign_id]" value="%2$s" placeholder="camp_********" />',
            esc_attr( $this->option_name ),
            esc_attr( $campaign )
        );

        echo '<p class="description">' . esc_html__( 'Identifiant de campagne transmis aux récompenses Tremendous.', 'ecosplay-referrals' ) . '</p>';
    }

    /**
     * Displays the Tremendous environment selector.
     *
     * @return void
     */
    public function render_tremendous_environment_field() {
        $options     = $this->get_options();
        $environment = isset( $options['tremendous_environment'] ) ? (string) $options['tremendous_environment'] : $this->defaults['tremendous_environment'];
        $choices     = array(
            'production' => __( 'Production', 'ecosplay-referrals' ),
            'sandbox'    => __( 'Sandbox', 'ecosplay-referrals' ),
        );

        printf( '<select name="%1$s[tremendous_environment]">', esc_attr( $this->option_name ) );

        foreach ( $choices as $value => $label ) {
            printf(
                '<option value="%1$s" %2$s>%3$s</option>',
                esc_attr( $value ),
                selected( $environment, $value, false ),
                esc_html( $label )
            );
        }

        echo '</select>';
        echo '<p class="description">' . esc_html__( 'Choisissez l’environnement Tremendous ciblé.', 'ecosplay-referrals' ) . '</p>';
    }

    /**
     * Forces the option to be stored without autoload.
     *
     * @return void
     */
    protected function ensure_option_not_autoloaded() {
        $sentinel = new stdClass();
        $value    = get_option( $this->option_name, $sentinel );

        if ( $sentinel === $value ) {
            add_option( $this->option_name, array(), '', 'no' );
            return;
        }

        update_option( $this->option_name, $value, false );
    }
}
