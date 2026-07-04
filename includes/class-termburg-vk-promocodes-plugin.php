<?php

if (!defined('ABSPATH')) {
    exit;
}

class Termburg_VK_Promocodes_Plugin {
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function activate() {
        Termburg_VK_Promocodes_DB::install();
        if (!get_option(Termburg_VK_Promocodes_Settings::OPTION)) {
            Termburg_VK_Promocodes_Settings::update(Termburg_VK_Promocodes_Settings::defaults());
        }
    }

    private function __construct() {
        add_action('init', array('Termburg_VK_Promocodes_DB', 'maybe_install'));
        add_action('rest_api_init', array('Termburg_VK_Promocodes_REST', 'register'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
        add_action('admin_post_termburg_vk_save_bot_settings', array($this, 'save_bot_settings'));
        add_action('admin_post_termburg_vk_save_campaign', array($this, 'save_campaign'));
        add_action('admin_post_termburg_vk_issue_promocode', array($this, 'issue_promocode'));
        add_action('admin_post_termburg_vk_cancel_promocode', array($this, 'cancel_promocode'));
        add_action('woocommerce_order_status_processing', array($this, 'mark_order_paid'), 20);
        add_action('woocommerce_order_status_completed', array($this, 'mark_order_paid'), 20);
    }

    public function admin_menu() {
        add_menu_page(
            'Termburg VK',
            'Termburg VK',
            'manage_options',
            'termburg-vk-promocodes',
            array($this, 'render_settings_page'),
            'dashicons-megaphone',
            58
        );

        add_submenu_page(
            'termburg-vk-promocodes',
            'Бот',
            'Бот',
            'manage_options',
            'termburg-vk-bot',
            array($this, 'render_bot_page')
        );

        add_submenu_page(
            'termburg-vk-promocodes',
            'Акции',
            'Акции',
            'manage_options',
            'termburg-vk-campaigns',
            array($this, 'render_campaigns_page')
        );

        add_submenu_page(
            'termburg-vk-promocodes',
            'Промокоды',
            'Промокоды',
            'manage_options',
            'termburg-vk-issued-codes',
            array($this, 'render_promocodes_page')
        );
    }

    public function admin_init() {
        register_setting(
            'termburg_vk_promocodes',
            Termburg_VK_Promocodes_Settings::OPTION,
            array('sanitize_callback' => array('Termburg_VK_Promocodes_Settings', 'sanitize'))
        );
    }

    public function mark_order_paid($order_id) {
        Termburg_VK_Promocodes_Coupons::mark_order_paid($order_id);
    }

    public function enqueue_admin_assets($hook) {
        if (strpos((string) $hook, 'termburg-vk') === false) {
            return;
        }

        wp_enqueue_script(
            'termburg-vk-promocodes-admin',
            TERMBURG_VK_PROMOCODES_URL . 'assets/admin.js',
            array(),
            TERMBURG_VK_PROMOCODES_VERSION,
            true
        );
    }

    public function save_campaign() {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }

        check_admin_referer('termburg_vk_save_campaign');
        $campaign_id = Termburg_VK_Promocodes_Campaigns::save_campaign(isset($_POST['campaign']) ? wp_unslash($_POST['campaign']) : array());
        wp_safe_redirect(add_query_arg(array(
            'page' => 'termburg-vk-campaigns',
            'campaign_id' => $campaign_id,
            'updated' => '1',
        ), admin_url('admin.php')));
        exit;
    }

    public function save_bot_settings() {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }

        check_admin_referer('termburg_vk_save_bot_settings');
        $current = Termburg_VK_Promocodes_Settings::get();
        $input = isset($_POST['bot']) && is_array($_POST['bot']) ? wp_unslash($_POST['bot']) : array();
        $fields = array(
            'vk_group_id',
            'vk_group_slug',
            'vk_token',
            'vk_secret',
            'vk_confirmation',
            'vk_api_version',
            'bot_intro',
            'bot_consent_text',
            'bot_need_subscription',
            'bot_code_message',
            'bot_existing_code_message',
            'bot_issue_error_message',
            'bot_no_active_campaign_message',
            'bot_unsubscribe_message',
            'bot_button_subscribe',
            'bot_button_consent',
            'bot_button_check_subscription',
            'bot_button_continue',
            'continue_url',
            'consent_text_version',
            'vk_timeout',
            'vk_retry_limit',
            'log_mode',
        );

        foreach ($fields as $field) {
            if (array_key_exists($field, $input)) {
                $current[$field] = $input[$field];
            }
        }

        Termburg_VK_Promocodes_Settings::update($current);
        wp_safe_redirect(add_query_arg(array(
            'page' => 'termburg-vk-bot',
            'updated' => '1',
        ), admin_url('admin.php')));
        exit;
    }

    public function cancel_promocode() {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }

        $promocode_id = isset($_POST['promocode_id']) ? intval($_POST['promocode_id']) : 0;
        check_admin_referer('termburg_vk_cancel_promocode_' . $promocode_id);
        Termburg_VK_Promocodes_Campaigns::cancel_promocode($promocode_id, isset($_POST['reason']) ? wp_unslash($_POST['reason']) : '');
        wp_safe_redirect(add_query_arg(array(
            'page' => 'termburg-vk-issued-codes',
            'cancelled' => '1',
        ), admin_url('admin.php')));
        exit;
    }

    public function issue_promocode() {
        if (!current_user_can('manage_options')) {
            wp_die('Forbidden');
        }

        check_admin_referer('termburg_vk_issue_promocode');
        $result = Termburg_VK_Promocodes_Campaigns::issue(
            isset($_POST['campaign_id']) ? intval($_POST['campaign_id']) : 0,
            isset($_POST['vk_user_id']) ? intval($_POST['vk_user_id']) : 0,
            array(
                'vk_first_name' => isset($_POST['vk_first_name']) ? wp_unslash($_POST['vk_first_name']) : '',
                'vk_last_name' => isset($_POST['vk_last_name']) ? wp_unslash($_POST['vk_last_name']) : '',
            )
        );

        $args = array(
            'page' => 'termburg-vk-issued-codes',
        );

        if (is_wp_error($result)) {
            $args['issue_error'] = $result->get_error_message();
        } else {
            $args['issued_code'] = $result['code'];
            $args['issued_reused'] = !empty($result['reused']) ? '1' : '0';
        }

        wp_safe_redirect(add_query_arg($args, admin_url('admin.php')));
        exit;
    }

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = Termburg_VK_Promocodes_Settings::get();
        $option = Termburg_VK_Promocodes_Settings::OPTION;
        $callback_url = rest_url('termburg-promocodes/v1/vk/callback');
        $kinds = array(
            'visit_ticket' => 'Все входные билеты',
            'adult_ticket' => 'Взрослый билет',
            'child_ticket' => 'Детский билет',
            'child_under6_ticket' => 'Дети до 6 лет',
            'pensioner_ticket' => 'Пенсионерский билет',
            'service' => 'Услуги',
            'event' => 'Платные события расписания',
            'photo_service' => 'Фотоуслуги',
            'certificate' => 'Сертификаты',
            'subscription' => 'Абонементы',
            'gift_box' => 'Подарочные боксы',
            'merch' => 'Мерч',
            'product' => 'Прочее',
        );
        ?>
        <div class="wrap">
            <h1>Termburg VK Promocodes</h1>
            <form method="post" action="options.php">
                <?php settings_fields('termburg_vk_promocodes'); ?>

                <h2>VK подключение</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Callback URL</th>
                        <td><input type="text" class="regular-text" readonly value="<?php echo esc_attr($callback_url); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">VK group ID</th>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr($option); ?>[vk_group_id]" value="<?php echo esc_attr($settings['vk_group_id']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">VK group slug</th>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr($option); ?>[vk_group_slug]" value="<?php echo esc_attr($settings['vk_group_slug']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">VK access token</th>
                        <td><input type="password" class="regular-text" name="<?php echo esc_attr($option); ?>[vk_token]" value="<?php echo esc_attr($settings['vk_token']); ?>" autocomplete="new-password"></td>
                    </tr>
                    <tr>
                        <th scope="row">VK secret key</th>
                        <td><input type="password" class="regular-text" name="<?php echo esc_attr($option); ?>[vk_secret]" value="<?php echo esc_attr($settings['vk_secret']); ?>" autocomplete="new-password"></td>
                    </tr>
                    <tr>
                        <th scope="row">VK confirmation string</th>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr($option); ?>[vk_confirmation]" value="<?php echo esc_attr($settings['vk_confirmation']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">VK API version</th>
                        <td><input type="text" class="small-text" name="<?php echo esc_attr($option); ?>[vk_api_version]" value="<?php echo esc_attr($settings['vk_api_version']); ?>"></td>
                    </tr>
                </table>

                <h2>Акция и промокоды</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Акция включена</th>
                        <td><input type="hidden" name="<?php echo esc_attr($option); ?>[campaign_enabled]" value="0"><label><input type="checkbox" name="<?php echo esc_attr($option); ?>[campaign_enabled]" value="1" <?php checked($settings['campaign_enabled'], '1'); ?>> Да</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Тип скидки</th>
                        <td>
                            <select name="<?php echo esc_attr($option); ?>[discount_type]">
                                <option value="percent" <?php selected($settings['discount_type'], 'percent'); ?>>Процент</option>
                                <option value="fixed_cart" <?php selected($settings['discount_type'], 'fixed_cart'); ?>>Фиксированная сумма</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Размер скидки</th>
                        <td><input type="number" step="0.01" min="0" class="small-text" name="<?php echo esc_attr($option); ?>[discount_value]" value="<?php echo esc_attr($settings['discount_value']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">Минимальная сумма</th>
                        <td><input type="number" step="0.01" min="0" class="small-text" name="<?php echo esc_attr($option); ?>[min_order_total]" value="<?php echo esc_attr($settings['min_order_total']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">Только первое посещение</th>
                        <td><input type="hidden" name="<?php echo esc_attr($option); ?>[first_visit_only]" value="0"><label><input type="checkbox" name="<?php echo esc_attr($option); ?>[first_visit_only]" value="1" <?php checked($settings['first_visit_only'], '1'); ?>> Да</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Префикс кода</th>
                        <td><input type="text" class="small-text" name="<?php echo esc_attr($option); ?>[code_prefix]" value="<?php echo esc_attr($settings['code_prefix']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">Длина кода</th>
                        <td><input type="number" min="4" max="16" class="small-text" name="<?php echo esc_attr($option); ?>[code_length]" value="<?php echo esc_attr($settings['code_length']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">Срок действия, дней</th>
                        <td><input type="number" min="1" class="small-text" name="<?php echo esc_attr($option); ?>[expires_days]" value="<?php echo esc_attr($settings['expires_days']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">Повторная выдача</th>
                        <td>
                            <select name="<?php echo esc_attr($option); ?>[reissue_policy]">
                                <option value="never" <?php selected($settings['reissue_policy'], 'never'); ?>>Никогда</option>
                                <option value="after_days" <?php selected($settings['reissue_policy'], 'after_days'); ?>>Через N дней</option>
                                <option value="after_expiry" <?php selected($settings['reissue_policy'], 'after_expiry'); ?>>После истечения</option>
                            </select>
                            <input type="number" min="1" class="small-text" name="<?php echo esc_attr($option); ?>[reissue_days]" value="<?php echo esc_attr($settings['reissue_days']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Считать использованным</th>
                        <td>
                            <select name="<?php echo esc_attr($option); ?>[mark_used_on]">
                                <option value="paid" <?php selected($settings['mark_used_on'], 'paid'); ?>>После оплаты</option>
                                <option value="created" <?php selected($settings['mark_used_on'], 'created'); ?>>После создания заказа</option>
                            </select>
                        </td>
                    </tr>
                </table>

                <h2>Типы товаров для скидки</h2>
                <fieldset>
                    <?php foreach ($kinds as $kind => $label) : ?>
                        <label>
                            <input type="checkbox" name="<?php echo esc_attr($option); ?>[allowed_kinds][]" value="<?php echo esc_attr($kind); ?>" <?php checked(in_array($kind, $settings['allowed_kinds'], true)); ?>>
                            <?php echo esc_html($label); ?>
                        </label><br>
                    <?php endforeach; ?>
                </fieldset>

                <h2>Виджет</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Виджет включен</th>
                        <td><input type="hidden" name="<?php echo esc_attr($option); ?>[widget_enabled]" value="0"><label><input type="checkbox" name="<?php echo esc_attr($option); ?>[widget_enabled]" value="1" <?php checked($settings['widget_enabled'], '1'); ?>> Да</label></td>
                    </tr>
                    <tr>
                        <th scope="row">Задержка, секунд</th>
                        <td><input type="number" min="0" class="small-text" name="<?php echo esc_attr($option); ?>[widget_delay]" value="<?php echo esc_attr($settings['widget_delay']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">Заголовок</th>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr($option); ?>[widget_title]" value="<?php echo esc_attr($settings['widget_title']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">Текст</th>
                        <td><textarea class="large-text" rows="2" name="<?php echo esc_attr($option); ?>[widget_text]"><?php echo esc_textarea($settings['widget_text']); ?></textarea></td>
                    </tr>
                    <tr>
                        <th scope="row">Кнопка</th>
                        <td><input type="text" class="regular-text" name="<?php echo esc_attr($option); ?>[widget_button]" value="<?php echo esc_attr($settings['widget_button']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">Продолжить покупки URL</th>
                        <td><input type="url" class="regular-text" name="<?php echo esc_attr($option); ?>[continue_url]" value="<?php echo esc_attr($settings['continue_url']); ?>"></td>
                    </tr>
                </table>

                <h2>Логи и VK API</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Режим логирования</th>
                        <td>
                            <select name="<?php echo esc_attr($option); ?>[log_mode]">
                                <option value="off" <?php selected($settings['log_mode'], 'off'); ?>>Выключено</option>
                                <option value="errors" <?php selected($settings['log_mode'], 'errors'); ?>>Ошибки</option>
                                <option value="all" <?php selected($settings['log_mode'], 'all'); ?>>Все события</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">VK timeout</th>
                        <td><input type="number" min="3" max="30" class="small-text" name="<?php echo esc_attr($option); ?>[vk_timeout]" value="<?php echo esc_attr($settings['vk_timeout']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">VK retry limit</th>
                        <td><input type="number" min="0" max="3" class="small-text" name="<?php echo esc_attr($option); ?>[vk_retry_limit]" value="<?php echo esc_attr($settings['vk_retry_limit']); ?>"></td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    }

    public function render_bot_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = Termburg_VK_Promocodes_Settings::get();
        $callback_url = rest_url('termburg-promocodes/v1/vk/callback');
        $issue_url = rest_url('termburg-promocodes/v1/promocodes/issue');
        $config_url = rest_url('termburg-promocodes/v1/bot/config');
        $consent_url = rest_url('termburg-promocodes/v1/bot/consent');
        $status_url = rest_url('termburg-promocodes/v1/bot/user-status');
        $active_campaign = Termburg_VK_Promocodes_Campaigns::get_active_campaign();
        ?>
        <div class="wrap">
            <h1>VK-бот</h1>
            <?php if (isset($_GET['updated'])) : ?>
                <div class="notice notice-success"><p>Настройки бота сохранены.</p></div>
            <?php endif; ?>
            <h2>Служебные URL</h2>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Callback URL</th>
                    <td><input type="text" class="large-text" readonly value="<?php echo esc_attr($callback_url); ?>"></td>
                </tr>
                <tr>
                    <th scope="row">Выдача промокода</th>
                    <td><input type="text" class="large-text" readonly value="<?php echo esc_attr($issue_url); ?>"></td>
                </tr>
                <tr>
                    <th scope="row">Конфиг бота</th>
                    <td><input type="text" class="large-text" readonly value="<?php echo esc_attr($config_url); ?>"></td>
                </tr>
                <tr>
                    <th scope="row">Сохранить согласие</th>
                    <td><input type="text" class="large-text" readonly value="<?php echo esc_attr($consent_url); ?>"></td>
                </tr>
                <tr>
                    <th scope="row">Статус пользователя</th>
                    <td><input type="text" class="large-text" readonly value="<?php echo esc_attr($status_url); ?>"></td>
                </tr>
                <tr>
                    <th scope="row">Активная акция</th>
                    <td><?php echo $active_campaign ? esc_html($active_campaign['name'] . ' #' . $active_campaign['id']) : 'Нет активной акции'; ?></td>
                </tr>
            </table>

            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('termburg_vk_save_bot_settings'); ?>
                <input type="hidden" name="action" value="termburg_vk_save_bot_settings">

                <h2>VK подключение</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">VK group ID</th>
                        <td><input type="text" class="regular-text" name="bot[vk_group_id]" value="<?php echo esc_attr($settings['vk_group_id']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">VK group slug</th>
                        <td><input type="text" class="regular-text" name="bot[vk_group_slug]" value="<?php echo esc_attr($settings['vk_group_slug']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">VK access token</th>
                        <td><input type="password" class="regular-text" name="bot[vk_token]" value="<?php echo esc_attr($settings['vk_token']); ?>" autocomplete="new-password"></td>
                    </tr>
                    <tr>
                        <th scope="row">VK secret key</th>
                        <td><input type="password" class="regular-text" name="bot[vk_secret]" value="<?php echo esc_attr($settings['vk_secret']); ?>" autocomplete="new-password"></td>
                    </tr>
                    <tr>
                        <th scope="row">VK confirmation string</th>
                        <td><input type="text" class="regular-text" name="bot[vk_confirmation]" value="<?php echo esc_attr($settings['vk_confirmation']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">VK API version</th>
                        <td><input type="text" class="small-text" name="bot[vk_api_version]" value="<?php echo esc_attr($settings['vk_api_version']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">VK timeout</th>
                        <td><input type="number" min="3" max="30" class="small-text" name="bot[vk_timeout]" value="<?php echo esc_attr($settings['vk_timeout']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">VK retry limit</th>
                        <td><input type="number" min="0" max="3" class="small-text" name="bot[vk_retry_limit]" value="<?php echo esc_attr($settings['vk_retry_limit']); ?>"></td>
                    </tr>
                </table>

                <h2>Сценарий и тексты</h2>
                <table class="form-table" role="presentation">
                    <?php
                    $text_fields = array(
                        'bot_intro' => 'Первое сообщение',
                        'bot_consent_text' => 'Текст согласия',
                        'bot_need_subscription' => 'Нет подписки',
                        'bot_code_message' => 'Промокод создан',
                        'bot_existing_code_message' => 'Промокод уже есть',
                        'bot_issue_error_message' => 'Ошибка выдачи',
                        'bot_no_active_campaign_message' => 'Нет активной акции',
                        'bot_unsubscribe_message' => 'Отписка',
                    );
                    foreach ($text_fields as $field => $label) :
                    ?>
                        <tr>
                            <th scope="row"><?php echo esc_html($label); ?></th>
                            <td><textarea class="large-text" rows="2" name="bot[<?php echo esc_attr($field); ?>]"><?php echo esc_textarea($settings[$field]); ?></textarea></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <th scope="row">Версия согласия</th>
                        <td><input type="text" class="small-text" name="bot[consent_text_version]" value="<?php echo esc_attr($settings['consent_text_version']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">Продолжить покупки URL</th>
                        <td><input type="url" class="regular-text" name="bot[continue_url]" value="<?php echo esc_attr($settings['continue_url']); ?>"></td>
                    </tr>
                </table>

                <h2>Кнопки бота</h2>
                <table class="form-table" role="presentation">
                    <?php
                    $button_fields = array(
                        'bot_button_subscribe' => 'Подписаться на сообщество',
                        'bot_button_consent' => 'Согласие на сообщения',
                        'bot_button_check_subscription' => 'Проверить подписку',
                        'bot_button_continue' => 'Продолжить покупки',
                    );
                    foreach ($button_fields as $field => $label) :
                    ?>
                        <tr>
                            <th scope="row"><?php echo esc_html($label); ?></th>
                            <td><input type="text" class="regular-text" name="bot[<?php echo esc_attr($field); ?>]" value="<?php echo esc_attr($settings[$field]); ?>"></td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <h2>Логи</h2>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Режим логирования</th>
                        <td>
                            <select name="bot[log_mode]">
                                <option value="off" <?php selected($settings['log_mode'], 'off'); ?>>Выключено</option>
                                <option value="errors" <?php selected($settings['log_mode'], 'errors'); ?>>Ошибки</option>
                                <option value="all" <?php selected($settings['log_mode'], 'all'); ?>>Все события</option>
                            </select>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Сохранить настройки бота'); ?>
            </form>
        </div>
        <?php
    }

    public function render_campaigns_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        Termburg_VK_Promocodes_Campaigns::ensure_default_campaign();
        $campaign_id = isset($_GET['campaign_id']) ? intval($_GET['campaign_id']) : 0;
        $campaign = $campaign_id ? Termburg_VK_Promocodes_Campaigns::get_campaign($campaign_id) : null;
        $campaigns = Termburg_VK_Promocodes_Campaigns::list_campaigns();
        $settings = Termburg_VK_Promocodes_Settings::get();
        $groups = $this->product_groups();
        $selected_groups = $campaign ? Termburg_VK_Promocodes_Campaigns::product_groups($campaign) : $settings['allowed_kinds'];
        $form = $campaign ?: array(
            'id' => 0,
            'name' => '',
            'slug' => '',
            'status' => 'inactive',
            'discount_type' => 'percent',
            'discount_value' => '10',
            'expires_in_days' => '0',
            'is_lifetime' => '1',
            'usage_limit' => '1',
            'reissue_mode' => 'never',
            'reissue_after_days' => '0',
            'code_prefix' => 'VK',
            'code_length' => '6',
            'total_issue_limit' => '0',
            'daily_issue_limit' => '0',
            'continue_url' => $settings['continue_url'],
            'consent_text_version' => $settings['consent_text_version'],
            'bot_intro' => $settings['bot_intro'],
            'bot_consent_text' => $settings['bot_consent_text'],
            'bot_need_subscription' => $settings['bot_need_subscription'],
            'bot_code_message' => $settings['bot_code_message'],
            'bot_existing_code_message' => $settings['bot_existing_code_message'],
        );
        ?>
        <div class="wrap">
            <h1>Акции VK</h1>
            <?php if (isset($_GET['updated'])) : ?>
                <div class="notice notice-success"><p>Акция сохранена.</p></div>
            <?php endif; ?>
            <h2>Список акций</h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Название</th>
                        <th>Статус</th>
                        <th>Скидка</th>
                        <th>Срок</th>
                        <th>Повторная выдача</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($campaigns as $item) : ?>
                        <tr>
                            <td><?php echo esc_html($item['id']); ?></td>
                            <td><?php echo esc_html($item['name']); ?></td>
                            <td><?php echo esc_html($item['status'] === 'active' ? 'Включена' : 'Выключена'); ?></td>
                            <td><?php echo esc_html($item['discount_type'] === 'percent' ? $item['discount_value'] . '%' : $item['discount_value'] . ' ₽'); ?></td>
                            <td><?php echo esc_html(intval($item['is_lifetime']) === 1 ? 'Бессрочно' : $item['expires_in_days'] . ' дн.'); ?></td>
                            <td><?php echo esc_html($this->reissue_label($item['reissue_mode'], $item['reissue_after_days'])); ?></td>
                            <td><a class="button" href="<?php echo esc_url(add_query_arg(array('page' => 'termburg-vk-campaigns', 'campaign_id' => intval($item['id'])), admin_url('admin.php'))); ?>">Редактировать</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <h2><?php echo $campaign ? 'Редактировать акцию' : 'Новая акция'; ?></h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('termburg_vk_save_campaign'); ?>
                <input type="hidden" name="action" value="termburg_vk_save_campaign">
                <input type="hidden" name="campaign[id]" value="<?php echo esc_attr($form['id']); ?>">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Название</th>
                        <td><input type="text" class="regular-text" name="campaign[name]" value="<?php echo esc_attr($form['name']); ?>" required></td>
                    </tr>
                    <tr>
                        <th scope="row">Slug</th>
                        <td><input type="text" class="regular-text" name="campaign[slug]" value="<?php echo esc_attr($form['slug']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">Статус</th>
                        <td>
                            <select name="campaign[status]">
                                <option value="active" <?php selected($form['status'], 'active'); ?>>Включена</option>
                                <option value="inactive" <?php selected($form['status'], 'inactive'); ?>>Выключена</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Тип скидки</th>
                        <td>
                            <select name="campaign[discount_type]">
                                <option value="percent" <?php selected($form['discount_type'], 'percent'); ?>>Процент</option>
                                <option value="fixed" <?php selected($form['discount_type'], 'fixed'); ?>>Фиксированная сумма</option>
                            </select>
                            <input type="number" step="0.01" min="0" class="small-text" name="campaign[discount_value]" value="<?php echo esc_attr($form['discount_value']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Группы товаров</th>
                        <td>
                            <?php foreach ($groups as $group => $label) : ?>
                                <label><input type="checkbox" name="campaign[product_groups][]" value="<?php echo esc_attr($group); ?>" <?php checked(in_array($group, $selected_groups, true)); ?>> <?php echo esc_html($label); ?></label><br>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Срок действия</th>
                        <td>
                            <label><input type="checkbox" name="campaign[is_lifetime]" value="1" <?php checked(intval($form['is_lifetime']), 1); ?>> Бессрочно</label><br>
                            <input type="number" min="0" class="small-text" name="campaign[expires_in_days]" value="<?php echo esc_attr($form['expires_in_days']); ?>"> дней
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Лимит применений</th>
                        <td><input type="number" min="1" class="small-text" name="campaign[usage_limit]" value="<?php echo esc_attr($form['usage_limit']); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row">Повторная выдача</th>
                        <td>
                            <select name="campaign[reissue_mode]">
                                <option value="never" <?php selected($form['reissue_mode'], 'never'); ?>>Никогда</option>
                                <option value="after_days" <?php selected($form['reissue_mode'], 'after_days'); ?>>Через N дней</option>
                                <option value="after_expiry" <?php selected($form['reissue_mode'], 'after_expiry'); ?>>После истечения</option>
                            </select>
                            <input type="number" min="0" class="small-text" name="campaign[reissue_after_days]" value="<?php echo esc_attr($form['reissue_after_days']); ?>"> дней
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Формат кода</th>
                        <td>
                            Префикс <input type="text" class="small-text" name="campaign[code_prefix]" value="<?php echo esc_attr($form['code_prefix']); ?>">
                            Длина <input type="number" min="4" max="16" class="small-text" name="campaign[code_length]" value="<?php echo esc_attr($form['code_length']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Лимиты выдачи</th>
                        <td>
                            Всего <input type="number" min="0" class="small-text" name="campaign[total_issue_limit]" value="<?php echo esc_attr($form['total_issue_limit']); ?>">
                            В день <input type="number" min="0" class="small-text" name="campaign[daily_issue_limit]" value="<?php echo esc_attr($form['daily_issue_limit']); ?>">
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">Продолжить покупки URL</th>
                        <td><input type="url" class="regular-text" name="campaign[continue_url]" value="<?php echo esc_attr($form['continue_url']); ?>"></td>
                    </tr>
                </table>
                <?php submit_button('Сохранить акцию'); ?>
            </form>
        </div>
        <?php
    }

    public function render_promocodes_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $campaign_id = isset($_GET['campaign_id']) ? intval($_GET['campaign_id']) : 0;
        Termburg_VK_Promocodes_Campaigns::ensure_default_campaign();
        $campaigns = Termburg_VK_Promocodes_Campaigns::list_campaigns();
        $promocodes = Termburg_VK_Promocodes_Campaigns::list_promocodes($campaign_id);
        ?>
        <div class="wrap">
            <h1>Выданные промокоды</h1>
            <?php if (isset($_GET['issued_code'])) : ?>
                <div class="notice notice-success"><p><?php echo esc_html(isset($_GET['issued_reused']) && $_GET['issued_reused'] === '1' ? 'Промокод уже был выдан: ' : 'Промокод выдан: '); ?><strong><?php echo esc_html(wp_unslash($_GET['issued_code'])); ?></strong></p></div>
            <?php endif; ?>
            <?php if (isset($_GET['issue_error'])) : ?>
                <div class="notice notice-error"><p><?php echo esc_html(wp_unslash($_GET['issue_error'])); ?></p></div>
            <?php endif; ?>
            <?php if (isset($_GET['cancelled'])) : ?>
                <div class="notice notice-success"><p>Промокод аннулирован.</p></div>
            <?php endif; ?>

            <h2>Ручная выдача</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('termburg_vk_issue_promocode'); ?>
                <input type="hidden" name="action" value="termburg_vk_issue_promocode">
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">Акция</th>
                        <td>
                            <select name="campaign_id" required>
                                <?php foreach ($campaigns as $campaign) : ?>
                                    <option value="<?php echo esc_attr($campaign['id']); ?>"><?php echo esc_html($campaign['name'] . ' #' . $campaign['id'] . ' · ' . ($campaign['status'] === 'active' ? 'включена' : 'выключена')); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">VK ID</th>
                        <td><input type="number" min="1" class="regular-text" name="vk_user_id" required></td>
                    </tr>
                    <tr>
                        <th scope="row">Имя VK</th>
                        <td>
                            <input type="text" class="regular-text" name="vk_first_name" placeholder="Имя">
                            <input type="text" class="regular-text" name="vk_last_name" placeholder="Фамилия">
                        </td>
                    </tr>
                </table>
                <?php submit_button('Выдать промокод'); ?>
            </form>

            <h2>Список промокодов</h2>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Код</th>
                        <th>Акция</th>
                        <th>VK</th>
                        <th>Статус</th>
                        <th>Выдан</th>
                        <th>Истекает</th>
                        <th>Применений</th>
                        <th>Заказ</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($promocodes as $item) : ?>
                        <tr>
                            <td><strong><?php echo esc_html($item['promo_code']); ?></strong></td>
                            <td><?php echo esc_html($item['campaign_name']); ?></td>
                            <td>
                                <a href="<?php echo esc_url('https://vk.com/id' . intval($item['vk_user_id'])); ?>" target="_blank" rel="noopener noreferrer">
                                    <?php echo esc_html(trim($item['vk_first_name'] . ' ' . $item['vk_last_name']) ?: $item['vk_user_id']); ?>
                                </a>
                            </td>
                            <td><?php echo esc_html(Termburg_VK_Promocodes_Campaigns::display_status($item)); ?></td>
                            <td><?php echo esc_html($item['created_at']); ?></td>
                            <td><?php echo esc_html($item['expires_at'] ?: 'Бессрочно'); ?></td>
                            <td><?php echo esc_html(intval($item['usage_count']) . ' / ' . intval($item['usage_limit'])); ?></td>
                            <td><?php echo $item['order_id'] ? esc_html($item['order_id']) : ''; ?></td>
                            <td>
                                <?php if ($item['status'] !== 'cancelled') : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                                        <?php wp_nonce_field('termburg_vk_cancel_promocode_' . intval($item['id'])); ?>
                                        <input type="hidden" name="action" value="termburg_vk_cancel_promocode">
                                        <input type="hidden" name="promocode_id" value="<?php echo esc_attr($item['id']); ?>">
                                        <input type="text" name="reason" value="" placeholder="Причина">
                                        <?php submit_button('Аннулировать', 'delete small termburg-vk-cancel-submit', '', false); ?>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    private function product_groups() {
        return array(
            'visit_ticket' => 'Все входные билеты',
            'adult_ticket' => 'Взрослый билет',
            'child_ticket' => 'Детский билет',
            'child_under6_ticket' => 'Дети до 6 лет',
            'pensioner_ticket' => 'Пенсионерский билет',
            'service' => 'Услуги',
            'event' => 'Платные события расписания',
            'photo_service' => 'Фотоуслуги',
            'certificate' => 'Сертификаты',
            'subscription' => 'Абонементы',
            'gift_box' => 'Подарочные боксы',
            'merch' => 'Мерч',
            'product' => 'Прочее',
        );
    }

    private function reissue_label($mode, $days) {
        if ($mode === 'after_days') {
            return 'Через ' . intval($days) . ' дн.';
        }

        if ($mode === 'after_expiry') {
            return 'После истечения';
        }

        return 'Никогда';
    }
}
