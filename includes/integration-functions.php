<?php

if (!defined('ABSPATH')) {
    exit;
}

function termburg_promocodes_validate_checkout_code($code, $line_items, $customer = array()) {
    return Termburg_VK_Promocodes_Coupons::validate($code, $line_items, $customer);
}

function termburg_promocodes_apply_to_order($order, $validation_result) {
    return Termburg_VK_Promocodes_Coupons::apply_to_order($order, $validation_result);
}

function termburg_promocodes_mark_order_paid($order_id) {
    Termburg_VK_Promocodes_Coupons::mark_order_paid($order_id);
}
