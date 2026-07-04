<?php

if (!defined('ABSPATH')) {
    exit;
}

class Termburg_VK_Promocodes_Settings {
    const OPTION = 'termburg_vk_promocodes_settings';

    public static function defaults() {
        return array(
            'vk_group_id' => '',
            'vk_group_slug' => 'termburg',
            'vk_token' => '',
            'vk_secret' => '',
            'vk_confirmation' => '',
            'vk_api_version' => '5.199',
            'campaign_enabled' => '0',
            'discount_type' => 'percent',
            'discount_value' => '10',
            'min_order_total' => '0',
            'first_visit_only' => '0',
            'code_prefix' => 'VK',
            'code_length' => '6',
            'expires_days' => '14',
            'reissue_policy' => 'never',
            'reissue_days' => '365',
            'mark_used_on' => 'paid',
            'allowed_kinds' => array('visit_ticket'),
            'widget_enabled' => '1',
            'widget_delay' => '5',
            'widget_title' => 'Промокод на первое посещение',
            'widget_text' => 'Подпишитесь на VK и получите персональный промокод.',
            'widget_button' => 'Получить во VK',
            'promo_field_label' => 'Промокод',
            'promo_field_placeholder' => 'Введите промокод',
            'promo_field_button' => 'Применить',
            'promo_field_applied_text' => 'Промокод применен',
            'bot_intro' => 'Чтобы выдать промокод, подпишитесь на сообщество и подтвердите согласие получать сообщения с акциями.',
            'bot_consent_text' => 'Нажимая кнопку согласия, вы соглашаетесь получать сообщения от Термбурга с акциями, новостями и персональными предложениями.',
            'bot_need_subscription' => 'Остался один шаг: подпишитесь на сообщество, и я пришлю промокод.',
            'bot_code_message' => 'Ваш промокод: {code}. Используйте его на сайте при покупке.',
            'bot_existing_code_message' => 'Ваш активный промокод: {code}.',
            'bot_issue_error_message' => 'Не получилось выдать промокод: {message}',
            'bot_no_active_campaign_message' => 'Сейчас нет активной акции для выдачи промокода.',
            'bot_unsubscribe_message' => 'Вы отписались от рекламных сообщений.',
            'bot_button_subscribe' => 'Подписаться на сообщество',
            'bot_button_consent' => 'Согласен получать сообщения',
            'bot_button_check_subscription' => 'Проверить подписку',
            'bot_button_continue' => 'Продолжить покупки',
            'continue_url' => 'https://termburg.ru/',
            'consent_text_version' => '1',
            'log_mode' => 'errors',
            'vk_timeout' => '8',
            'vk_retry_limit' => '1',
        );
    }

    public static function get($key = null, $fallback = null) {
        $settings = get_option(self::OPTION, array());
        if (!is_array($settings)) {
            $settings = array();
        }
        $settings = wp_parse_args($settings, self::defaults());

        if ($key === null) {
            return $settings;
        }

        return array_key_exists($key, $settings) ? $settings[$key] : $fallback;
    }

    public static function update($settings) {
        update_option(self::OPTION, self::sanitize($settings), false);
    }

    public static function sanitize($input) {
        $defaults = self::defaults();
        $input = is_array($input) ? $input : array();
        $allowed_kinds = array('visit_ticket', 'adult_ticket', 'child_ticket', 'child_under6_ticket', 'pensioner_ticket', 'service', 'event', 'photo_service', 'certificate', 'subscription', 'gift_box', 'merch', 'product');
        $out = array();

        foreach ($defaults as $key => $default) {
            if ($key === 'allowed_kinds') {
                $raw = isset($input[$key]) && is_array($input[$key]) ? $input[$key] : array();
                $out[$key] = array_values(array_intersect(array_map('sanitize_key', $raw), $allowed_kinds));
                continue;
            }

            $value = array_key_exists($key, $input) ? $input[$key] : $default;
            if (in_array($key, array('vk_token', 'vk_secret', 'vk_confirmation'), true)) {
                $out[$key] = sanitize_text_field($value);
            } elseif (in_array($key, array('bot_intro', 'bot_consent_text', 'bot_need_subscription', 'bot_code_message', 'bot_existing_code_message', 'bot_issue_error_message', 'bot_no_active_campaign_message', 'bot_unsubscribe_message', 'widget_text'), true)) {
                $out[$key] = sanitize_textarea_field($value);
            } elseif (in_array($key, array('continue_url'), true)) {
                $out[$key] = esc_url_raw($value);
            } else {
                $out[$key] = sanitize_text_field($value);
            }
        }

        if (!in_array($out['discount_type'], array('percent', 'fixed_cart'), true)) {
            $out['discount_type'] = 'percent';
        }
        if (!in_array($out['reissue_policy'], array('never', 'after_days', 'after_expiry'), true)) {
            $out['reissue_policy'] = 'never';
        }
        if (!in_array($out['mark_used_on'], array('created', 'paid'), true)) {
            $out['mark_used_on'] = 'paid';
        }
        if (!in_array($out['log_mode'], array('off', 'errors', 'all'), true)) {
            $out['log_mode'] = 'errors';
        }

        $out['discount_value'] = (string) max(0, floatval($out['discount_value']));
        $out['min_order_total'] = (string) max(0, floatval($out['min_order_total']));
        $out['code_length'] = (string) min(16, max(4, intval($out['code_length'])));
        $out['expires_days'] = (string) min(3650, max(1, intval($out['expires_days'])));
        $out['reissue_days'] = (string) min(3650, max(1, intval($out['reissue_days'])));
        $out['widget_delay'] = (string) min(300, max(0, intval($out['widget_delay'])));
        $out['vk_timeout'] = (string) min(30, max(3, intval($out['vk_timeout'])));
        $out['vk_retry_limit'] = (string) min(3, max(0, intval($out['vk_retry_limit'])));
        $out['campaign_enabled'] = empty($out['campaign_enabled']) ? '0' : '1';
        $out['widget_enabled'] = empty($out['widget_enabled']) ? '0' : '1';
        $out['first_visit_only'] = empty($out['first_visit_only']) ? '0' : '1';

        foreach (array('bot_button_subscribe', 'bot_button_consent', 'bot_button_check_subscription', 'bot_button_continue') as $button_key) {
            if (trim((string) $out[$button_key]) === '') {
                $out[$button_key] = $defaults[$button_key];
            }
        }

        return $out;
    }
}
