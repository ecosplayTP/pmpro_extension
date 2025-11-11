<?php
/**
 * REST endpoint handling Tremendous webhook events.
 *
 * @package Ecosplay\Referrals
 * @file    wp-content/plugins/ecosplay-referrals/includes/class-tremendous-webhooks.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers a REST route to log Tremendous webhook events with signature checks.
 */
class Ecosplay_Referrals_Tremendous_Webhooks {
    /**
     * Persistence layer.
     *
     * @var Ecosplay_Referrals_Store
     */
    protected $store;

    /**
     * Boots the webhook handler.
     *
     * @param Ecosplay_Referrals_Store $store Data access layer.
     */
    public function __construct( Ecosplay_Referrals_Store $store ) {
        $this->store = $store;

        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    /**
     * Registers the Tremendous webhook endpoint.
     *
     * @return void
     */
    public function register_routes() {
        register_rest_route(
            'ecosplay-referrals/v1',
            '/tremendous',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'handle_event' ),
                'permission_callback' => '__return_true',
            )
        );
    }

    /**
     * Processes incoming Tremendous webhook events and stores them.
     *
     * @param WP_REST_Request $request REST request instance.
     *
     * @return WP_REST_Response
     */
    public function handle_event( WP_REST_Request $request ) {
        $raw_body = $request->get_body();
        $payload  = $this->normalise_payload( $request->get_json_params(), $raw_body );

        if ( ! ecosplay_referrals_is_tremendous_enabled() ) {
            $this->log_event( $payload, 'disabled' );

            return new WP_REST_Response( array( 'received' => false, 'reason' => 'disabled' ), 403 );
        }

        $secret = ecosplay_referrals_get_tremendous_secret();

        if ( '' === $secret ) {
            $this->log_event( $payload, 'unconfigured' );

            return new WP_REST_Response( array( 'received' => false, 'reason' => 'unconfigured' ), 403 );
        }

        if ( ! $this->verify_signature( $request, $secret, $raw_body ) ) {
            $this->log_event( $payload, 'invalid_signature' );

            return new WP_REST_Response( array( 'received' => false, 'reason' => 'invalid_signature' ), 401 );
        }

        if ( empty( $payload ) ) {
            $this->log_event( array( 'raw_body' => $raw_body ), 'invalid_payload' );

            return new WP_REST_Response( array( 'received' => false, 'reason' => 'invalid_payload' ), 400 );
        }

        $event_type    = $this->extract_event_type( $payload );
        $resource_state = $this->extract_resource_state( $payload );

        $this->store->log_webhook_event( $event_type, 'processed', $payload, 'tremendous', $resource_state );

        do_action( 'ecosplay_referrals_tremendous_event_received', $event_type, $payload, $resource_state );

        return new WP_REST_Response( array( 'received' => true ), 200 );
    }

    /**
     * Validates the request signature using the stored Tremendous secret.
     *
     * @param WP_REST_Request $request REST request instance.
     * @param string          $secret  Tremendous API secret.
     * @param string          $raw     Raw request body.
     *
     * @return bool
     */
    protected function verify_signature( WP_REST_Request $request, $secret, $raw ) {
        $header = $this->get_signature_header( $request );

        if ( '' === $header ) {
            return false;
        }

        $parsed = $this->parse_signature_header( $header );
        $base   = $parsed['timestamp'] ? $parsed['timestamp'] . '.' . $raw : $raw;
        $valid  = hash_hmac( 'sha256', $base, $secret );

        if ( hash_equals( $valid, $parsed['signature'] ) ) {
            return true;
        }

        if ( '' !== $parsed['timestamp'] ) {
            return false;
        }

        return hash_equals( hash_hmac( 'sha256', $raw, $secret ), $parsed['signature'] );
    }

    /**
     * Extracts the Tremendous signature header value.
     *
     * @param WP_REST_Request $request REST request instance.
     *
     * @return string
     */
    protected function get_signature_header( WP_REST_Request $request ) {
        $headers = $request->get_headers();
        $keys    = array( 'tremendous-signature', 'x-tremendous-signature' );

        foreach ( $keys as $key ) {
            if ( isset( $headers[ $key ] ) && ! empty( $headers[ $key ] ) ) {
                $value = $headers[ $key ];

                return is_array( $value ) ? (string) reset( $value ) : (string) $value;
            }
        }

        $server_keys = array( 'HTTP_TREMENDOUS_SIGNATURE', 'HTTP_X_TREMENDOUS_SIGNATURE' );

        foreach ( $server_keys as $key ) {
            if ( isset( $_SERVER[ $key ] ) && '' !== $_SERVER[ $key ] ) {
                return (string) $_SERVER[ $key ];
            }
        }

        return '';
    }

    /**
     * Parses the Tremendous signature header format.
     *
     * @param string $header Raw header value.
     *
     * @return array{signature:string,timestamp:string}
     */
    protected function parse_signature_header( $header ) {
        $signature = trim( (string) $header );
        $timestamp = '';

        if ( false !== strpos( $signature, '=' ) ) {
            $parts = explode( ',', $signature );

            foreach ( $parts as $part ) {
                $pair = array_map( 'trim', explode( '=', $part, 2 ) );

                if ( 2 !== count( $pair ) ) {
                    continue;
                }

                if ( 't' === strtolower( $pair[0] ) ) {
                    $timestamp = $pair[1];
                }

                if ( 'v1' === strtolower( $pair[0] ) ) {
                    $signature = $pair[1];
                }
            }
        }

        return array(
            'signature' => $signature,
            'timestamp' => $timestamp,
        );
    }

    /**
     * Ensures an array payload is always available for logging.
     *
     * @param mixed  $data     Decoded JSON payload.
     * @param string $raw_body Raw request body.
     *
     * @return array<string,mixed>
     */
    protected function normalise_payload( $data, $raw_body ) {
        if ( is_array( $data ) ) {
            return $data;
        }

        $decoded = json_decode( (string) $raw_body, true );

        return is_array( $decoded ) ? $decoded : array();
    }

    /**
     * Extracts an event type from the webhook payload.
     *
     * @param array<string,mixed> $payload Webhook payload.
     *
     * @return string
     */
    protected function extract_event_type( array $payload ) {
        $candidates = array(
            $this->read_string( $payload, 'type' ),
            $this->read_string( $payload, 'event_type' ),
            $this->read_string( $payload, 'event' ),
            $this->read_nested_string( $payload, array( 'data', 'type' ) ),
            $this->read_string( $payload, 'topic' ),
            $this->read_string( $payload, 'name' ),
        );

        foreach ( $candidates as $candidate ) {
            if ( '' !== $candidate ) {
                $candidate = strtoupper( str_replace( ' ', '_', $candidate ) );

                return $candidate;
            }
        }

        return 'UNKNOWN';
    }

    /**
     * Extracts a resource state from the webhook payload.
     *
     * @param array<string,mixed> $payload Webhook payload.
     *
     * @return string
     */
    protected function extract_resource_state( array $payload ) {
        $paths = array(
            array( 'data', 'attributes', 'status' ),
            array( 'data', 'attributes', 'state' ),
            array( 'data', 'status' ),
            array( 'resource', 'status' ),
            array( 'resource', 'state' ),
            array( 'status' ),
        );

        foreach ( $paths as $path ) {
            $value = $this->read_nested_string( $payload, $path );

            if ( '' !== $value ) {
                return strtoupper( str_replace( ' ', '_', $value ) );
            }
        }

        return '';
    }

    /**
     * Safely logs an event with the Tremendous provider flag.
     *
     * @param array<string,mixed> $payload Logged payload.
     * @param string              $status  Storage status.
     *
     * @return void
     */
    protected function log_event( array $payload, $status ) {
        $type  = $this->extract_event_type( $payload );
        $state = $this->extract_resource_state( $payload );

        $this->store->log_webhook_event( $type, $status, $payload, 'tremendous', $state );
    }

    /**
     * Reads a scalar value from an array.
     *
     * @param array<string,mixed> $payload Source array.
     * @param string              $key     Array key.
     *
     * @return string
     */
    protected function read_string( array $payload, $key ) {
        if ( isset( $payload[ $key ] ) && is_scalar( $payload[ $key ] ) ) {
            return trim( (string) $payload[ $key ] );
        }

        return '';
    }

    /**
     * Reads a scalar value following a path in a nested array.
     *
     * @param array<string,mixed> $payload Source array.
     * @param array<int,string>   $path    Path to traverse.
     *
     * @return string
     */
    protected function read_nested_string( array $payload, array $path ) {
        $value = $payload;

        foreach ( $path as $segment ) {
            if ( ! is_array( $value ) || ! array_key_exists( $segment, $value ) ) {
                return '';
            }

            $value = $value[ $segment ];
        }

        if ( is_scalar( $value ) ) {
            return trim( (string) $value );
        }

        return '';
    }
}
