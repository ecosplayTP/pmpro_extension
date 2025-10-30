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
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            notification_state TINYINT(1) NOT NULL DEFAULT 0,
            last_regenerated_at DATETIME NULL,
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

        dbDelta( $sql_referrals );
        dbDelta( $sql_uses );
        dbDelta( $sql_notifications );
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

        $sql = "SELECT id, user_id, code, earned_credits, is_active, created_at, updated_at
            FROM {$this->referrals_table()}";

        if ( $only_available ) {
            $sql .= " WHERE is_active = 1";
        }

        $sql .= ' ORDER BY created_at DESC';

        return $wpdb->get_results( $sql );
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
                "SELECT id, user_id, code, earned_credits, is_active, notification_state, last_regenerated_at, created_at, updated_at
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
                "SELECT id, user_id, code, earned_credits, is_active, notification_state, last_regenerated_at, created_at, updated_at
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
     * @param float $discount_amount Discount applied.
     *
     * @return bool
     */
    public function log_code_use( $referral_id, $order_id, $used_by, $discount_amount ) {
        global $wpdb;

        $wpdb->query( 'START TRANSACTION' );

        $inserted = $wpdb->insert(
            $this->uses_table(),
            array(
                'referral_id'     => $referral_id,
                'order_id'        => $order_id,
                'used_by'         => $used_by,
                'discount_amount' => $discount_amount,
            ),
            array( '%d', '%d', '%d', '%f' )
        );

        if ( false === $inserted ) {
            $wpdb->query( 'ROLLBACK' );

            return false;
        }

        $updated = $wpdb->query(
            $wpdb->prepare(
                "UPDATE {$this->referrals_table()} SET earned_credits = earned_credits + %f, updated_at = CURRENT_TIMESTAMP WHERE id = %d",
                $discount_amount,
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
     *
     * @return array<int,object>
     */
    public function get_usage_history( $referral_id = null, $limit = 20 ) {
        global $wpdb;

        $sql   = "SELECT id, referral_id, order_id, used_by, discount_amount, created_at FROM {$this->uses_table()}";
        $args  = array();

        if ( null !== $referral_id ) {
            $sql   .= ' WHERE referral_id = %d';
            $args[] = $referral_id;
        }

        $sql .= ' ORDER BY created_at DESC';
        $sql .= $wpdb->prepare( ' LIMIT %d', max( 1, (int) $limit ) );

        if ( $args ) {
            return $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) );
        }

        return $wpdb->get_results( $sql );
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
