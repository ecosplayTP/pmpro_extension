<?php
/**
 * Minimal Stripe HTTP client for ECOSplay referrals.
 *
 * @package Ecosplay\Referrals
 * @file    wp-content/plugins/ecosplay-referrals/includes/class-stripe-client.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles authenticated HTTP requests to the Stripe API.
 */
class Ecosplay_Referrals_Stripe_Client {
    const API_BASE = 'https://api.stripe.com';

    /**
     * Secret API key used for authentication.
     *
     * @var string
     */
    protected $secret_key;

    /**
     * Builds the client instance with a decrypted secret key.
     *
     * @param string $secret_key Stripe secret key.
     */
    public function __construct( $secret_key ) {
        $this->secret_key = trim( (string) $secret_key );
    }

    /**
     * Checks whether the client is ready to issue requests.
     *
     * @return bool
     */
    public function is_configured() {
        return '' !== $this->secret_key;
    }

    /**
     * Creates a Connect account.
     *
     * @param array<string,mixed> $params Account creation parameters.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function create_account( array $params ) {
        $params = wp_parse_args(
            $params,
            array(
                'type'        => 'express',
                'capabilities'=> array( 'transfers' => array( 'requested' => true ) ),
            )
        );

        return $this->request( 'POST', '/v1/accounts', $params );
    }

    /**
     * Retrieves a Connect account state.
     *
     * @param string $account_id Stripe account identifier.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function retrieve_account( $account_id ) {
        $account_id = trim( (string) $account_id );

        if ( '' === $account_id ) {
            return new WP_Error( 'ecosplay_stripe_missing_account', __( 'Identifiant de compte Stripe manquant.', 'ecosplay-referrals' ) );
        }

        return $this->request( 'GET', '/v1/accounts/' . $account_id );
    }

    /**
     * Retrieves the platform Stripe account details.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function get_account() {
        return $this->request( 'GET', '/v1/account' );
    }

    /**
     * Generates an account link for onboarding or refresh flows.
     *
     * @param string               $account_id Stripe account identifier.
     * @param array<string,string> $params     Additional parameters.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function create_account_link( $account_id, array $params ) {
        $account_id = trim( (string) $account_id );

        if ( '' === $account_id ) {
            return new WP_Error( 'ecosplay_stripe_missing_account', __( 'Identifiant de compte Stripe manquant.', 'ecosplay-referrals' ) );
        }

        $params = wp_parse_args(
            $params,
            array(
                'type' => 'account_onboarding',
            )
        );

        $params['account'] = $account_id;

        return $this->request( 'POST', '/v1/account_links', $params );
    }

    /**
     * Issues a login link for the Express dashboard.
     *
     * @param string $account_id Stripe account identifier.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function create_login_link( $account_id ) {
        $account_id = trim( (string) $account_id );

        if ( '' === $account_id ) {
            return new WP_Error( 'ecosplay_stripe_missing_account', __( 'Identifiant de compte Stripe manquant.', 'ecosplay-referrals' ) );
        }

        return $this->request( 'POST', '/v1/accounts/' . $account_id . '/login_links' );
    }

    /**
     * Creates a transfer towards a connected account.
     *
     * @param array<string,mixed> $params Transfer parameters.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function create_transfer( array $params ) {
        return $this->request( 'POST', '/v1/transfers', $params );
    }

    /**
     * Cancels a pending transfer when permitted.
     *
     * @param string $transfer_id Stripe transfer identifier.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function cancel_transfer( $transfer_id ) {
        $transfer_id = trim( (string) $transfer_id );

        if ( '' === $transfer_id ) {
            return new WP_Error( 'ecosplay_stripe_missing_transfer', __( 'Identifiant de transfert manquant.', 'ecosplay-referrals' ) );
        }

        return $this->request( 'POST', '/v1/transfers/' . $transfer_id . '/cancel' );
    }

    /**
     * Retrieves the platform balance snapshot.
     *
     * @return array<string,mixed>|WP_Error
     */
    public function get_balance() {
        return $this->request( 'GET', '/v1/balance' );
    }

    /**
     * Centralised HTTP request handler with error normalisation.
     *
     * @param string               $method  HTTP verb.
     * @param string               $path    Endpoint path.
     * @param array<string,mixed>  $params  Request parameters.
     * @param array<string,string> $headers Extra headers.
     *
     * @return array<string,mixed>|WP_Error
     */
    protected function request( $method, $path, array $params = array(), array $headers = array() ) {
        if ( ! $this->is_configured() ) {
            return new WP_Error( 'ecosplay_stripe_missing_secret', __( 'Aucune clé Stripe n\'est configurée.', 'ecosplay-referrals' ) );
        }

        $url = trailingslashit( self::API_BASE ) . ltrim( $path, '/' );

        $args = array(
            'method'  => strtoupper( $method ),
            'timeout' => 20,
            'headers' => array_merge(
                array(
                    'Authorization' => 'Bearer ' . $this->secret_key,
                    'User-Agent'    => 'ECOSplay Referrals/1.0',
                ),
                $headers
            ),
        );

        $prepared_params = $this->prepare_params( $params );

        if ( 'GET' === $args['method'] ) {
            if ( ! empty( $prepared_params ) ) {
                $url = add_query_arg( $prepared_params, $url );
            }
        } else {
            $args['body']    = $prepared_params;
            $args['headers'] = array_merge( $args['headers'], array( 'Content-Type' => 'application/x-www-form-urlencoded' ) );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code( $response );
        $body   = wp_remote_retrieve_body( $response );
        $data   = json_decode( $body, true );

        if ( null === $data && '' !== trim( (string) $body ) ) {
            return new WP_Error( 'ecosplay_stripe_invalid_response', __( 'Réponse Stripe invalide.', 'ecosplay-referrals' ), array( 'body' => $body ) );
        }

        if ( $status < 200 || $status >= 300 ) {
            $error_message = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'Erreur de communication avec Stripe.', 'ecosplay-referrals' );
            $error_code    = isset( $data['error']['code'] ) ? $data['error']['code'] : 'ecosplay_stripe_http_error';

            return new WP_Error( $error_code, $error_message, array( 'status' => $status, 'body' => $data ) );
        }

        return is_array( $data ) ? $data : array();
    }

    /**
     * Converts parameters into Stripe compatible payloads.
     *
     * @param array<string,mixed> $params Parameters to normalise.
     *
     * @return array<string,mixed>
     */
    protected function prepare_params( array $params ) {
        foreach ( $params as $key => $value ) {
            if ( is_bool( $value ) ) {
                $params[ $key ] = $value ? 'true' : 'false';
            } elseif ( is_array( $value ) ) {
                $params[ $key ] = $this->prepare_params( $value );
            }
        }

        return $params;
    }
}
