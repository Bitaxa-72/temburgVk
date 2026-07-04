<?php

if (!defined('ABSPATH')) {
    exit;
}

class Termburg_VK_Promocodes_Campaigns {
    public static function list_campaigns() {
        global $wpdb;

        return $wpdb->get_results('SELECT * FROM ' . Termburg_VK_Promocodes_DB::campaigns_table() . ' ORDER BY id DESC', ARRAY_A);
    }

    public static function get_campaign($campaign_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Termburg_VK_Promocodes_DB::campaigns_table() . ' WHERE id = %d', intval($campaign_id)), ARRAY_A);
    }

    public static function get_active_campaign() {
        global $wpdb;

        $campaign = $wpdb->get_row("SELECT * FROM " . Termburg_VK_Promocodes_DB::campaigns_table() . " WHERE status = 'active' ORDER BY id DESC LIMIT 1", ARRAY_A);
        if ($campaign) {
            return $campaign;
        }

        return self::ensure_default_campaign();
    }

    public static function ensure_default_campaign() {
        global $wpdb;

        $campaign = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Termburg_VK_Promocodes_DB::campaigns_table() . ' WHERE slug = %s LIMIT 1', 'vk-default'), ARRAY_A);
        if ($campaign) {
            return $campaign;
        }

        $settings = Termburg_VK_Promocodes_Settings::get();
        $campaign_id = self::save_campaign(array(
            'name' => 'VK промокод',
            'slug' => 'vk-default',
            'status' => $settings['campaign_enabled'] === '1' ? 'active' : 'inactive',
            'discount_type' => $settings['discount_type'],
            'discount_value' => $settings['discount_value'],
            'product_groups' => $settings['allowed_kinds'],
            'expires_in_days' => $settings['expires_days'],
            'is_lifetime' => '1',
            'usage_limit' => '1',
            'reissue_mode' => $settings['reissue_policy'],
            'reissue_after_days' => $settings['reissue_days'],
            'code_prefix' => $settings['code_prefix'],
            'code_length' => $settings['code_length'],
            'continue_url' => $settings['continue_url'],
            'consent_text_version' => $settings['consent_text_version'],
            'bot_intro' => $settings['bot_intro'],
            'bot_consent_text' => $settings['bot_consent_text'],
            'bot_need_subscription' => $settings['bot_need_subscription'],
            'bot_code_message' => $settings['bot_code_message'],
            'bot_existing_code_message' => $settings['bot_existing_code_message'],
        ));

        return self::get_campaign($campaign_id);
    }

    public static function save_campaign($input) {
        global $wpdb;

        $data = self::sanitize_campaign($input);
        $now = current_time('mysql');
        $campaign_id = isset($input['id']) ? intval($input['id']) : 0;
        $data['updated_at'] = $now;

        if ($campaign_id > 0 && self::get_campaign($campaign_id)) {
            $wpdb->update(Termburg_VK_Promocodes_DB::campaigns_table(), $data, array('id' => $campaign_id));
            return $campaign_id;
        }

        $data['created_at'] = $now;
        $wpdb->insert(Termburg_VK_Promocodes_DB::campaigns_table(), $data);

        return intval($wpdb->insert_id);
    }

    public static function issue($campaign_id, $vk_user_id, $profile = array()) {
        $campaign = $campaign_id ? self::get_campaign($campaign_id) : self::get_active_campaign();
        if (!$campaign) {
            return new WP_Error('campaign_missing', 'Акция не найдена');
        }

        if ($campaign['status'] !== 'active') {
            return new WP_Error('campaign_disabled', 'Акция выключена');
        }

        $vk_user_id = intval($vk_user_id);
        if ($vk_user_id <= 0) {
            return new WP_Error('vk_user_missing', 'VK пользователь не указан');
        }

        $existing = self::latest_user_promocode(intval($campaign['id']), $vk_user_id);
        if ($existing && !self::can_reissue($existing, $campaign)) {
            if ($existing['status'] === 'cancelled') {
                return new WP_Error('promocode_cancelled', 'Промокод по этой акции был аннулирован');
            }

            if (!empty($existing['used_at']) || intval($existing['usage_count']) >= intval($existing['usage_limit'])) {
                return new WP_Error('promocode_already_used', 'Промокод по этой акции уже был использован');
            }

            if (!empty($existing['expires_at']) && strtotime($existing['expires_at']) < time()) {
                return new WP_Error('promocode_expired', 'Промокод по этой акции уже истек');
            }

            return self::issue_response($campaign, $existing, false, true);
        }

        if (self::issue_limit_reached($campaign)) {
            return new WP_Error('issue_limit_reached', 'Лимит выдачи промокодов исчерпан');
        }

        $now = current_time('mysql');
        $expires_at = self::expires_at($campaign);
        $can_reissue_at = self::can_reissue_at($campaign, $expires_at);
        $code = self::make_unique_code($campaign['code_prefix'], intval($campaign['code_length']));
        $data = array(
            'campaign_id' => intval($campaign['id']),
            'vk_user_id' => $vk_user_id,
            'vk_first_name' => sanitize_text_field(isset($profile['vk_first_name']) ? $profile['vk_first_name'] : ''),
            'vk_last_name' => sanitize_text_field(isset($profile['vk_last_name']) ? $profile['vk_last_name'] : ''),
            'promo_code' => $code,
            'discount_type' => $campaign['discount_type'],
            'discount_value' => (float) $campaign['discount_value'],
            'product_groups' => $campaign['product_groups'],
            'usage_limit' => max(1, intval($campaign['usage_limit'])),
            'usage_count' => 0,
            'expires_at' => $expires_at,
            'can_reissue_at' => $can_reissue_at,
            'last_issue_at' => $now,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        );

        global $wpdb;
        $wpdb->insert(Termburg_VK_Promocodes_DB::promocodes_table(), $data);
        $promocode = self::get_promocode(intval($wpdb->insert_id));

        Termburg_VK_Promocodes_DB::log_event('', 'promocode_issued', $vk_user_id, array(
            'campaign_id' => intval($campaign['id']),
            'promocode_id' => intval($promocode['id']),
            'promo_code' => $promocode['promo_code'],
        ), 'ok');

        return self::issue_response($campaign, $promocode, true, false);
    }

    public static function get_promocode($promocode_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Termburg_VK_Promocodes_DB::promocodes_table() . ' WHERE id = %d', intval($promocode_id)), ARRAY_A);
    }

    public static function list_promocodes($campaign_id = 0) {
        global $wpdb;

        if ($campaign_id) {
            return $wpdb->get_results($wpdb->prepare('SELECT p.*, c.name AS campaign_name FROM ' . Termburg_VK_Promocodes_DB::promocodes_table() . ' p LEFT JOIN ' . Termburg_VK_Promocodes_DB::campaigns_table() . ' c ON c.id = p.campaign_id WHERE p.campaign_id = %d ORDER BY p.id DESC', intval($campaign_id)), ARRAY_A);
        }

        return $wpdb->get_results('SELECT p.*, c.name AS campaign_name FROM ' . Termburg_VK_Promocodes_DB::promocodes_table() . ' p LEFT JOIN ' . Termburg_VK_Promocodes_DB::campaigns_table() . ' c ON c.id = p.campaign_id ORDER BY p.id DESC LIMIT 300', ARRAY_A);
    }

    public static function cancel_promocode($promocode_id, $reason = '') {
        global $wpdb;

        $promocode = self::get_promocode($promocode_id);
        if (!$promocode) {
            return new WP_Error('not_found', 'Промокод не найден');
        }

        $wpdb->update(Termburg_VK_Promocodes_DB::promocodes_table(), array(
            'status' => 'cancelled',
            'cancelled_at' => current_time('mysql'),
            'cancelled_by' => get_current_user_id(),
            'cancel_reason' => sanitize_textarea_field($reason),
            'updated_at' => current_time('mysql'),
        ), array('id' => intval($promocode_id)));

        Termburg_VK_Promocodes_DB::log_event('', 'promocode_cancelled', intval($promocode['vk_user_id']), array(
            'campaign_id' => intval($promocode['campaign_id']),
            'promocode_id' => intval($promocode_id),
        ), 'ok');

        return true;
    }

    public static function sanitize_campaign($input) {
        $settings = Termburg_VK_Promocodes_Settings::get();
        $input = is_array($input) ? $input : array();
        $product_groups = isset($input['product_groups']) && is_array($input['product_groups']) ? $input['product_groups'] : array();
        $allowed_groups = array('visit_ticket', 'adult_ticket', 'child_ticket', 'service', 'certificate', 'subscription', 'gift_box', 'merch', 'product');
        $name = sanitize_text_field(isset($input['name']) ? $input['name'] : '');
        $slug = sanitize_title(isset($input['slug']) ? $input['slug'] : '');

        if ($name === '') {
            $name = 'VK промокод';
        }

        if ($slug === '') {
            $slug = sanitize_title($name);
        }

        $discount_type = isset($input['discount_type']) ? sanitize_key($input['discount_type']) : 'percent';
        if ($discount_type === 'fixed_cart') {
            $discount_type = 'fixed';
        }

        return array(
            'name' => $name,
            'slug' => $slug ?: 'vk-promo',
            'status' => isset($input['status']) && $input['status'] === 'active' ? 'active' : 'inactive',
            'discount_type' => $discount_type === 'fixed' ? 'fixed' : 'percent',
            'discount_value' => max(0, round((float) (isset($input['discount_value']) ? $input['discount_value'] : $settings['discount_value']), 2)),
            'product_groups' => wp_json_encode(array_values(array_intersect(array_map('sanitize_key', $product_groups), $allowed_groups)), JSON_UNESCAPED_UNICODE),
            'expires_in_days' => max(0, min(3650, intval(isset($input['expires_in_days']) ? $input['expires_in_days'] : 0))),
            'is_lifetime' => empty($input['is_lifetime']) ? 0 : 1,
            'usage_limit' => max(1, min(1000, intval(isset($input['usage_limit']) ? $input['usage_limit'] : 1))),
            'reissue_mode' => in_array(isset($input['reissue_mode']) ? $input['reissue_mode'] : 'never', array('never', 'after_days', 'after_expiry'), true) ? $input['reissue_mode'] : 'never',
            'reissue_after_days' => max(0, min(3650, intval(isset($input['reissue_after_days']) ? $input['reissue_after_days'] : 0))),
            'code_prefix' => self::sanitize_code_prefix(isset($input['code_prefix']) ? $input['code_prefix'] : $settings['code_prefix']),
            'code_length' => max(4, min(16, intval(isset($input['code_length']) ? $input['code_length'] : $settings['code_length']))),
            'total_issue_limit' => max(0, intval(isset($input['total_issue_limit']) ? $input['total_issue_limit'] : 0)),
            'daily_issue_limit' => max(0, intval(isset($input['daily_issue_limit']) ? $input['daily_issue_limit'] : 0)),
            'continue_url' => esc_url_raw(isset($input['continue_url']) ? $input['continue_url'] : $settings['continue_url']),
            'consent_text_version' => sanitize_text_field(isset($input['consent_text_version']) ? $input['consent_text_version'] : $settings['consent_text_version']),
            'bot_intro' => sanitize_textarea_field(isset($input['bot_intro']) ? $input['bot_intro'] : $settings['bot_intro']),
            'bot_consent_text' => sanitize_textarea_field(isset($input['bot_consent_text']) ? $input['bot_consent_text'] : $settings['bot_consent_text']),
            'bot_need_subscription' => sanitize_textarea_field(isset($input['bot_need_subscription']) ? $input['bot_need_subscription'] : $settings['bot_need_subscription']),
            'bot_code_message' => sanitize_textarea_field(isset($input['bot_code_message']) ? $input['bot_code_message'] : $settings['bot_code_message']),
            'bot_existing_code_message' => sanitize_textarea_field(isset($input['bot_existing_code_message']) ? $input['bot_existing_code_message'] : $settings['bot_existing_code_message']),
        );
    }

    public static function product_groups($campaign) {
        $groups = json_decode(isset($campaign['product_groups']) ? $campaign['product_groups'] : '[]', true);
        return is_array($groups) ? $groups : array();
    }

    public static function display_status($promocode) {
        if ($promocode['status'] === 'cancelled') {
            return 'Аннулирован';
        }

        if (!empty($promocode['used_at']) || intval($promocode['usage_count']) >= intval($promocode['usage_limit'])) {
            return 'Применен';
        }

        if (!empty($promocode['expires_at']) && strtotime($promocode['expires_at']) < time()) {
            return 'Истек';
        }

        return 'Активен';
    }

    private static function latest_user_promocode($campaign_id, $vk_user_id) {
        global $wpdb;

        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Termburg_VK_Promocodes_DB::promocodes_table() . ' WHERE campaign_id = %d AND vk_user_id = %d ORDER BY id DESC LIMIT 1', intval($campaign_id), intval($vk_user_id)), ARRAY_A);
    }

    private static function can_reissue($promocode, $campaign) {
        if (!$promocode) {
            return true;
        }

        if ($campaign['reissue_mode'] === 'after_expiry') {
            return !empty($promocode['expires_at']) && strtotime($promocode['expires_at']) <= time();
        }

        if ($campaign['reissue_mode'] === 'after_days') {
            return !empty($promocode['can_reissue_at']) && strtotime($promocode['can_reissue_at']) <= time();
        }

        return false;
    }

    private static function issue_limit_reached($campaign) {
        global $wpdb;

        $campaign_id = intval($campaign['id']);
        $total_limit = intval($campaign['total_issue_limit']);
        if ($total_limit > 0) {
            $total = intval($wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Termburg_VK_Promocodes_DB::promocodes_table() . ' WHERE campaign_id = %d', $campaign_id)));
            if ($total >= $total_limit) {
                return true;
            }
        }

        $daily_limit = intval($campaign['daily_issue_limit']);
        if ($daily_limit > 0) {
            $today = current_time('Y-m-d') . ' 00:00:00';
            $daily = intval($wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Termburg_VK_Promocodes_DB::promocodes_table() . ' WHERE campaign_id = %d AND created_at >= %s', $campaign_id, $today)));
            if ($daily >= $daily_limit) {
                return true;
            }
        }

        return false;
    }

    private static function expires_at($campaign) {
        if (intval($campaign['is_lifetime']) === 1 || intval($campaign['expires_in_days']) <= 0) {
            return null;
        }

        $expires = new DateTime('now', wp_timezone());
        $expires->modify('+' . intval($campaign['expires_in_days']) . ' days');

        return $expires->format('Y-m-d H:i:s');
    }

    private static function can_reissue_at($campaign, $expires_at) {
        if ($campaign['reissue_mode'] === 'after_days' && intval($campaign['reissue_after_days']) > 0) {
            $date = new DateTime('now', wp_timezone());
            $date->modify('+' . intval($campaign['reissue_after_days']) . ' days');
            return $date->format('Y-m-d H:i:s');
        }

        if ($campaign['reissue_mode'] === 'after_expiry') {
            return $expires_at;
        }

        return null;
    }

    private static function issue_response($campaign, $promocode, $issued, $reused) {
        $settings = Termburg_VK_Promocodes_Settings::get();
        $template = $reused ? $settings['bot_existing_code_message'] : $settings['bot_code_message'];

        return array(
            'issued' => (bool) $issued,
            'reused' => (bool) $reused,
            'code' => $promocode['promo_code'],
            'promocode_id' => intval($promocode['id']),
            'campaign_id' => intval($campaign['id']),
            'expires_at' => $promocode['expires_at'],
            'can_reissue_at' => $promocode['can_reissue_at'],
            'message' => str_replace('{code}', $promocode['promo_code'], $template),
        );
    }

    private static function make_unique_code($prefix, $length) {
        global $wpdb;

        $prefix = self::sanitize_code_prefix($prefix);
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $body = '';
            for ($i = 0; $i < $length; $i++) {
                $body .= $alphabet[wp_rand(0, strlen($alphabet) - 1)];
            }
            $code = $prefix . '-' . $body;
            $exists = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Termburg_VK_Promocodes_DB::promocodes_table() . ' WHERE promo_code = %s LIMIT 1', $code));
        } while ($exists);

        return $code;
    }

    private static function sanitize_code_prefix($prefix) {
        $prefix = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $prefix));
        return $prefix ?: 'VK';
    }
}
