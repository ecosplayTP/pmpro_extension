<?php
/**
 * Data store for PMPro Notify campaign and view tables.
 *
 * @package Pmpro_Notify
 * @file    wp-content/plugins/pmpro-notify/includes/class-notify-store.php
 */

namespace Pmpro_Notify;

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Handles table creation and view persistence for notification campaigns.
 */
class Notify_Store {
    /**
     * Returns the campaigns table name.
     *
     * @return string
     */
    public function get_campaigns_table() {
        global $wpdb;

        return $wpdb->prefix . 'pmpro_notify_campaigns';
    }

    /**
     * Returns the views table name.
     *
     * @return string
     */
    public function get_views_table() {
        global $wpdb;

        return $wpdb->prefix . 'pmpro_notify_views';
    }

    /**
     * Creates or updates the campaign and view tables.
     *
     * @return void
     */
    public function create_tables() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset_collate = $wpdb->get_charset_collate();
        $campaigns_table = $this->get_campaigns_table();
        $views_table     = $this->get_views_table();

        $campaigns_sql = "CREATE TABLE {$campaigns_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            title varchar(200) NOT NULL,
            message longtext NOT NULL,
            level_target longtext NULL,
            start_at datetime NULL,
            end_at datetime NULL,
            is_active tinyint(1) NOT NULL DEFAULT 1,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY is_active (is_active),
            KEY date_range (start_at, end_at)
        ) {$charset_collate};";

        $views_sql = "CREATE TABLE {$views_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            campaign_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            seen_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY campaign_user_seen (campaign_id, user_id, seen_at),
            KEY user_id (user_id)
        ) {$charset_collate};";

        dbDelta( $campaigns_sql );
        dbDelta( $views_sql );
    }

    /**
     * Retrieves the first active campaign for the current date.
     *
     * @return object|null
     */
    public function get_active_campaign() {
        global $wpdb;

        $campaigns_table = $this->get_campaigns_table();
        $now             = current_time( 'mysql' );

        $query = $wpdb->prepare(
            "SELECT * FROM {$campaigns_table}
            WHERE is_active = 1
                AND (start_at IS NULL OR start_at <= %s)
                AND (end_at IS NULL OR end_at >= %s)
            ORDER BY start_at DESC, id DESC
            LIMIT 1",
            $now,
            $now
        );

        return $wpdb->get_row( $query );
    }

    /**
     * Retrieves all campaigns for admin listing.
     *
     * @return array
     */
    public function get_campaigns() {
        global $wpdb;

        $campaigns_table = $this->get_campaigns_table();

        return $wpdb->get_results( "SELECT * FROM {$campaigns_table} ORDER BY created_at DESC, id DESC" );
    }

    /**
     * Fetches a campaign row by ID.
     *
     * @param int $campaign_id Campaign identifier.
     *
     * @return object|null
     */
    public function get_campaign( $campaign_id ) {
        global $wpdb;

        $campaigns_table = $this->get_campaigns_table();

        $query = $wpdb->prepare(
            "SELECT * FROM {$campaigns_table} WHERE id = %d",
            absint( $campaign_id )
        );

        return $wpdb->get_row( $query );
    }

    /**
     * Inserts or updates a campaign row.
     *
     * @param array $data Campaign data.
     *
     * @return int|false
     */
    public function save_campaign( $data ) {
        global $wpdb;

        $campaigns_table = $this->get_campaigns_table();
        $now             = current_time( 'mysql' );
        $campaign_id     = isset( $data['id'] ) ? absint( $data['id'] ) : 0;

        $payload = array(
            'title'        => $data['title'],
            'message'      => $data['message'],
            'level_target' => $data['level_target'],
            'start_at'     => $data['start_at'],
            'end_at'       => $data['end_at'],
            'is_active'    => $data['is_active'],
            'updated_at'   => $now,
        );

        $formats = array( '%s', '%s', '%s', '%s', '%s', '%d', '%s' );

        if ( $campaign_id > 0 ) {
            $result = $wpdb->update(
                $campaigns_table,
                $payload,
                array( 'id' => $campaign_id ),
                $formats,
                array( '%d' )
            );

            return false === $result ? false : $campaign_id;
        }

        $payload['created_at'] = $now;
        $formats[]             = '%s';

        $result = $wpdb->insert( $campaigns_table, $payload, $formats );

        return false === $result ? false : (int) $wpdb->insert_id;
    }

    /**
     * Aggregates view counts per day for a given range.
     *
     * @param string $start_date Inclusive start date (Y-m-d).
     * @param string $end_date   Inclusive end date (Y-m-d).
     *
     * @return array
     */
    public function get_views_by_day( $start_date, $end_date ) {
        global $wpdb;

        $views_table = $this->get_views_table();

        $query = $wpdb->prepare(
            "SELECT DATE(seen_at) as view_date, COUNT(*) as total_views
            FROM {$views_table}
            WHERE DATE(seen_at) BETWEEN %s AND %s
            GROUP BY DATE(seen_at)
            ORDER BY DATE(seen_at) ASC",
            $start_date,
            $end_date
        );

        return $wpdb->get_results( $query, ARRAY_A );
    }

    /**
     * Inserts a view record for a given campaign and user.
     *
     * @param int         $campaign_id Campaign identifier.
     * @param int         $user_id     User identifier.
     * @param string|null $seen_at     Optional timestamp for the view.
     *
     * @return bool
     */
    public function insert_view( $campaign_id, $user_id, $seen_at = null ) {
        global $wpdb;

        $views_table = $this->get_views_table();
        $seen_at     = $seen_at ? $seen_at : current_time( 'mysql' );

        $result = $wpdb->insert(
            $views_table,
            array(
                'campaign_id' => absint( $campaign_id ),
                'user_id'     => absint( $user_id ),
                'seen_at'     => $seen_at,
            ),
            array( '%d', '%d', '%s' )
        );

        return false !== $result;
    }

    /**
     * Fetches the most recent view for a user and campaign.
     *
     * @param int $campaign_id Campaign identifier.
     * @param int $user_id     User identifier.
     *
     * @return object|null
     */
    public function get_latest_view( $campaign_id, $user_id ) {
        global $wpdb;

        $views_table = $this->get_views_table();

        $query = $wpdb->prepare(
            "SELECT * FROM {$views_table}
            WHERE campaign_id = %d AND user_id = %d
            ORDER BY seen_at DESC
            LIMIT 1",
            absint( $campaign_id ),
            absint( $user_id )
        );

        return $wpdb->get_row( $query );
    }

    /**
     * Indicates if the user already viewed the campaign.
     *
     * @param int $campaign_id Campaign identifier.
     * @param int $user_id     User identifier.
     *
     * @return bool
     */
    public function has_seen_campaign( $campaign_id, $user_id ) {
        return (bool) $this->get_latest_view( $campaign_id, $user_id );
    }
}
