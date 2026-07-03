<?php

if (!defined('ABSPATH')) {
    exit;
}

class Termburg_VK_Promocodes_VK {
    public static function handle_user_message($vk_user_id, $text = '', $payload = array()) {
        $vk_user_id = intval($vk_user_id);
        if (!$vk_user_id) {
            return;
        }

        Termburg_VK_Promocodes_DB::upsert_user($vk_user_id, array(
            'messages_allowed' => 1,
        ));

        $command = self::payload_command($payload);
        $normalized_text = function_exists('mb_strtolower') ? mb_strtolower(trim((string) $text), 'UTF-8') : strtolower(trim((string) $text));

        if ($normalized_text === 'отписаться') {
            self::revoke_consent($vk_user_id);
            return;
        }

        if ($command === 'consent') {
            self::save_consent($vk_user_id);
            self::try_issue_code($vk_user_id);
            return;
        }

        if ($command === 'check_subscription') {
            self::try_issue_code($vk_user_id);
            return;
        }

        self::send_intro($vk_user_id);
    }

    public static function handle_message_allow($vk_user_id) {
        Termburg_VK_Promocodes_DB::upsert_user(intval($vk_user_id), array(
            'messages_allowed' => 1,
        ));
    }

    public static function handle_message_deny($vk_user_id) {
        Termburg_VK_Promocodes_DB::upsert_user(intval($vk_user_id), array(
            'messages_allowed' => 0,
            'marketing_revoked_at' => current_time('mysql'),
        ));
    }

    public static function save_consent($vk_user_id) {
        Termburg_VK_Promocodes_DB::upsert_user($vk_user_id, array(
            'marketing_consent_at' => current_time('mysql'),
            'marketing_consent_text_version' => Termburg_VK_Promocodes_Settings::get('consent_text_version', '1'),
            'marketing_revoked_at' => null,
            'messages_allowed' => 1,
        ));
    }

    public static function revoke_consent($vk_user_id) {
        Termburg_VK_Promocodes_DB::upsert_user($vk_user_id, array(
            'marketing_revoked_at' => current_time('mysql'),
        ));
        self::send_message($vk_user_id, Termburg_VK_Promocodes_Settings::get('bot_unsubscribe_message'), self::default_keyboard());
    }

    public static function send_intro($vk_user_id) {
        $settings = Termburg_VK_Promocodes_Settings::get();
        $message = trim($settings['bot_intro'] . "\n\n" . $settings['bot_consent_text']);
        self::send_message($vk_user_id, $message, self::default_keyboard());
    }

    public static function try_issue_code($vk_user_id) {
        $settings = Termburg_VK_Promocodes_Settings::get();
        $user = Termburg_VK_Promocodes_DB::get_user($vk_user_id);
        $has_consent = $user && !empty($user['marketing_consent_at']) && empty($user['marketing_revoked_at']);
        $is_member = self::is_group_member($vk_user_id);

        Termburg_VK_Promocodes_DB::upsert_user($vk_user_id, array(
            'is_group_member' => $is_member ? 1 : 0,
            'messages_allowed' => 1,
        ));

        if (!$has_consent) {
            self::send_intro($vk_user_id);
            return;
        }

        if (!$is_member) {
            self::send_message($vk_user_id, $settings['bot_need_subscription'], self::default_keyboard());
            return;
        }

        $result = Termburg_VK_Promocodes_Coupons::generate_for_vk_user($vk_user_id);
        if (is_wp_error($result)) {
            self::send_message($vk_user_id, $result->get_error_message(), self::default_keyboard());
            return;
        }

        $template = !empty($result['existing']) ? $settings['bot_existing_code_message'] : $settings['bot_code_message'];
        $message = str_replace('{code}', $result['code'], $template);
        self::send_message($vk_user_id, $message, self::continue_keyboard());
    }

    public static function is_group_member($vk_user_id) {
        $settings = Termburg_VK_Promocodes_Settings::get();
        if (empty($settings['vk_group_id']) || empty($settings['vk_token'])) {
            return false;
        }

        $response = self::api('groups.isMember', array(
            'group_id' => $settings['vk_group_id'],
            'user_id' => intval($vk_user_id),
        ));

        if (is_wp_error($response)) {
            return false;
        }

        return !empty($response['response']);
    }

    public static function send_message($vk_user_id, $message, $keyboard = null) {
        $params = array(
            'user_id' => intval($vk_user_id),
            'random_id' => wp_rand(1, PHP_INT_MAX),
            'message' => (string) $message,
        );

        if ($keyboard) {
            $params['keyboard'] = wp_json_encode($keyboard, JSON_UNESCAPED_UNICODE);
        }

        return self::api('messages.send', $params);
    }

    public static function api($method, $params) {
        $settings = Termburg_VK_Promocodes_Settings::get();
        if (empty($settings['vk_token'])) {
            return new WP_Error('vk_token_missing', 'VK token is missing');
        }

        $params['access_token'] = $settings['vk_token'];
        $params['v'] = $settings['vk_api_version'];

        $response = wp_remote_post('https://api.vk.com/method/' . sanitize_key($method), array(
            'timeout' => intval($settings['vk_timeout']),
            'body' => $params,
        ));

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            return new WP_Error('vk_invalid_response', 'Invalid VK response');
        }

        if (!empty($body['error'])) {
            return new WP_Error('vk_api_error', isset($body['error']['error_msg']) ? $body['error']['error_msg'] : 'VK API error');
        }

        return $body;
    }

    private static function payload_command($payload) {
        if (is_string($payload)) {
            $decoded = json_decode($payload, true);
            $payload = is_array($decoded) ? $decoded : array();
        }

        return isset($payload['command']) ? sanitize_key($payload['command']) : '';
    }

    private static function default_keyboard() {
        $settings = Termburg_VK_Promocodes_Settings::get();
        $group_slug = $settings['vk_group_slug'] ?: 'termburg';

        return array(
            'one_time' => false,
            'inline' => false,
            'buttons' => array(
                array(
                    array(
                        'action' => array(
                            'type' => 'open_link',
                            'link' => 'https://vk.com/' . rawurlencode($group_slug),
                            'label' => 'Подписаться на сообщество',
                        ),
                    ),
                ),
                array(
                    array(
                        'action' => array(
                            'type' => 'callback',
                            'label' => 'Согласен получать сообщения',
                            'payload' => wp_json_encode(array('command' => 'consent'), JSON_UNESCAPED_UNICODE),
                        ),
                        'color' => 'positive',
                    ),
                ),
                array(
                    array(
                        'action' => array(
                            'type' => 'callback',
                            'label' => 'Проверить подписку',
                            'payload' => wp_json_encode(array('command' => 'check_subscription'), JSON_UNESCAPED_UNICODE),
                        ),
                        'color' => 'primary',
                    ),
                ),
            ),
        );
    }

    private static function continue_keyboard() {
        $url = Termburg_VK_Promocodes_Settings::get('continue_url', home_url('/'));

        return array(
            'one_time' => false,
            'inline' => false,
            'buttons' => array(
                array(
                    array(
                        'action' => array(
                            'type' => 'open_link',
                            'link' => $url,
                            'label' => 'Продолжить покупки',
                        ),
                    ),
                ),
            ),
        );
    }
}
