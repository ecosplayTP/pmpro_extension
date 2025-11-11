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

    /**
     * Builds the Tremendous API base URL according to the environment.
     *
     * @return string
     */
    protected function get_base_url() {
        $map = array(
            'sandbox'    => 'https://testflight.tremendous.com/api/v2/',
            'production' => 'https://api.tremendous.com/api/v2/',
        );

        return isset( $map[ $this->environment ] ) ? $map[ $this->environment ] : $map['production'];
    }

    /**
     * Executes an authenticated HTTP request against the Tremendous API.
     *
     * @param string                    $method HTTP method to use.
     * @param string                    $path   Relative endpoint path.
     * @param array<string,mixed>|null  $body   Optional request payload.
     *
     * @return array<string,mixed>|WP_Error
     */
    protected function request( $method, $path, $body = null ) {
        if ( ! $this->is_configured() ) {
            return new WP_Error( 'ecosplay_referrals_tremendous_unconfigured', __( 'L’intégration Tremendous est incomplète.', 'ecosplay-referrals' ) );
        }

        $url = trailingslashit( $this->get_base_url() ) . ltrim( (string) $path, '/' );

        $args = array(
            'method'  => strtoupper( (string) $method ),
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->secret_key,
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ),
            'timeout' => 15,
        );

        if ( null !== $body ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $code < 200 || $code >= 300 ) {
            $message = is_array( $data ) && isset( $data['error']['message'] ) ? (string) $data['error']['message'] : wp_remote_retrieve_response_message( $response );

            return new WP_Error( 'ecosplay_referrals_tremendous_http_error', $message ? $message : __( 'La requête Tremendous a échoué.', 'ecosplay-referrals' ) );
        }

        return is_array( $data ) ? $data : array();
    }

    /**
     * Creates a connected organization for the supplied payload.
     *
     * @param array<string,mixed> $payload Organization details.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function create_connected_organization( array $payload ) {
        return $this->request( 'POST', 'connected_organizations', array( 'connected_organization' => $payload ) );
    }

    /**
     * Retrieves a connected organization information by identifier.
     *
     * @param string $organization_id Connected organization identifier.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function get_connected_organization( $organization_id ) {
        $organization_id = trim( (string) $organization_id );

        if ( '' === $organization_id ) {
            return new WP_Error( 'ecosplay_referrals_tremendous_missing_org', __( 'L’identifiant Tremendous est manquant.', 'ecosplay-referrals' ) );
        }

        return $this->request( 'GET', 'connected_organizations/' . rawurlencode( $organization_id ) );
    }

    /**
     * Retrieves the available Tremendous funding balance in raw form.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function fetch_balance() {
        return $this->request( 'GET', 'funding_sources' );
    }

    /**
     * Returns the parsed Tremendous balance and related metadata.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function get_funding_source_balance() {
        $response = $this->fetch_balance();

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $sources = array();

        if ( isset( $response['funding_sources'] ) && is_array( $response['funding_sources'] ) ) {
            $sources = $response['funding_sources'];
        } elseif ( isset( $response['data'] ) && is_array( $response['data'] ) ) {
            $sources = $response['data'];
        } elseif ( is_array( $response ) ) {
            $sources = $response;
        }

        $result = array(
            'funding_source_id' => '',
            'available'         => 0.0,
            'currency'          => '',
            'method'            => '',
            'funding_source'    => null,
            'raw'               => $response,
        );

        foreach ( $sources as $entry ) {
            if ( ! is_array( $entry ) ) {
                continue;
            }

            $available = null;

            if ( isset( $entry['meta']['available_cents'] ) ) {
                $available = (float) $entry['meta']['available_cents'] / 100;
            } elseif ( isset( $entry['balance']['available'] ) ) {
                $available = (float) $entry['balance']['available'];
            } elseif ( isset( $entry['available_balance'] ) ) {
                $available = (float) $entry['available_balance'];
            }

            if ( null === $available ) {
                continue;
            }

            $permissions = isset( $entry['usage_permissions'] ) && is_array( $entry['usage_permissions'] )
                ? array_map( 'strtolower', array_map( 'strval', $entry['usage_permissions'] ) )
                : array();

            if ( ! empty( $permissions ) && ! array_intersect( $permissions, array( 'api_orders', 'balance_funding', 'dashboard_orders' ) ) ) {
                continue;
            }

            $method = isset( $entry['method'] ) ? strtolower( (string) $entry['method'] ) : '';

            if ( '' === $result['method'] && 'balance' !== $method && 0.0 !== $result['available'] ) {
                // Keep the previously selected balance-oriented source when present.
                continue;
            }

            $available = max( 0.0, round( $available, 2 ) );

            $should_replace = ( 'balance' === $method && 'balance' !== strtolower( (string) $result['method'] ) ) || $available > $result['available'];

            if ( ! $should_replace ) {
                continue;
            }

            $result['available']         = $available;
            $result['funding_source_id'] = isset( $entry['id'] ) ? (string) $entry['id'] : '';
            $result['currency']          = isset( $entry['currency'] ) ? strtoupper( (string) $entry['currency'] ) : $result['currency'];
            $result['method']            = isset( $entry['method'] ) ? (string) $entry['method'] : '';
            $result['funding_source']    = $entry;
        }

        return $result;
    }

    /**
     * Creates a Tremendous order for a recipient reward.
     *
     * @param array<string,mixed> $payload Order payload.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function create_order( array $payload ) {
        return $this->request( 'POST', 'orders', array( 'order' => $payload ) );
    }
}
