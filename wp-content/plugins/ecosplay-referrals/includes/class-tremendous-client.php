<?php
/**
 * Lightweight Tremendous HTTP client placeholder.
 *
 * @package Ecosplay\Referrals
 * @file    wp-content/plugins/ecosplay-referrals/includes/class-tremendous-client.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles Tremendous configuration for future API calls.
 */
class Ecosplay_Referrals_Tremendous_Client {
    /**
     * Secret API key used for authentication.
     *
     * @var string
     */
    protected $secret_key;

    /**
     * Active Tremendous campaign identifier.
     *
     * @var string
     */
    protected $campaign_id;

    /**
     * Target API environment.
     *
     * @var string
     */
    protected $environment;

    /**
     * Builds the client instance with decrypted secrets and context.
     *
     * @param string               $secret_key Tremendous API key.
     * @param array<string,string> $args       Extra configuration.
     */
    public function __construct( $secret_key, array $args = array() ) {
        $defaults = array(
            'environment' => 'production',
            'campaign_id' => '',
        );

        $args = array_merge( $defaults, array_intersect_key( $args, $defaults ) );

        $environment = strtolower( (string) $args['environment'] );

        if ( ! in_array( $environment, array( 'production', 'sandbox' ), true ) ) {
            $environment = $defaults['environment'];
        }

        $this->secret_key  = trim( (string) $secret_key );
        $this->environment = $environment;
        $this->campaign_id = sanitize_text_field( (string) $args['campaign_id'] );
    }

    /**
     * Checks whether the client is ready for authenticated calls.
     *
     * @return bool
     */
    public function is_configured() {
        return '' !== $this->secret_key && '' !== $this->campaign_id;
    }

    /**
     * Returns the configured campaign identifier.
     *
     * @return string
     */
    public function get_campaign_id() {
        return $this->campaign_id;
    }

    /**
     * Returns the selected Tremendous environment.
     *
     * @return string
     */
    public function get_environment() {
        return $this->environment;
    }
}
