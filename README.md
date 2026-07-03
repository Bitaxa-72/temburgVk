# temburgVk

WordPress plugin for Termburg VK promo bot.

## Features

- VK Callback API endpoint.
- Bot flow for group subscription and marketing consent.
- WooCommerce coupon generation.
- Promo code validation REST endpoint.
- Site widget config REST endpoint.
- Admin settings for VK connection, campaign, product kinds and texts.
- Public PHP integration functions for checkout.

## Install

1. Copy the repository folder to `wp-content/plugins/termburg-vk-promocodes`.
2. Activate `Termburg VK Promocodes` in WordPress admin.
3. Open `Termburg VK` in WordPress admin.
4. Fill VK token, group ID, secret and confirmation string.
5. Add Callback URL in VK:

```text
https://termburg.ru/wp-json/termburg-promocodes/v1/vk/callback
```

## REST

```text
POST /wp-json/termburg-promocodes/v1/vk/callback
POST /wp-json/termburg-promocodes/v1/validate
GET  /wp-json/termburg-promocodes/v1/widget
```

## Checkout Integration

Use these functions from the site checkout code:

```php
termburg_promocodes_validate_checkout_code($code, $line_items, $customer);
termburg_promocodes_apply_to_order($order, $validation_result);
termburg_promocodes_mark_order_paid($order_id);
```

`$line_items` must include explicit `kind` values where possible.

Recommended first-stage allowed kinds:

- `visit_ticket`
- `adult_ticket`
- `child_ticket`

## Status

Preliminary version. Payment receipt behavior must be tested on a real YooKassa test order after checkout integration.
