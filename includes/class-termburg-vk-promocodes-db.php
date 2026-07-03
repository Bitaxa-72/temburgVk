<?php

if (!defined('ABSPATH')) {
    exit;
}

class Termburg_VK_Promocodes_DB {
    public static function users_table() {
        global $wpdb;
        return $wpdb->prefix . 'termburg_vk_promo_users';
    }

    public static function events_table() {
        global $wpdb;
        return $wpdb->prefix . 'termburg_vk_promo_events';
    }

    public static function install() {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charset = $wpdb->get_charset_collate();
        $users = self::users_table();
        $events = self::events_table();

        dbDelta("CREATE TABLE {$users} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            vk_user_id bigint(20) unsigned NOT NULL,
            vk_first_name varchar(190) NOT NULL DEFAULT '',
            vk_last_name varchar(190) NOT NULL DEFAULT '',
            is_group_member tinyint(1) NOT NULL DEFAULT 0,
            messages_allowed tinyint(1) NOT NULL DEFAULT 0,
            marketing_consent_at datetime NULL,
            marketing_consent_text_version varchar(32) NOT NULL DEFAULT '',
            marketing_revoked_at datetime NULL,
            promo_code varchar(64) NOT NULL DEFAULT '',
            coupon_id bigint(20) unsigned NOT NULL DEFAULT 0,
            promo_created_at datetime NULL,
            promo_expires_at datetime NULL,
            promo_used_at datetime NULL,
            last_issue_at datetime NULL,
            campaign_id varchar(64) NOT NULL DEFAULT 'default',
            status varchar(32) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY vk_user_id (vk_user_id),
            KEY promo_code (promo_code),
            KEY coupon_id (coupon_id),
            KEY status (status)
        ) {$charset};");

        dbDelta("CREATE TABLE {$events} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_id varchar(190) NOT NULL DEFAULT '',
            event_type varchar(64) NOT NULL DEFAULT '',
            vk_user_id bigint(20) unsigned NOT NULL DEFAULT 0,
            payload_hash varchar(64) NOT NULL DEFAULT '',
            status varchar(32) NOT NULL DEFAULT '',
            error text NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY event_id (event_id),
            KEY event_type (event_type),
            KEY vk_user_id (vk_user_id),
            KEY status (status)
        ) {$charset};");
    }

    public static function get_user($vk_user_id) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::users_table() . ' WHERE vk_user_id = %d', $vk_user_id), ARRAY_A);
    }

    public static function get_user_by_code($code) {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::users_table() . ' WHERE promo_code = %s', $code), ARRAY_A);
    }

    public static function upsert_user($vk_user_id, $data = array()) {
        global $wpdb;

        $now = current_time('mysql');
        $existing = self::get_user($vk_user_id);
        $base = array(
            'vk_user_id' => intval($vk_user_id),
            'updated_at' => $now,
        );
        $data = array_merge($base, $data);

        if ($existing) {
            $wpdb->update(self::users_table(), $data, array('vk_user_id' => intval($vk_user_id)));
            return self::get_user($vk_user_id);
        }

        $data['created_at'] = $now;
        $wpdb->insert(self::users_table(), $data);
        return self::get_user($vk_user_id);
    }

    public static function mark_promo_used($coupon_id, $code = '') {
        global $wpdb;

        $where = $coupon_id ? array('coupon_id' => intval($coupon_id)) : array('promo_code' => sanitize_text_field($code));
        $wpdb->update(self::users_table(), array(
            'promo_used_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ), $where);
    }

    public static function event_seen($event_id) {
        if ($event_id === '') {
            return false;
        }

        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::events_table() . ' WHERE event_id = %s LIMIT 1', $event_id));
    }

    public static function log_event($event_id, $event_type, $vk_user_id, $payload, $status, $error = '') {
        global $wpdb;

        $wpdb->insert(self::events_table(), array(
            'event_id' => sanitize_text_field($event_id),
            'event_type' => sanitize_key($event_type),
            'vk_user_id' => intval($vk_user_id),
            'payload_hash' => hash('sha256', wp_json_encode($payload)),
            'status' => sanitize_key($status),
            'error' => sanitize_textarea_field($error),
            'created_at' => current_time('mysql'),
        ));
    }
}
