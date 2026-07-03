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
        add_action('rest_api_init', array('Termburg_VK_Promocodes_REST', 'register'));
        add_action('admin_menu', array($this, 'admin_menu'));
        add_action('admin_init', array($this, 'admin_init'));
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

    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $settings = Termburg_VK_Promocodes_Settings::get();
        $option = Termburg_VK_Promocodes_Settings::OPTION;
        $callback_url = rest_url('termburg-promocodes/v1/vk/callback');
        $kinds = array(
            'visit_ticket' => 'Входной билет',
            'adult_ticket' => 'Взрослый билет',
            'child_ticket' => 'Детский билет',
            'service' => 'Услуги',
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

                <h2>Тексты бота</h2>
                <table class="form-table" role="presentation">
                    <?php
                    $text_fields = array(
                        'bot_intro' => 'Первое сообщение',
                        'bot_consent_text' => 'Текст согласия',
                        'bot_need_subscription' => 'Нет подписки',
                        'bot_code_message' => 'Промокод создан',
                        'bot_existing_code_message' => 'Промокод уже есть',
                        'bot_unsubscribe_message' => 'Отписка',
                    );
                    foreach ($text_fields as $field => $label) :
                    ?>
                        <tr>
                            <th scope="row"><?php echo esc_html($label); ?></th>
                            <td><textarea class="large-text" rows="2" name="<?php echo esc_attr($option); ?>[<?php echo esc_attr($field); ?>]"><?php echo esc_textarea($settings[$field]); ?></textarea></td>
                        </tr>
                    <?php endforeach; ?>
                    <tr>
                        <th scope="row">Версия текста согласия</th>
                        <td><input type="text" class="small-text" name="<?php echo esc_attr($option); ?>[consent_text_version]" value="<?php echo esc_attr($settings['consent_text_version']); ?>"></td>
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
}
