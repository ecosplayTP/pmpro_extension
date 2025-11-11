<?php
/**
 * Data persistence layer for referral codes.
 *
 * @package Ecosplay\Referrals
 * @file    wp-content/plugins/ecosplay-referrals/includes/class-referrals-store.php
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles custom tables used by the referrals system.
 */
class Ecosplay_Referrals_Store {
    /**
     * Database table name for referral owners and codes.
     *
     * @return string
     */
    protected function referrals_table() {
        global $wpdb;

        return $wpdb->prefix . 'ecos_referrals';
    }

    /**
     * Database table name for referral use logs.
     *
     * @return string
     */
    protected function uses_table() {
        global $wpdb;

        return $wpdb->prefix . 'ecos_referral_uses';
    }

    /**
     * Database table name for notification flags.
     *
     * @return string
     */
    protected function notifications_table() {
        global $wpdb;

        return $wpdb->prefix . 'ecos_referral_notifications';
    }

    /**
     * Database table name for payout ledger rows.
     *
     * @return string
     */
    protected function payouts_table() {
        global $wpdb;

        return $wpdb->prefix . 'ecos_referral_payouts';
    }

    /**
     * Provides the table name storing webhook event logs.
     *
     * @return string
     */
    protected function webhooks_table() {
        global $wpdb;

        return $wpdb->prefix . 'ecos_referral_webhooks';
    }

    /**
     * Creates or updates plugin tables on activation.
     *
     * @return void
     */
    public function install() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();

        $sql_referrals = "CREATE TABLE {$this->referrals_table()} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            code VARCHAR(64) NOT NULL,
            earned_credits DECIMAL(10,2) NOT NULL DEFAULT 0,
            total_paid DECIMAL(10,2) NOT NULL DEFAULT 0,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            notification_state TINYINT(1) NOT NULL DEFAULT 0,
            last_regenerated_at DATETIME NULL,
            stripe_account_id VARCHAR(64) NULL,
            stripe_capabilities LONGTEXT NULL,
            tremendous_organization_id VARCHAR(64) NULL,
            tremendous_status VARCHAR(32) NULL,
            tremendous_status_message VARCHAR(255) NULL,
            tremendous_balance DECIMAL(10,2) NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            UNIQUE KEY code (code),
            KEY user_id (user_id),
            PRIMARY KEY (id)
        ) $charset;";

        $sql_uses = "CREATE TABLE {$this->uses_table()} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            referral_id BIGINT(20) UNSIGNED NOT NULL,
            order_id BIGINT(20) UNSIGNED NULL,
            used_by BIGINT(20) UNSIGNED NULL,
            discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            reward_amount DECIMAL(10,2) NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY referral_id (referral_id)
        ) $charset;";

        $sql_notifications = "CREATE TABLE {$this->notifications_table()} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            has_seen TINYINT(1) NOT NULL DEFAULT 0,
            last_reset_at DATETIME NULL,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY user_id (user_id)
        ) $charset;";

        $sql_payouts = "CREATE TABLE {$this->payouts_table()} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            referral_id BIGINT(20) UNSIGNED NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            amount DECIMAL(10,2) NOT NULL,
            currency VARCHAR(10) NOT NULL DEFAULT 'EUR',
            status VARCHAR(32) NOT NULL,
            transfer_id VARCHAR(64) NULL,
            payout_id VARCHAR(64) NULL,
            failure_code VARCHAR(64) NULL,
            failure_message VARCHAR(255) NULL,
            metadata LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL,
            PRIMARY KEY (id),
            KEY referral_id (referral_id),
            KEY user_id (user_id),
            KEY transfer_id (transfer_id),
            KEY payout_id (payout_id)
        ) $charset;";

        $sql_webhooks = "CREATE TABLE {$this->webhooks_table()} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            event_type VARCHAR(191) NOT NULL,
            status VARCHAR(32) NOT NULL,
            payload LONGTEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY event_type (event_type),
            KEY created_at (created_at)
        ) $charset;";

        $uses_table        = $this->uses_table();
        $uses_table_sql    = esc_sql( $uses_table );
        $column_check_sql  = $wpdb->prepare( "SHOW COLUMNS FROM `{$uses_table_sql}` LIKE %s", 'reward_amount' );
        $had_reward_column = (bool) $wpdb->get_var( $column_check_sql );

        dbDelta( $sql_referrals );
        dbDelta( $sql_uses );
        dbDelta( $sql_notifications );
        dbDelta( $sql_payouts );
        dbDelta( $sql_webhooks );

        $has_reward_column = (bool) $wpdb->get_var( $column_check_sql );

        if ( ! $has_reward_column ) {
            $wpdb->query( "ALTER TABLE `{$uses_table_sql}` ADD COLUMN reward_amount DECIMAL(10,2) NOT NULL DEFAULT 0 AFTER discount_amount" );
            $has_reward_column = (bool) $wpdb->get_var( $column_check_sql );
        }

        if ( ! $had_reward_column && $has_reward_column ) {
            $wpdb->query( "UPDATE `{$uses_table_sql}` SET reward_amount = discount_amount" );
        }

        $this->maybe_add_referral_column( 'stripe_account_id', 'VARCHAR(64) NULL' );
        $this->maybe_add_referral_column( 'stripe_capabilities', 'LONGTEXT NULL' );
        $this->maybe_add_referral_column( 'total_paid', "DECIMAL(10,2) NOT NULL DEFAULT 0" );
        $this->maybe_add_referral_column( 'tremendous_organization_id', 'VARCHAR(64) NULL' );
        $this->maybe_add_referral_column( 'tremendous_status', 'VARCHAR(32) NULL' );
        $this->maybe_add_referral_column( 'tremendous_status_message', 'VARCHAR(255) NULL' );
        $this->maybe_add_referral_column( 'tremendous_balance', 'DECIMAL(10,2) NULL' );
    }

    /**
     * Ensures referral table columns exist without re-running full migrations.
     *
     * @param string $column     Column name to check.
     * @param string $definition SQL fragment for column definition.
     *
     * @return void
     */
    protected function maybe_add_referral_column( $column, $definition ) {
        global $wpdb;

        $table  = esc_sql( $this->referrals_table() );
        $column = sanitize_key( $column );

        if ( '' === $column ) {
            return;
        }

        $exists = $wpdb->get_var( $wpdb->prepare( "SHOW COLUMNS FROM `{$table}` LIKE %s", $column ) );

        if ( $exists ) {
            return;
        }

        $wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}" );
    }

    /**
     * Returns the active referral codes for display.
     *
     * @param bool $only_available Restricts to active codes.
     *
     * @return array<int,object>
     */
    public function get_active_codes( $only_available = true ) {
        global $wpdb;

        $sql = "SELECT id, user_id, code, earned_credits, total_paid, stripe_account_id, stripe_capabilities, tremendous_organization_id, tremendous_status, tremendous_status_message, tremendous_balance, is_active, created_at, updated_at
            FROM {$this->referrals_table()}";

        if ( $only_available ) {
            $sql .= " WHERE is_active = 1";
        }

        $sql .= ' ORDER BY created_at DESC';

        return $wpdb->get_results( $sql );
    }

    /**
     * Summarises payout readiness for each referrer.
     *
     * @return array<int,object>
     */
    public function get_payouts_overview() {
        global $wpdb;

        $referrals = $this->referrals_table();
        $uses      = $this->uses_table();
        $payouts   = $this->payouts_table();
        $users     = $wpdb->users;

        $sql = "SELECT
                r.id AS referral_id,
                r.user_id,
                u.display_name,
                u.user_email,
                r.stripe_account_id,
                r.stripe_capabilities,
                r.tremendous_organization_id,
                r.tremendous_status,
                r.tremendous_status_message,
                r.tremendous_balance,
                r.earned_credits,
                r.total_paid,
                COALESCE(r.earned_credits - r.total_paid, 0) AS balance,
                COALESCE(usage_summary.last_use, r.created_at) AS last_use,
                payout_summary.last_payout,
                COALESCE(payout_summary.pending_amount, 0) AS pending_amount,
                COALESCE(r.updated_at, r.created_at) AS referral_updated
            FROM {$referrals} AS r
            INNER JOIN {$users} AS u ON u.ID = r.user_id
            LEFT JOIN (
                SELECT referral_id, MAX(created_at) AS last_use
                FROM {$uses}
                GROUP BY referral_id
            ) AS usage_summary ON usage_summary.referral_id = r.id
            LEFT JOIN (
                SELECT user_id, MAX(created_at) AS last_payout,
                    SUM(CASE WHEN status IN ('pending','created') THEN amount ELSE 0 END) AS pending_amount
                FROM {$payouts}
                GROUP BY user_id
            ) AS payout_summary ON payout_summary.user_id = r.user_id
            WHERE r.is_active = 1
            ORDER BY u.display_name ASC";

        $rows = $wpdb->get_results( $sql );

        if ( empty( $rows ) ) {
            return array();
        }

        foreach ( $rows as $row ) {
            $timestamps = array();

            if ( ! empty( $row->last_use ) ) {
                $timestamps[] = strtotime( $row->last_use );
            }

            if ( ! empty( $row->last_payout ) ) {
                $timestamps[] = strtotime( $row->last_payout );
            }

            if ( ! empty( $row->referral_updated ) ) {
                $timestamps[] = strtotime( $row->referral_updated );
            }

            $row->last_activity = empty( $timestamps ) ? null : max( $timestamps );
        }

        return $rows;
    }

    /**
     * Finds the referral record for a given user.
     *
     * @param int $user_id User identifier.
     *
     * @return object|null
     */
    public function get_referral_by_user( $user_id ) {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, user_id, code, earned_credits, total_paid, stripe_account_id, stripe_capabilities, tremendous_organization_id, tremendous_status, tremendous_status_message, tremendous_balance, is_active, notification_state, last_regenerated_at, created_at, updated_at
                 FROM {$this->referrals_table()} WHERE user_id = %d",
                $user_id
            )
        );
    }

    /**
     * Finds a referral entry from its code.
     *
     * @param string $code Referral code.
     *
     * @return object|null
     */
    public function get_referral_by_code( $code ) {
        global $wpdb;

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, user_id, code, earned_credits, total_paid, stripe_account_id, stripe_capabilities, tremendous_organization_id, tremendous_status, tremendous_status_message, tremendous_balance, is_active, notification_state, last_regenerated_at, created_at, updated_at
                 FROM {$this->referrals_table()} WHERE code = %s",
                $code
            )
        );
    }

    /**
     * Inserts a record for a code usage and updates credits atomically.
     *
     * @param int   $referral_id     Referral identifier.
     * @param int   $order_id        Related order identifier.
     * @param int   $used_by         User who used the code.
     * @param float $discount_amount Discount applied to the customer.
     * @param float $reward_amount   Reward granted to the referrer.
     *
     * @return bool
     */
    public function log_code_use( $referral_id, $order_id, $used_by, $discount_amount, $reward_amount ) {
        global $wpdb;

        $wpdb->query( 'START TRANSACTION' );

        $inserted = $wpdb->insert(
            $this->uses_table(),
            array(
                'referral_id'     => $referral_id,
                'order_id'        => $order_id,
                'used_by'         => $used_by,
                'discount_amount' => $discount_amount,
                'reward_amount'   => $reward_amount,
            ),
            array( '%d', '%d', '%d', '%f', '%f' )
        );

        if ( false === $inserted ) {
            $wpdb->query( 'ROLLBACK' );

            return false;
        }

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->referrals_table()} SET earned_credits = earned_credits + %f, updated_at = CURRENT_TIMESTAMP WHERE id = %d",
                $reward_amount,
                $referral_id
            )
        );

        if ( false === $updated ) {
            $wpdb->query( 'ROLLBACK' );

            return false;
        }

        $wpdb->query( 'COMMIT' );

        return true;
    }

    /**
     * Returns usage history entries for one or all referrals.
     *
     * @param int|null $referral_id Optional referral identifier.
     * @param int      $limit       Number of records to return.
     * @param bool     $with_labels Whether to include column descriptors.
     *
     * @return array<int,object>|array<string,mixed>
     */
    public function get_usage_history( $referral_id = null, $limit = 20, $with_labels = false ) {
        global $wpdb;

        $sql   = "SELECT id, referral_id, order_id, used_by, discount_amount, reward_amount, created_at FROM {$this->uses_table()}";
        $args  = array();

        if ( null !== $referral_id ) {
            $sql   .= ' WHERE referral_id = %d';
            $args[] = $referral_id;
        }

        $sql .= ' ORDER BY created_at DESC';
        $sql .= $wpdb->prepare( ' LIMIT %d', max( 1, (int) $limit ) );

        $rows = $args ? $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) ) : $wpdb->get_results( $sql );

        if ( $with_labels ) {
            return array(
                'rows'   => $rows,
                'labels' => array(
                    'discount' => 'discount_amount',
                    'reward'   => 'reward_amount',
                ),
            );
        }

        return $rows;
    }

    /**
     * Récupère les événements de virements pour un membre.
     *
     * @param int $user_id Identifiant du membre.
     * @param int $limit   Nombre maximal d\'entrées.
     *
     * @return array<int,object>
     */
    public function get_member_payouts( $user_id, $limit = 10 ) {
        global $wpdb;

        $user_id = (int) $user_id;
        $limit   = max( 1, (int) $limit );

        if ( $user_id <= 0 ) {
            return array();
        }

        $query = $wpdb->prepare(
            "SELECT id, amount, currency, status, failure_message, created_at FROM {$this->payouts_table()} WHERE user_id = %d ORDER BY created_at DESC LIMIT %d",
            $user_id,
            $limit
        );

        return $wpdb->get_results( $query );
    }

    /**
     * Summarises usage rows grouped by period.
     *
     * @param string $period Grouping period (month|week).
     * @param int    $limit  Number of periods to return.
     * @param bool   $with_labels Whether to include aggregation descriptors.
     *
     * @return array<string,mixed>
     */
    public function get_usage_summary( $period = 'month', $limit = 6, $with_labels = false ) {
        global $wpdb;

        $period = in_array( $period, array( 'week', 'month' ), true ) ? $period : 'month';
        $limit  = max( 1, (int) $limit );

        if ( 'week' === $period ) {
            $group_expr = "DATE_FORMAT(created_at, '%x-%v')";
            $label_expr = "DATE_FORMAT(created_at, '%x semaine %v')";
        } else {
            $group_expr = "DATE_FORMAT(created_at, '%Y-%m-01')";
            $label_expr = "DATE_FORMAT(created_at, '%Y-%m')";
        }

        $sql = "SELECT {$group_expr} AS period_key, {$label_expr} AS period_label, COUNT(*) AS conversions, COALESCE(SUM(discount_amount),0) AS total_discount, COALESCE(SUM(reward_amount),0) AS total_reward
            FROM {$this->uses_table()}
            GROUP BY {$group_expr}
            ORDER BY period_key DESC
            LIMIT %d";

        $entries = $wpdb->get_results( $wpdb->prepare( $sql, $limit ) );

        $payload = array(
            'period'  => $period,
            'entries' => $entries,
        );

        if ( $with_labels ) {
            $payload['labels'] = array(
                'discount' => 'total_discount',
                'reward'   => 'total_reward',
            );
        }

        return $payload;
    }

    /**
     * Returns the global amount of credits earned.
     *
     * @return float
     */
    public function get_total_credits() {
        global $wpdb;

        $total = $wpdb->get_var( "SELECT COALESCE(SUM(earned_credits),0) FROM {$this->referrals_table()} WHERE is_active = 1" );

        return (float) $total;
    }

    /**
     * Persists the Stripe account identifier and capabilities for a member.
     *
     * @param int   $user_id      Related user identifier.
     * @param string $account_id  Stripe account identifier.
     * @param array $capabilities Capability map returned by Stripe.
     *
     * @return bool
     */
    public function save_stripe_account( $user_id, $account_id, array $capabilities = array() ) {
        global $wpdb;

        $user_id    = (int) $user_id;
        $account_id = trim( (string) $account_id );

        if ( $user_id <= 0 || '' === $account_id ) {
            return false;
        }

        $encoded_capabilities = $this->encode_capabilities( $capabilities );

        $data = array(
            'stripe_account_id'   => $account_id,
            'stripe_capabilities' => $encoded_capabilities,
            'updated_at'          => current_time( 'mysql' ),
        );

        $formats = array( '%s', '%s', '%s' );

        $result = $wpdb->update(
            $this->referrals_table(),
            $data,
            array( 'user_id' => $user_id ),
            $formats,
            array( '%d' )
        );

        if ( false !== $result ) {
            return true;
        }

        $this->regenerate_code( $user_id );

        $result = $wpdb->update(
            $this->referrals_table(),
            $data,
            array( 'user_id' => $user_id ),
            $formats,
            array( '%d' )
        );

        return false !== $result;
    }

    /**
     * Persists Tremendous connection state for the specified user.
     *
     * @param int                 $user_id Target user identifier.
     * @param array<string,mixed> $data    Association details.
     *
     * @return bool
     */
    public function save_tremendous_state( $user_id, array $data ) {
        global $wpdb;

        $user_id = (int) $user_id;

        if ( $user_id <= 0 ) {
            return false;
        }

        $allowed = array(
            'tremendous_organization_id' => '%s',
            'tremendous_status'          => '%s',
            'tremendous_status_message'  => '%s',
            'tremendous_balance'         => '%f',
        );

        $update = array();
        $format = array();

        foreach ( $allowed as $column => $column_format ) {
            if ( ! array_key_exists( $column, $data ) ) {
                continue;
            }

            $value = $data[ $column ];

            switch ( $column ) {
                case 'tremendous_organization_id':
                    $value = substr( sanitize_text_field( (string) $value ), 0, 64 );
                    break;
                case 'tremendous_status':
                    $value = substr( sanitize_text_field( strtolower( (string) $value ) ), 0, 32 );
                    break;
                case 'tremendous_status_message':
                    $value = substr( wp_strip_all_tags( (string) $value ), 0, 255 );
                    break;
                case 'tremendous_balance':
                    $value = (float) $value;
                    break;
            }

            $update[ $column ] = $value;
            $format[]          = $column_format;
        }

        if ( empty( $update ) ) {
            return false;
        }

        $update['updated_at'] = current_time( 'mysql' );
        $format[]             = '%s';

        return false !== $wpdb->update(
            $this->referrals_table(),
            $update,
            array( 'user_id' => $user_id ),
            $format,
            array( '%d' )
        );
    }

    /**
     * Updates only the stored capabilities for a referral entry.
     *
     * @param int   $referral_id Referral identifier.
     * @param array $capabilities Capability information from Stripe.
     *
     * @return bool
     */
    public function update_stripe_capabilities( $referral_id, array $capabilities ) {
        global $wpdb;

        $referral_id = (int) $referral_id;

        if ( $referral_id <= 0 ) {
            return false;
        }

        $result = $wpdb->update(
            $this->referrals_table(),
            array(
                'stripe_capabilities' => $this->encode_capabilities( $capabilities ),
                'updated_at'          => current_time( 'mysql' ),
            ),
            array( 'id' => $referral_id ),
            array( '%s', '%s' ),
            array( '%d' )
        );

        return false !== $result;
    }

    /**
     * Fetches a referral entry by its Stripe account identifier.
     *
     * @param string $account_id Stripe account identifier.
     *
     * @return object|null
     */
    public function get_referral_by_account( $account_id ) {
        global $wpdb;

        $account_id = trim( (string) $account_id );

        if ( '' === $account_id ) {
            return null;
        }

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT id, user_id, code, earned_credits, total_paid, stripe_account_id, stripe_capabilities, tremendous_organization_id, tremendous_status, tremendous_status_message, tremendous_balance, is_active, notification_state, last_regenerated_at, created_at, updated_at
                 FROM {$this->referrals_table()} WHERE stripe_account_id = %s",
                $account_id
            )
        );
    }

    /**
     * Retrieves payout ledger rows for a specific user.
     *
     * @param int $user_id WordPress user identifier.
     *
     * @return array<int,object>
     */
    public function get_user_payouts( $user_id ) {
        global $wpdb;

        $user_id = (int) $user_id;

        if ( $user_id <= 0 ) {
            return array();
        }

        $query = $wpdb->prepare(
            "SELECT id, amount, currency, status, transfer_id, payout_id, failure_message, metadata, created_at
            FROM {$this->payouts_table()} WHERE user_id = %d ORDER BY created_at DESC LIMIT 25",
            $user_id
        );

        return $wpdb->get_results( $query );
    }

    /**
     * Inserts a payout ledger event.
     *
     * @param array<string,mixed> $args Event details.
     *
     * @return int Inserted row identifier or 0 on failure.
     */
    public function record_payout_event( array $args ) {
        global $wpdb;

        $defaults = array(
            'user_id'        => 0,
            'referral_id'    => 0,
            'amount'         => 0,
            'currency'       => 'EUR',
            'status'         => '',
            'transfer_id'    => null,
            'payout_id'      => null,
            'failure_code'   => null,
            'failure_message'=> null,
            'metadata'       => null,
        );

        $data = array_merge( $defaults, $args );

        $metadata = $data['metadata'];

        if ( is_array( $metadata ) || is_object( $metadata ) ) {
            $metadata = wp_json_encode( $metadata );
        }

        $inserted = $wpdb->insert(
            $this->payouts_table(),
            array(
                'user_id'        => (int) $data['user_id'],
                'referral_id'    => (int) $data['referral_id'],
                'amount'         => (float) $data['amount'],
                'currency'       => strtoupper( substr( (string) $data['currency'], 0, 10 ) ),
                'status'         => substr( (string) $data['status'], 0, 32 ),
                'transfer_id'    => $data['transfer_id'] ? substr( (string) $data['transfer_id'], 0, 64 ) : null,
                'payout_id'      => $data['payout_id'] ? substr( (string) $data['payout_id'], 0, 64 ) : null,
                'failure_code'   => $data['failure_code'] ? substr( (string) $data['failure_code'], 0, 64 ) : null,
                'failure_message'=> $data['failure_message'] ? substr( (string) $data['failure_message'], 0, 255 ) : null,
                'metadata'       => $metadata,
                'created_at'     => current_time( 'mysql' ),
                'updated_at'     => current_time( 'mysql' ),
            ),
            array( '%d', '%d', '%f', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
        );

        if ( false === $inserted ) {
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Updates a payout ledger row referenced by its transfer identifier.
     *
     * @param string $transfer_id Stripe transfer identifier.
     * @param string $status      New status label.
     * @param array  $fields      Additional fields to persist.
     *
     * @return bool
     */
    public function update_payout_by_transfer( $transfer_id, $status, array $fields = array() ) {
        return $this->update_payout_row( 'transfer_id', $transfer_id, $status, $fields );
    }

    /**
     * Updates a payout ledger row referenced by its payout identifier.
     *
     * @param string $payout_id Stripe payout identifier.
     * @param string $status    Status label to persist.
     * @param array  $fields    Optional extra fields.
     *
     * @return bool
     */
    public function update_payout_by_payout( $payout_id, $status, array $fields = array() ) {
        return $this->update_payout_row( 'payout_id', $payout_id, $status, $fields );
    }

    /**
     * Increments the total paid amount for a referral entry.
     *
     * @param int   $referral_id Referral identifier.
     * @param float $amount      Amount to add.
     *
     * @return bool
     */
    public function increment_total_paid( $referral_id, $amount ) {
        global $wpdb;

        $referral_id = (int) $referral_id;
        $amount      = (float) $amount;

        if ( $referral_id <= 0 || $amount <= 0 ) {
            return false;
        }

        $result = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->referrals_table()} SET total_paid = total_paid + %f, updated_at = CURRENT_TIMESTAMP WHERE id = %d",
                $amount,
                $referral_id
            )
        );

        return false !== $result;
    }

    /**
     * Normalises capabilities into a storable JSON representation.
     *
     * @param array $capabilities Capability payload.
     *
     * @return string
     */
    protected function encode_capabilities( array $capabilities ) {
        if ( empty( $capabilities ) ) {
            return '';
        }

        return wp_json_encode( $capabilities );
    }

    /**
     * Updates payout rows by identifier and tracks success transitions.
     *
     * @param string $column Identifier column.
     * @param string $value  Identifier value.
     * @param string $status New status value.
     * @param array  $fields Additional data to save.
     *
     * @return bool
     */
    protected function update_payout_row( $column, $value, $status, array $fields ) {
        global $wpdb;

        $column = sanitize_key( $column );
        $value  = trim( (string) $value );

        if ( '' === $column || '' === $value ) {
            return false;
        }

        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->payouts_table()} WHERE {$column} = %s ORDER BY id DESC LIMIT 1",
                $value
            )
        );

        if ( ! $row ) {
            return false;
        }

        $data = array( 'status' => substr( (string) $status, 0, 32 ), 'updated_at' => current_time( 'mysql' ) );

        foreach ( $fields as $key => $field_value ) {
            switch ( $key ) {
                case 'failure_code':
                case 'failure_message':
                case 'transfer_id':
                case 'payout_id':
                    $data[ $key ] = $this->truncate_field( $key, $field_value );
                    break;
                case 'metadata':
                    if ( is_array( $field_value ) || is_object( $field_value ) ) {
                        $data[ $key ] = wp_json_encode( $field_value );
                    } else {
                        $data[ $key ] = (string) $field_value;
                    }
                    break;
            }
        }

        $updated = $wpdb->update(
            $this->payouts_table(),
            $data,
            array( 'id' => (int) $row->id ),
            array_fill( 0, count( $data ), '%s' ),
            array( '%d' )
        );

        if ( false === $updated ) {
            return false;
        }

        if ( $this->is_success_status( $status ) && ! $this->is_success_status( $row->status ) ) {
            $this->increment_total_paid( (int) $row->referral_id, (float) $row->amount );
        }

        return true;
    }

    /**
     * Determines whether a payout status represents a successful transfer.
     *
     * @param string $status Status label.
     *
     * @return bool
     */
    protected function is_success_status( $status ) {
        $status = strtolower( (string) $status );

        return in_array( $status, array( 'created', 'paid', 'succeeded', 'completed' ), true );
    }

    /**
     * Trims arbitrary values to fit column limits.
     *
     * @param string     $field Field name.
     * @param string|int $value Raw value.
     *
     * @return string
     */
    protected function truncate_field( $field, $value ) {
        $value = (string) $value;

        switch ( $field ) {
            case 'failure_code':
            case 'transfer_id':
            case 'payout_id':
                return substr( $value, 0, 64 );
            case 'failure_message':
                return substr( $value, 0, 255 );
        }

        return $value;
    }

    /**
     * Records the reception of a Stripe webhook payload.
     *
     * @param string              $type    Event type identifier.
     * @param string              $status  Processing outcome label.
     * @param array<string,mixed> $payload Payload forwarded by Stripe.
     *
     * @return int Insert identifier or 0 on failure.
     */
    public function log_webhook_event( $type, $status, array $payload ) {
        global $wpdb;

        if ( ! $this->table_exists( $this->webhooks_table() ) ) {
            return 0;
        }

        $inserted = $wpdb->insert(
            $this->webhooks_table(),
            array(
                'event_type' => substr( sanitize_text_field( $type ), 0, 191 ),
                'status'     => substr( sanitize_key( $status ), 0, 32 ),
                'payload'    => wp_json_encode( $payload ),
                'created_at' => current_time( 'mysql' ),
            ),
            array( '%s', '%s', '%s', '%s' )
        );

        if ( false === $inserted ) {
            return 0;
        }

        return (int) $wpdb->insert_id;
    }

    /**
     * Lists webhook logs with optional filters.
     *
     * @param array<string,mixed> $filters Filters: type, from, to, limit.
     *
     * @return array<int,object>
     */
    public function get_webhook_logs( array $filters = array() ) {
        global $wpdb;

        if ( ! $this->table_exists( $this->webhooks_table() ) ) {
            return array();
        }

        $defaults = array(
            'type'  => '',
            'from'  => '',
            'to'    => '',
            'limit' => 50,
        );

        $filters = array_merge( $defaults, $filters );

        $where = array();
        $args  = array();

        if ( '' !== $filters['type'] ) {
            $where[] = 'event_type = %s';
            $args[]  = substr( sanitize_text_field( $filters['type'] ), 0, 191 );
        }

        if ( '' !== $filters['from'] ) {
            $where[] = 'created_at >= %s';
            $args[]  = sanitize_text_field( $filters['from'] ) . ' 00:00:00';
        }

        if ( '' !== $filters['to'] ) {
            $where[] = 'created_at <= %s';
            $args[]  = sanitize_text_field( $filters['to'] ) . ' 23:59:59';
        }

        $limit = max( 1, (int) $filters['limit'] );

        $sql = "SELECT id, event_type, status, payload, created_at FROM {$this->webhooks_table()}";

        if ( ! empty( $where ) ) {
            $sql .= ' WHERE ' . implode( ' AND ', $where );
        }

        $sql .= ' ORDER BY created_at DESC';
        $sql .= $wpdb->prepare( ' LIMIT %d', $limit );

        if ( ! empty( $args ) ) {
            $sql = $wpdb->prepare( $sql, $args );
        }

        return $wpdb->get_results( $sql );
    }

    /**
     * Returns the list of distinct webhook event types stored.
     *
     * @return array<int,string>
     */
    public function get_webhook_event_types() {
        global $wpdb;

        if ( ! $this->table_exists( $this->webhooks_table() ) ) {
            return array();
        }

        $sql = "SELECT DISTINCT event_type FROM {$this->webhooks_table()} ORDER BY event_type ASC";

        return array_map( 'strval', $wpdb->get_col( $sql ) );
    }

    /**
     * Indicates whether a custom table exists in the database.
     *
     * @param string $table Fully qualified table name.
     *
     * @return bool
     */
    protected function table_exists( $table ) {
        global $wpdb;

        $table = esc_sql( $table );

        return (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
    }

    /**
     * Retrieves the total credits earned by a member.
     *
     * @param int $user_id User identifier.
     *
     * @return float
     */
    public function get_member_credits( $user_id ) {
        global $wpdb;

        $total = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT earned_credits FROM {$this->referrals_table()} WHERE user_id = %d",
                $user_id
            )
        );

        return null === $total ? 0.0 : (float) $total;
    }

    /**
     * Checks if the floating notice dismissal was already stored for the user.
     *
     * @param int $user_id User identifier.
     *
     * @return bool
     */
    public function has_seen_notification( $user_id ) {
        global $wpdb;

        $flag = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT has_seen FROM {$this->notifications_table()} WHERE user_id = %d",
                $user_id
            )
        );

        return ! empty( $flag );
    }

    /**
     * Stores the floating notice dismissal flag for the given user.
     *
     * @param int $user_id User identifier.
     *
     * @return bool
     */
    public function mark_notification_seen( $user_id ) {
        global $wpdb;

        $result = $wpdb->query(
            $wpdb->prepare(
                "INSERT INTO {$this->notifications_table()} (user_id, has_seen, updated_at)
                VALUES (%d, 1, %s)
                ON DUPLICATE KEY UPDATE has_seen = VALUES(has_seen), updated_at = VALUES(updated_at)",
                $user_id,
                current_time( 'mysql' )
            )
        );

        return false !== $result;
    }

    /**
     * Resets the notification flag for one or all users.
     *
     * @param int|null $user_id Optional user identifier.
     *
     * @return void
     */
    public function reset_notification_flag( $user_id = null ) {
        global $wpdb;

        if ( null === $user_id ) {
            $wpdb->query( "UPDATE {$this->notifications_table()} SET has_seen = 0, last_reset_at = CURRENT_TIMESTAMP" );

            return;
        }

        $wpdb->replace(
            $this->notifications_table(),
            array(
                'user_id'      => $user_id,
                'has_seen'     => 0,
                'last_reset_at'=> current_time( 'mysql' ),
            ),
            array( '%d', '%d', '%s' )
        );
    }

    /**
     * Regenerates and persists a new referral code safely.
     *
     * @param int $user_id User identifier.
     *
     * @return string|false
     */
    public function regenerate_code( $user_id ) {
        global $wpdb;

        $wpdb->query( 'START TRANSACTION' );

        $existing_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT id FROM {$this->referrals_table()} WHERE user_id = %d FOR UPDATE",
                $user_id
            )
        );

        $code = $this->generate_code();

        if ( $existing_id ) {
            $updated = $wpdb->update(
                $this->referrals_table(),
                array(
                    'code'                => $code,
                    'last_regenerated_at' => current_time( 'mysql' ),
                    'updated_at'          => current_time( 'mysql' ),
                ),
                array( 'id' => $existing_id ),
                array( '%s', '%s', '%s' ),
                array( '%d' )
            );
        } else {
            $updated = $wpdb->insert(
                $this->referrals_table(),
                array(
                    'user_id'            => $user_id,
                    'code'               => $code,
                    'created_at'         => current_time( 'mysql' ),
                    'earned_credits'     => 0,
                ),
                array( '%d', '%s', '%s', '%f' )
            );
        }

        if ( false === $updated ) {
            $wpdb->query( 'ROLLBACK' );

            return false;
        }

        $wpdb->query( 'COMMIT' );

        return $code;
    }

    /**
     * Generates a short unique referral code.
     *
     * @return string
     */
    protected function generate_code() {
        return strtoupper( wp_generate_password( 10, false ) );
    }
}
