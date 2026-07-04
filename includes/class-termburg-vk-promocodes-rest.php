<?php

if (!defined('ABSPATH')) {
    exit;
}

class Termburg_VK_Promocodes_REST {
    const NS = 'termburg-promocodes/v1';

    public static function register() {
        register_rest_route(self::NS, '/vk/callback', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'vk_callback'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route(self::NS, '/validate', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'validate'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route(self::NS, '/widget', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'widget'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route(self::NS, '/checkout/config', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'checkout_config'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route(self::NS, '/promocodes/issue', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'issue_promocode'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route(self::NS, '/bot/config', array(
            'methods' => 'GET',
            'callback' => array(__CLASS__, 'bot_config'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route(self::NS, '/bot/consent', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'bot_consent'),
            'permission_callback' => '__return_true',
        ));

        register_rest_route(self::NS, '/bot/user-status', array(
            'methods' => 'POST',
            'callback' => array(__CLASS__, 'bot_user_status'),
            'permission_callback' => '__return_true',
        ));
    }

    public static function vk_callback($request) {
        $payload = $request->get_json_params();
        if (!is_array($payload)) {
            return new WP_REST_Response('bad request', 400);
        }

        $type = isset($payload['type']) ? sanitize_key($payload['type']) : '';
        $settings = Termburg_VK_Promocodes_Settings::get();

        if ($type === 'confirmation') {
            return new WP_REST_Response($settings['vk_confirmation'], 200);
        }

        if (!empty($settings['vk_secret'])) {
            $secret = isset($payload['secret']) ? (string) $payload['secret'] : '';
            if (!hash_equals($settings['vk_secret'], $secret)) {
                return new WP_REST_Response('forbidden', 403);
            }
        }

        $event_id = isset($payload['event_id']) ? sanitize_text_field($payload['event_id']) : self::fallback_event_id($payload);
        if (Termburg_VK_Promocodes_DB::event_seen($event_id)) {
            return new WP_REST_Response('ok', 200);
        }

        $vk_user_id = self::extract_user_id($payload);

        try {
            self::dispatch_vk_event($type, $payload, $vk_user_id);
            Termburg_VK_Promocodes_DB::log_event($event_id, $type, $vk_user_id, $payload, 'ok');
        } catch (Throwable $e) {
            Termburg_VK_Promocodes_DB::log_event($event_id, $type, $vk_user_id, $payload, 'error', $e->getMessage());
        }

        return new WP_REST_Response('ok', 200);
    }

    public static function validate($request) {
        $params = $request->get_json_params();
        $params = is_array($params) ? $params : array();

        $result = Termburg_VK_Promocodes_Coupons::validate(
            isset($params['code']) ? $params['code'] : '',
            isset($params['items']) ? $params['items'] : array(),
            array(
                'email' => isset($params['email']) ? $params['email'] : '',
                'phone' => isset($params['phone']) ? $params['phone'] : '',
            )
        );

        if (is_wp_error($result)) {
            return new WP_REST_Response(array(
                'valid' => false,
                'reason' => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ), 200);
        }

        return new WP_REST_Response(array(
            'valid' => true,
            'code' => $result['code'],
            'discountType' => $result['discount_type'],
            'discountValue' => $result['discount_value'],
            'discountAmount' => $result['discount_amount'],
            'totalBeforeDiscount' => $result['total_before_discount'],
            'totalAfterDiscount' => $result['total_after_discount'],
            'eligibleTotal' => $result['eligible_total'],
            'expiresAt' => $result['expires_at'],
            'campaignId' => $result['campaign_id'],
            'campaignName' => $result['campaign_name'],
            'lineDiscounts' => $result['line_discounts'],
            'message' => 'Промокод применен',
        ), 200);
    }

    public static function checkout_config() {
        $settings = Termburg_VK_Promocodes_Settings::get();
        $campaign = Termburg_VK_Promocodes_Campaigns::get_active_campaign();

        return new WP_REST_Response(array(
            'enabled' => $campaign && $campaign['status'] === 'active' && $settings['campaign_enabled'] === '1',
            'label' => $settings['promo_field_label'],
            'placeholder' => $settings['promo_field_placeholder'],
            'button' => $settings['promo_field_button'],
            'appliedText' => $settings['promo_field_applied_text'],
        ), 200);
    }

    public static function widget() {
        $settings = Termburg_VK_Promocodes_Settings::get();
        $campaign = Termburg_VK_Promocodes_Campaigns::get_active_campaign();

        return new WP_REST_Response(array(
            'enabled' => $campaign && $campaign['status'] === 'active' && $settings['widget_enabled'] === '1',
            'delay' => intval($settings['widget_delay']),
            'title' => $settings['widget_title'],
            'text' => $settings['widget_text'],
            'button' => $settings['widget_button'],
            'url' => 'https://vk.me/' . ($settings['vk_group_slug'] ?: 'termburg'),
        ), 200);
    }

    public static function issue_promocode($request) {
        $settings = Termburg_VK_Promocodes_Settings::get();
        $secret = $request->get_header('x-termburg-bot-secret');
        $params = $request->get_json_params();
        $params = is_array($params) ? $params : array();

        if (!self::bot_secret_allowed($settings, $secret, isset($params['secret']) ? (string) $params['secret'] : '')) {
            return new WP_REST_Response(array(
                'issued' => false,
                'reason' => 'forbidden',
                'message' => 'Forbidden',
            ), 403);
        }

        $vk_user_id = isset($params['vkUserId']) ? intval($params['vkUserId']) : 0;
        $user = $vk_user_id ? Termburg_VK_Promocodes_DB::get_user($vk_user_id) : null;
        $has_consent = $user && !empty($user['marketing_consent_at']) && empty($user['marketing_revoked_at']);
        $is_member = $vk_user_id ? Termburg_VK_Promocodes_VK::is_group_member($vk_user_id) : false;

        if (!$has_consent || !$is_member) {
            return new WP_REST_Response(array(
                'issued' => false,
                'reason' => !$has_consent ? 'consent_missing' : 'subscription_missing',
                'message' => !$has_consent ? $settings['bot_consent_text'] : $settings['bot_need_subscription'],
                'messagesAllowed' => $user ? (bool) intval($user['messages_allowed']) : false,
                'marketingConsent' => (bool) $has_consent,
                'groupMember' => (bool) $is_member,
            ), 200);
        }

        $result = Termburg_VK_Promocodes_Campaigns::issue(
            isset($params['campaignId']) ? intval($params['campaignId']) : 0,
            $vk_user_id,
            array(
                'vk_first_name' => isset($params['vkFirstName']) ? $params['vkFirstName'] : '',
                'vk_last_name' => isset($params['vkLastName']) ? $params['vkLastName'] : '',
            )
        );

        if (is_wp_error($result)) {
            return new WP_REST_Response(array(
                'issued' => false,
                'reason' => $result->get_error_code(),
                'message' => $result->get_error_message(),
            ), 200);
        }

        return new WP_REST_Response($result, 200);
    }

    public static function bot_config($request) {
        $settings = Termburg_VK_Promocodes_Settings::get();
        $secret = $request->get_header('x-termburg-bot-secret');
        $param_secret = (string) $request->get_param('secret');

        if (!self::bot_secret_allowed($settings, $secret, $param_secret)) {
            return new WP_REST_Response(array(
                'reason' => 'forbidden',
                'message' => 'Forbidden',
            ), 403);
        }

        $campaign = Termburg_VK_Promocodes_Campaigns::get_active_campaign();

        return new WP_REST_Response(array(
            'callbackUrl' => rest_url('termburg-promocodes/v1/vk/callback'),
            'issueUrl' => rest_url('termburg-promocodes/v1/promocodes/issue'),
            'vk' => array(
                'groupId' => $settings['vk_group_id'],
                'groupSlug' => $settings['vk_group_slug'],
                'apiVersion' => $settings['vk_api_version'],
            ),
            'campaign' => $campaign ? array(
                'id' => intval($campaign['id']),
                'name' => $campaign['name'],
                'status' => $campaign['status'],
            ) : null,
            'texts' => array(
                'intro' => $settings['bot_intro'],
                'consent' => $settings['bot_consent_text'],
                'needSubscription' => $settings['bot_need_subscription'],
                'codeMessage' => $settings['bot_code_message'],
                'existingCodeMessage' => $settings['bot_existing_code_message'],
                'issueErrorMessage' => $settings['bot_issue_error_message'],
                'noActiveCampaignMessage' => $settings['bot_no_active_campaign_message'],
                'unsubscribeMessage' => $settings['bot_unsubscribe_message'],
            ),
            'buttons' => array(
                'subscribe' => $settings['bot_button_subscribe'],
                'consent' => $settings['bot_button_consent'],
                'checkSubscription' => $settings['bot_button_check_subscription'],
                'continue' => $settings['bot_button_continue'],
            ),
            'continueUrl' => $settings['continue_url'],
            'consentTextVersion' => $settings['consent_text_version'],
        ), 200);
    }

    public static function bot_consent($request) {
        $settings = Termburg_VK_Promocodes_Settings::get();
        $params = $request->get_json_params();
        $params = is_array($params) ? $params : array();

        if (!self::bot_secret_allowed($settings, $request->get_header('x-termburg-bot-secret'), isset($params['secret']) ? (string) $params['secret'] : '')) {
            return new WP_REST_Response(array(
                'saved' => false,
                'reason' => 'forbidden',
                'message' => 'Forbidden',
            ), 403);
        }

        $vk_user_id = isset($params['vkUserId']) ? intval($params['vkUserId']) : 0;
        if ($vk_user_id <= 0) {
            return new WP_REST_Response(array(
                'saved' => false,
                'reason' => 'vk_user_missing',
                'message' => 'VK пользователь не указан',
            ), 200);
        }

        Termburg_VK_Promocodes_DB::upsert_user($vk_user_id, array(
            'vk_first_name' => sanitize_text_field(isset($params['vkFirstName']) ? $params['vkFirstName'] : ''),
            'vk_last_name' => sanitize_text_field(isset($params['vkLastName']) ? $params['vkLastName'] : ''),
            'messages_allowed' => 1,
            'marketing_consent_at' => current_time('mysql'),
            'marketing_consent_text_version' => $settings['consent_text_version'],
            'marketing_revoked_at' => null,
        ));

        return new WP_REST_Response(array(
            'saved' => true,
            'vkUserId' => $vk_user_id,
            'consentTextVersion' => $settings['consent_text_version'],
        ), 200);
    }

    public static function bot_user_status($request) {
        $settings = Termburg_VK_Promocodes_Settings::get();
        $params = $request->get_json_params();
        $params = is_array($params) ? $params : array();

        if (!self::bot_secret_allowed($settings, $request->get_header('x-termburg-bot-secret'), isset($params['secret']) ? (string) $params['secret'] : '')) {
            return new WP_REST_Response(array(
                'reason' => 'forbidden',
                'message' => 'Forbidden',
            ), 403);
        }

        $vk_user_id = isset($params['vkUserId']) ? intval($params['vkUserId']) : 0;
        $user = $vk_user_id ? Termburg_VK_Promocodes_DB::get_user($vk_user_id) : null;
        $is_member = $vk_user_id ? Termburg_VK_Promocodes_VK::is_group_member($vk_user_id) : false;
        $has_consent = $user && !empty($user['marketing_consent_at']) && empty($user['marketing_revoked_at']);

        if ($vk_user_id > 0) {
            Termburg_VK_Promocodes_DB::upsert_user($vk_user_id, array(
                'is_group_member' => $is_member ? 1 : 0,
            ));
        }

        return new WP_REST_Response(array(
            'vkUserId' => $vk_user_id,
            'messagesAllowed' => $user ? (bool) intval($user['messages_allowed']) : false,
            'marketingConsent' => (bool) $has_consent,
            'groupMember' => (bool) $is_member,
            'canIssue' => (bool) ($has_consent && $is_member),
        ), 200);
    }

    private static function bot_secret_allowed($settings, $header_secret, $param_secret = '') {
        if (empty($settings['vk_secret'])) {
            return false;
        }

        return hash_equals($settings['vk_secret'], (string) $header_secret) || hash_equals($settings['vk_secret'], (string) $param_secret);
    }

    private static function dispatch_vk_event($type, $payload, $vk_user_id) {
        $object = isset($payload['object']) && is_array($payload['object']) ? $payload['object'] : array();

        if ($type === 'message_new') {
            $message = isset($object['message']) && is_array($object['message']) ? $object['message'] : $object;
            Termburg_VK_Promocodes_VK::handle_user_message(
                $vk_user_id,
                isset($message['text']) ? $message['text'] : '',
                isset($message['payload']) ? $message['payload'] : array()
            );
            return;
        }

        if ($type === 'message_event') {
            Termburg_VK_Promocodes_VK::handle_user_message(
                $vk_user_id,
                '',
                isset($object['payload']) ? $object['payload'] : array()
            );
            return;
        }

        if ($type === 'message_allow') {
            Termburg_VK_Promocodes_VK::handle_message_allow($vk_user_id);
            return;
        }

        if ($type === 'message_deny') {
            Termburg_VK_Promocodes_VK::handle_message_deny($vk_user_id);
        }
    }

    private static function extract_user_id($payload) {
        $object = isset($payload['object']) && is_array($payload['object']) ? $payload['object'] : array();

        if (isset($object['user_id'])) {
            return intval($object['user_id']);
        }

        if (isset($object['message']['from_id'])) {
            return intval($object['message']['from_id']);
        }

        if (isset($object['from_id'])) {
            return intval($object['from_id']);
        }

        return 0;
    }

    private static function fallback_event_id($payload) {
        return hash('sha256', wp_json_encode($payload) . microtime(true));
    }
}
