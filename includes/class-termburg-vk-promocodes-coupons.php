<?php

if (!defined('ABSPATH')) {
    exit;
}

class Termburg_VK_Promocodes_Coupons {
    public static function allowed_kinds() {
        $kinds = Termburg_VK_Promocodes_Settings::get('allowed_kinds', array());
        return is_array($kinds) ? array_values(array_filter(array_map('sanitize_key', $kinds))) : array();
    }

    public static function generate_for_vk_user($vk_user_id) {
        if (!class_exists('WC_Coupon')) {
            return new WP_Error('woocommerce_missing', 'WooCommerce is not available');
        }

        $settings = Termburg_VK_Promocodes_Settings::get();
        if ($settings['campaign_enabled'] !== '1') {
            return new WP_Error('campaign_disabled', 'Campaign is disabled');
        }

        $user = Termburg_VK_Promocodes_DB::get_user($vk_user_id);
        if ($user && self::user_has_active_code($user)) {
            return array(
                'code' => $user['promo_code'],
                'coupon_id' => intval($user['coupon_id']),
                'existing' => true,
                'expires_at' => $user['promo_expires_at'],
            );
        }

        if ($user && !self::can_reissue($user, $settings)) {
            return new WP_Error('promo_already_issued', 'Promo code has already been issued');
        }

        $code = self::make_unique_code($settings['code_prefix'], intval($settings['code_length']));
        $coupon = new WC_Coupon();
        $coupon->set_code($code);
        $coupon->set_discount_type($settings['discount_type'] === 'fixed_cart' ? 'fixed_cart' : 'percent');
        $coupon->set_amount((float) $settings['discount_value']);
        $coupon->set_usage_limit(1);
        $coupon->set_usage_limit_per_user(1);
        $coupon->set_description('Termburg VK promo');

        $expires = new DateTime('now', wp_timezone());
        $expires->modify('+' . max(1, intval($settings['expires_days'])) . ' days');
        $coupon->set_date_expires($expires->getTimestamp());
        $coupon_id = $coupon->save();

        update_post_meta($coupon_id, '_termburg_vk_promo', '1');
        update_post_meta($coupon_id, '_termburg_vk_user_id', intval($vk_user_id));
        update_post_meta($coupon_id, '_termburg_campaign_id', 'default');
        update_post_meta($coupon_id, '_termburg_issue_source', 'vk_bot');
        update_post_meta($coupon_id, '_termburg_issued_at', current_time('mysql'));
        update_post_meta($coupon_id, '_termburg_expires_at', $expires->format('Y-m-d H:i:s'));

        Termburg_VK_Promocodes_DB::upsert_user($vk_user_id, array(
            'promo_code' => $code,
            'coupon_id' => intval($coupon_id),
            'promo_created_at' => current_time('mysql'),
            'promo_expires_at' => $expires->format('Y-m-d H:i:s'),
            'last_issue_at' => current_time('mysql'),
            'campaign_id' => 'default',
            'status' => 'active',
        ));

        return array(
            'code' => $code,
            'coupon_id' => intval($coupon_id),
            'existing' => false,
            'expires_at' => $expires->format('Y-m-d H:i:s'),
        );
    }

    public static function validate($code, $line_items, $customer = array()) {
        $settings = Termburg_VK_Promocodes_Settings::get();
        if ($settings['campaign_enabled'] !== '1') {
            return new WP_Error('campaign_disabled', 'Акция сейчас недоступна');
        }

        $code = self::normalize_code($code);
        if ($code === '') {
            return new WP_Error('empty_code', 'Укажите промокод');
        }

        $promocode = Termburg_VK_Promocodes_DB::get_promocode_by_code($code);
        if ($promocode) {
            return self::validate_promocode_record($promocode, $line_items, $customer, $settings);
        }

        if (!class_exists('WC_Coupon')) {
            return new WP_Error('woocommerce_missing', 'WooCommerce is not available');
        }

        $coupon_id = wc_get_coupon_id_by_code($code);
        if (!$coupon_id) {
            return new WP_Error('not_found', 'Промокод не найден');
        }

        if (get_post_meta($coupon_id, '_termburg_vk_promo', true) !== '1') {
            return new WP_Error('wrong_campaign', 'Промокод не относится к этой акции');
        }

        $coupon = new WC_Coupon($coupon_id);
        if (!$coupon->get_id()) {
            return new WP_Error('not_found', 'Промокод не найден');
        }

        $expires = $coupon->get_date_expires();
        if ($expires && $expires->getTimestamp() < time()) {
            return new WP_Error('expired', 'Промокод истек');
        }

        $usage_limit = intval($coupon->get_usage_limit());
        if ($usage_limit > 0 && intval($coupon->get_usage_count()) >= $usage_limit) {
            return new WP_Error('used', 'Промокод уже использован');
        }

        $line_items = self::normalize_line_items($line_items);
        $total = self::line_items_total($line_items);
        $eligible_total = self::eligible_total($line_items, self::allowed_kinds());
        $min_total = (float) $settings['min_order_total'];

        if ($min_total > 0 && $total < $min_total) {
            return new WP_Error('min_total', 'Сумма заказа меньше минимальной для промокода');
        }

        if ($eligible_total <= 0) {
            return new WP_Error('kind_not_allowed', 'Промокод нельзя применить к выбранным товарам');
        }

        if ($settings['first_visit_only'] === '1' && self::customer_has_paid_orders($customer)) {
            return new WP_Error('not_first_visit', 'Промокод действует только на первое посещение');
        }

        $discount = self::calculate_discount($coupon, $eligible_total);
        if ($discount <= 0) {
            return new WP_Error('zero_discount', 'Промокод не дает скидку для этого заказа');
        }

        return array(
            'valid' => true,
            'code' => $coupon->get_code(),
            'coupon_id' => $coupon->get_id(),
            'discount_type' => $coupon->get_discount_type(),
            'discount_value' => (float) $coupon->get_amount(),
            'discount_amount' => $discount,
            'total_before_discount' => $total,
            'eligible_total' => $eligible_total,
            'total_after_discount' => max(0, round($total - $discount, 2)),
            'expires_at' => $expires ? $expires->date('Y-m-d H:i:s') : '',
            'vk_user_id' => intval(get_post_meta($coupon_id, '_termburg_vk_user_id', true)),
            'campaign_id' => (string) get_post_meta($coupon_id, '_termburg_campaign_id', true),
        );
    }

    public static function apply_to_order($order, $validation) {
        if (!$order instanceof WC_Order || empty($validation['code'])) {
            return new WP_Error('invalid_order', 'Invalid order');
        }

        if (!empty($validation['coupon_id'])) {
            $coupon = new WC_Coupon($validation['coupon_id']);
            if (!$coupon->get_id()) {
                return new WP_Error('coupon_not_found', 'Coupon not found');
            }
            $order->apply_coupon($coupon);
        } elseif (class_exists('WC_Order_Item_Fee') && !empty($validation['discount_amount'])) {
            $fee = new WC_Order_Item_Fee();
            $fee->set_name('Промокод ' . sanitize_text_field($validation['code']));
            $fee->set_amount(-1 * abs((float) $validation['discount_amount']));
            $fee->set_total(-1 * abs((float) $validation['discount_amount']));
            $fee->set_tax_status('none');
            $order->add_item($fee);
        }

        $order->update_meta_data('_termburg_vk_promo_code', $validation['code']);
        $order->update_meta_data('_termburg_vk_user_id', intval($validation['vk_user_id']));
        $order->update_meta_data('_termburg_vk_coupon_id', intval($validation['coupon_id']));
        $order->update_meta_data('_termburg_vk_discount_amount', (float) $validation['discount_amount']);
        $order->update_meta_data('_termburg_vk_campaign_id', sanitize_text_field($validation['campaign_id']));
        $order->update_meta_data('_termburg_vk_promo_applied_at', current_time('mysql'));
        $order->calculate_totals();
        $order->save();

        if (Termburg_VK_Promocodes_Settings::get('mark_used_on') === 'created') {
            Termburg_VK_Promocodes_DB::mark_promo_used(intval($validation['coupon_id']), $validation['code']);
        }

        return $order;
    }

    public static function mark_order_paid($order_id) {
        $order = wc_get_order($order_id);
        if (!$order instanceof WC_Order) {
            return;
        }

        $coupon_id = intval($order->get_meta('_termburg_vk_coupon_id'));
        $code = (string) $order->get_meta('_termburg_vk_promo_code');
        if ($coupon_id || $code !== '') {
            Termburg_VK_Promocodes_DB::mark_promo_used($coupon_id, $code);
        }
    }

    public static function normalize_line_items($line_items) {
        $out = array();
        foreach (is_array($line_items) ? $line_items : array() as $item) {
            if (!is_array($item)) {
                continue;
            }

            $name = sanitize_text_field(isset($item['name']) ? $item['name'] : '');
            $price = isset($item['price']) ? round((float) $item['price'], 2) : 0;
            $quantity = max(1, intval(isset($item['quantity']) ? $item['quantity'] : 1));
            $kind = sanitize_key(isset($item['kind']) ? $item['kind'] : 'product');
            $product_key = sanitize_text_field(isset($item['productKey']) ? $item['productKey'] : (isset($item['product_key']) ? $item['product_key'] : ''));
            $product_group = sanitize_key(isset($item['productGroup']) ? $item['productGroup'] : (isset($item['product_group']) ? $item['product_group'] : ''));
            $source = sanitize_key(isset($item['source']) ? $item['source'] : '');
            $source_id = sanitize_text_field(isset($item['sourceId']) ? $item['sourceId'] : (isset($item['source_id']) ? $item['source_id'] : ''));

            if ($name === '' || $price <= 0) {
                continue;
            }

            $out[] = array(
                'name' => $name,
                'price' => $price,
                'quantity' => $quantity,
                'kind' => $kind ?: 'product',
                'product_key' => $product_key,
                'product_group' => $product_group,
                'source' => $source,
                'source_id' => $source_id,
            );
        }

        return $out;
    }

    private static function user_has_active_code($user) {
        if (empty($user['promo_code']) || empty($user['coupon_id']) || !empty($user['promo_used_at'])) {
            return false;
        }

        if (!empty($user['promo_expires_at']) && strtotime($user['promo_expires_at']) < time()) {
            return false;
        }

        $coupon = new WC_Coupon(intval($user['coupon_id']));
        return (bool) $coupon->get_id();
    }

    private static function can_reissue($user, $settings) {
        if ($settings['reissue_policy'] === 'after_expiry') {
            return !empty($user['promo_expires_at']) && strtotime($user['promo_expires_at']) < time();
        }

        if ($settings['reissue_policy'] === 'after_days') {
            if (empty($user['last_issue_at'])) {
                return true;
            }
            return strtotime($user['last_issue_at']) <= strtotime('-' . intval($settings['reissue_days']) . ' days');
        }

        return empty($user['promo_code']);
    }

    private static function make_unique_code($prefix, $length) {
        $prefix = strtoupper(preg_replace('/[^A-Z0-9]/', '', (string) $prefix));
        $prefix = $prefix ?: 'VK';
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

        do {
            $body = '';
            for ($i = 0; $i < $length; $i++) {
                $body .= $alphabet[wp_rand(0, strlen($alphabet) - 1)];
            }
            $code = $prefix . '-' . $body;
        } while (wc_get_coupon_id_by_code($code));

        return $code;
    }

    private static function line_items_total($items) {
        $total = 0;
        foreach ($items as $item) {
            $total += (float) $item['price'] * (int) $item['quantity'];
        }
        return round($total, 2);
    }

    private static function eligible_total($items, $allowed = array()) {
        $allowed = empty($allowed) ? self::allowed_kinds() : array_values(array_filter(array_map('sanitize_key', $allowed)));
        $total = 0;

        foreach ($items as $item) {
            if (self::line_item_allowed($item, $allowed)) {
                $total += (float) $item['price'] * (int) $item['quantity'];
            }
        }

        return round($total, 2);
    }

    private static function calculate_discount($coupon, $eligible_total) {
        return self::calculate_discount_amount($coupon->get_discount_type(), (float) $coupon->get_amount(), $eligible_total);
    }

    private static function validate_promocode_record($promocode, $line_items, $customer, $settings) {
        $campaign = Termburg_VK_Promocodes_Campaigns::get_campaign(intval($promocode['campaign_id']));
        if (!$campaign || $campaign['status'] !== 'active') {
            return new WP_Error('campaign_disabled', 'Акция сейчас недоступна');
        }

        if ($promocode['status'] === 'cancelled') {
            return new WP_Error('cancelled', 'Промокод аннулирован');
        }

        if (!empty($promocode['expires_at']) && strtotime($promocode['expires_at']) < time()) {
            return new WP_Error('expired', 'Промокод истек');
        }

        if (intval($promocode['usage_count']) >= intval($promocode['usage_limit'])) {
            return new WP_Error('used', 'Промокод уже использован');
        }

        $line_items = self::normalize_line_items($line_items);
        $total = self::line_items_total($line_items);
        $allowed = json_decode(isset($promocode['product_groups']) ? $promocode['product_groups'] : '[]', true);
        if (!is_array($allowed) || empty($allowed)) {
            $allowed = Termburg_VK_Promocodes_Campaigns::product_groups($campaign);
        }
        $eligible_total = self::eligible_total($line_items, $allowed);
        $min_total = (float) $settings['min_order_total'];

        if ($min_total > 0 && $total < $min_total) {
            return new WP_Error('min_total', 'Сумма заказа меньше минимальной для промокода');
        }

        if ($eligible_total <= 0) {
            return new WP_Error('kind_not_allowed', 'Промокод нельзя применить к выбранным товарам');
        }

        if ($settings['first_visit_only'] === '1' && self::customer_has_paid_orders($customer)) {
            return new WP_Error('not_first_visit', 'Промокод действует только на первое посещение');
        }

        $discount = self::calculate_discount_amount($promocode['discount_type'], (float) $promocode['discount_value'], $eligible_total);
        if ($discount <= 0) {
            return new WP_Error('zero_discount', 'Промокод не дает скидку для этого заказа');
        }

        return array(
            'valid' => true,
            'code' => $promocode['promo_code'],
            'coupon_id' => 0,
            'promocode_id' => intval($promocode['id']),
            'discount_type' => $promocode['discount_type'],
            'discount_value' => (float) $promocode['discount_value'],
            'discount_amount' => $discount,
            'total_before_discount' => $total,
            'eligible_total' => $eligible_total,
            'total_after_discount' => max(0, round($total - $discount, 2)),
            'expires_at' => $promocode['expires_at'],
            'vk_user_id' => intval($promocode['vk_user_id']),
            'campaign_id' => (string) $promocode['campaign_id'],
        );
    }

    private static function line_item_allowed($item, $allowed) {
        $kind = sanitize_key(isset($item['kind']) ? $item['kind'] : 'product');
        $group = sanitize_key(isset($item['product_group']) ? $item['product_group'] : '');
        $visit_kinds = array('adult_ticket', 'child_ticket', 'child_under6_ticket', 'pensioner_ticket', 'visit_ticket');

        if (in_array($kind, $allowed, true)) {
            return true;
        }

        if ($group !== '' && in_array($group, $allowed, true)) {
            return true;
        }

        return in_array('visit_ticket', $allowed, true) && in_array($kind, $visit_kinds, true);
    }

    private static function calculate_discount_amount($discount_type, $amount, $eligible_total) {
        $amount = (float) $amount;
        if ($discount_type === 'percent') {
            return round($eligible_total * min(100, max(0, $amount)) / 100, 2);
        }

        return round(min($eligible_total, max(0, $amount)), 2);
    }

    private static function normalize_code($code) {
        $code = sanitize_text_field($code);
        return function_exists('wc_format_coupon_code') ? wc_format_coupon_code($code) : strtoupper($code);
    }

    private static function customer_has_paid_orders($customer) {
        if (!function_exists('wc_get_orders')) {
            return false;
        }

        $customer = is_array($customer) ? $customer : array();
        $email = sanitize_email(isset($customer['email']) ? $customer['email'] : '');
        $phone = preg_replace('/\D+/', '', isset($customer['phone']) ? (string) $customer['phone'] : '');

        if ($email === '' && $phone === '') {
            return false;
        }

        if ($email !== '') {
            $orders = wc_get_orders(array(
                'limit' => 1,
                'status' => array('processing', 'completed'),
                'billing_email' => $email,
                'return' => 'ids',
            ));
            if (!empty($orders)) {
                return true;
            }
        }

        if ($phone !== '') {
            $orders = wc_get_orders(array(
                'limit' => 20,
                'status' => array('processing', 'completed'),
                'return' => 'objects',
            ));
            foreach ($orders as $order) {
                if ($order instanceof WC_Order && preg_replace('/\D+/', '', $order->get_billing_phone()) === $phone) {
                    return true;
                }
            }
        }

        return false;
    }
}
