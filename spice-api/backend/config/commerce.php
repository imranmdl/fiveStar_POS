<?php

declare(strict_types=1);

use App\Core\Env;

/**
 * Commerce defaults. Anything an administrator should be able to change at
 * runtime lives in the `settings` table instead and is read through
 * SettingRepository; these are the fallbacks when a setting is absent.
 */
return [
    'cart_max_line_items' => Env::int('CART_MAX_LINE_ITEMS', 50),
    'cart_abandon_after_days' => Env::int('CART_ABANDON_AFTER_DAYS', 30),
    'wishlist_max_items' => Env::int('WISHLIST_MAX_ITEMS', 200),

    // Indian MRP is GST-inclusive by law, so the engine EXTRACTS tax from the
    // price rather than adding it. Do not flip this without also revisiting
    // PricingEngine and every invoice template.
    'prices_include_gst' => true,
    'delivery_gst_rate' => (float) Env::get('DELIVERY_GST_RATE', '18'),

    // Wallet credit is a payment tender, never a discount: it lowers the amount
    // payable without changing the order value, so it does not reduce GST. The
    // percentage cap keeps it a supplement to a real payment rather than a
    // replacement for one, which is what makes promotional credit farmable.
    'wallet_max_redeem_percent' => (float) Env::get('WALLET_MAX_REDEEM_PERCENT', '20'),
    'wallet_min_redeem_amount' => Env::get('WALLET_MIN_REDEEM_AMOUNT', '10'),
];
