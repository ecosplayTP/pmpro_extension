<?php
/**
 * REST endpoint handling Stripe webhook events.
 *
 * @package Ecosplay\Referrals
 * @file    wp-content/plugins/ecosplay-referrals/includes/class-stripe-webhooks.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Registers a REST route to keep the payout ledger in sync with Stripe.
 */
class Ecosplay_Referrals_Stripe_Webhooks {
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
     * Registers the webhook endpoint.
     *
     * @return void
     */
    public function register_routes() {
        register_rest_route(
            'ecosplay-referrals/v1',
            '/stripe',
            array(
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => array( $this, 'handle_event' ),
                'permission_callback' => '__return_true',
            )
        );
    }

    /**
     * Processes incoming webhook events and updates the ledger.
     *
     * @param WP_REST_Request $request REST request instance.
     *
     * @return WP_REST_Response
     */
    public function handle_event( WP_REST_Request $request ) {
        $event = $request->get_json_params();

        if ( empty( $event['type'] ) || empty( $event['data']['object'] ) ) {
            return new WP_REST_Response( array( 'received' => false ), 400 );
        }

        $type   = (string) $event['type'];
        $object = (array) $event['data']['object'];

        switch ( $type ) {
            case 'account.updated':
                $this->handle_account_updated( $object );
                break;
            case 'transfer.created':
            case 'transfer.updated':
                $this->handle_transfer_event( $object, 'created' );
                break;
            case 'transfer.failed':
                $this->handle_transfer_event( $object, 'failed' );
                break;
            case 'transfer.reversed':
                $this->handle_transfer_event( $object, 'reversed' );
                break;
            case 'payout.created':
                $this->handle_payout_event( $object, 'created' );
                break;
            case 'payout.paid':
                $this->handle_payout_event( $object, 'paid' );
                break;
            case 'payout.failed':
                $this->handle_payout_event( $object, 'failed' );
                break;
            case 'topup.succeeded':
                $this->handle_topup_event( $object, 'succeeded' );
                break;
            case 'topup.failed':
                $this->handle_topup_event( $object, 'failed' );
                break;
        }

        do_action( 'ecosplay_referrals_stripe_event_handled', $type, $object, $event );

        return new WP_REST_Response( array( 'received' => true ), 200 );
    }

    /**
     * Updates stored capabilities when Stripe sends account changes.
     *
     * @param array<string,mixed> $account Account payload.
     *
     * @return void
     */
    protected function handle_account_updated( array $account ) {
        if ( empty( $account['id'] ) ) {
            return;
        }

        $referral = $this->store->get_referral_by_account( $account['id'] );

        if ( ! $referral ) {
            return;
        }

        $capabilities = array();

        if ( isset( $account['capabilities'] ) && is_array( $account['capabilities'] ) ) {
            $capabilities = $account['capabilities'];
        }

        $this->store->save_stripe_account( (int) $referral->user_id, $account['id'], $capabilities );
    }

    /**
     * Synchronises transfer events with the payouts ledger.
     *
     * @param array<string,mixed> $transfer Transfer payload.
     * @param string              $status   Status label to apply.
     *
     * @return void
     */
    protected function handle_transfer_event( array $transfer, $status ) {
        if ( empty( $transfer['destination'] ) || empty( $transfer['id'] ) ) {
            return;
        }

        $referral = $this->store->get_referral_by_account( $transfer['destination'] );

        if ( ! $referral ) {
            return;
        }

        $fields = array(
            'metadata' => isset( $transfer['metadata'] ) ? $transfer['metadata'] : array(),
        );

        if ( isset( $transfer['destination_payment'] ) ) {
            $fields['payout_id'] = $transfer['destination_payment'];
        }

        if ( isset( $transfer['failure_code'] ) ) {
            $fields['failure_code'] = $transfer['failure_code'];
        }

        if ( isset( $transfer['failure_message'] ) ) {
            $fields['failure_message'] = $transfer['failure_message'];
        }

        $updated = $this->store->update_payout_by_transfer( $transfer['id'], $status, $fields );

        if ( $updated ) {
            return;
        }

        $amount   = isset( $transfer['amount'] ) ? ( (float) $transfer['amount'] ) / 100 : 0;
        $currency = isset( $transfer['currency'] ) ? $transfer['currency'] : 'eur';

        $this->store->record_payout_event(
            array(
                'user_id'        => (int) $referral->user_id,
                'referral_id'    => (int) $referral->id,
                'amount'         => $amount,
                'currency'       => $currency,
                'status'         => $status,
                'transfer_id'    => $transfer['id'],
                'payout_id'      => isset( $transfer['destination_payment'] ) ? $transfer['destination_payment'] : null,
                'failure_code'   => isset( $transfer['failure_code'] ) ? $transfer['failure_code'] : null,
                'failure_message'=> isset( $transfer['failure_message'] ) ? $transfer['failure_message'] : null,
                'metadata'       => $fields['metadata'],
            )
        );

        if ( in_array( strtolower( $status ), array( 'created', 'paid', 'succeeded' ), true ) ) {
            $this->store->increment_total_paid( (int) $referral->id, $amount );
        }
    }

    /**
     * Applies payout webhook events to the ledger.
     *
     * @param array<string,mixed> $payout Payout payload.
     * @param string              $status Status label.
     *
     * @return void
     */
    protected function handle_payout_event( array $payout, $status ) {
        if ( empty( $payout['id'] ) ) {
            return;
        }

        $fields = array(
            'metadata'        => isset( $payout['metadata'] ) ? $payout['metadata'] : array(),
            'failure_code'    => isset( $payout['failure_code'] ) ? $payout['failure_code'] : null,
            'failure_message' => isset( $payout['failure_message'] ) ? $payout['failure_message'] : null,
        );

        $updated = $this->store->update_payout_by_payout( $payout['id'], $status, $fields );

        if ( $updated ) {
            return;
        }

        $amount   = isset( $payout['amount'] ) ? ( (float) $payout['amount'] ) / 100 : 0;
        $currency = isset( $payout['currency'] ) ? $payout['currency'] : 'eur';

        $this->store->record_payout_event(
            array(
                'user_id'        => 0,
                'referral_id'    => 0,
                'amount'         => $amount,
                'currency'       => $currency,
                'status'         => $status,
                'payout_id'      => $payout['id'],
                'failure_code'   => $fields['failure_code'],
                'failure_message'=> $fields['failure_message'],
                'metadata'       => $fields['metadata'],
            )
        );
    }

    /**
     * Persists topup information for audit purposes.
     *
     * @param array<string,mixed> $topup  Topup payload.
     * @param string              $status Status label.
     *
     * @return void
     */
    protected function handle_topup_event( array $topup, $status ) {
        $amount   = isset( $topup['amount'] ) ? ( (float) $topup['amount'] ) / 100 : 0;
        $currency = isset( $topup['currency'] ) ? $topup['currency'] : 'eur';

        $this->store->record_payout_event(
            array(
                'user_id'        => 0,
                'referral_id'    => 0,
                'amount'         => $amount,
                'currency'       => $currency,
                'status'         => 'topup_' . strtolower( $status ),
                'transfer_id'    => isset( $topup['id'] ) ? $topup['id'] : null,
                'failure_code'   => isset( $topup['failure_code'] ) ? $topup['failure_code'] : null,
                'failure_message'=> isset( $topup['failure_message'] ) ? $topup['failure_message'] : null,
                'metadata'       => isset( $topup['metadata'] ) ? $topup['metadata'] : array(),
            )
        );
    }
}
