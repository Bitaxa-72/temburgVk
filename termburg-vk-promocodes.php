<?php
/**
 * Plugin Name: Termburg VK Promocodes
 * Description: VK bot and WooCommerce promo codes for Termburg.
 * Version: 0.1.0
 * Author: Termburg
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TERMBURG_VK_PROMOCODES_VERSION', '0.1.0');
define('TERMBURG_VK_PROMOCODES_FILE', __FILE__);
define('TERMBURG_VK_PROMOCODES_DIR', plugin_dir_path(__FILE__));
define('TERMBURG_VK_PROMOCODES_URL', plugin_dir_url(__FILE__));

require_once TERMBURG_VK_PROMOCODES_DIR . 'includes/class-termburg-vk-promocodes-db.php';
require_once TERMBURG_VK_PROMOCODES_DIR . 'includes/class-termburg-vk-promocodes-settings.php';
require_once TERMBURG_VK_PROMOCODES_DIR . 'includes/class-termburg-vk-promocodes-coupons.php';
require_once TERMBURG_VK_PROMOCODES_DIR . 'includes/class-termburg-vk-promocodes-vk.php';
require_once TERMBURG_VK_PROMOCODES_DIR . 'includes/class-termburg-vk-promocodes-rest.php';
require_once TERMBURG_VK_PROMOCODES_DIR . 'includes/class-termburg-vk-promocodes-plugin.php';
require_once TERMBURG_VK_PROMOCODES_DIR . 'includes/integration-functions.php';

register_activation_hook(__FILE__, array('Termburg_VK_Promocodes_Plugin', 'activate'));

add_action('plugins_loaded', array('Termburg_VK_Promocodes_Plugin', 'instance'));
