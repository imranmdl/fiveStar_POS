<?php

declare(strict_types=1);

use App\Core\Env;

/**
 * Courier integration configuration.
 *
 * `shiprocket` is an aggregator: one contract fronting Delhivery, Blue Dart,
 * XpressBees and DTDC. Which of those carries a given parcel is decided by
 * BR-007 scoring and passed as a channel code, not by a separate integration.
 *
 * `sandbox` books deterministically and refuses to construct outside a local or
 * testing environment, so a production deployment left pointing at it fails at
 * boot rather than filling a warehouse with parcels carrying fake AWBs.
 *
 * `manual` runs with no courier API at all: staff hand parcels to a courier
 * outside this system and enter tracking details themselves. Customers see a
 * static "manual_delivery_min_days-manual_delivery_max_days business days"
 * estimate instead of a live serviceability check. Meant as a bridge while
 * Shiprocket is being finished — see GO_LIVE.md for the cutover to `shiprocket`.
 *
 * The driver here is only the DEPLOY-TIME default; it can be overridden per
 * environment without a redeploy via the `delivery_driver` row in `settings`,
 * editable from /admin/settings.
 */
return [
    'driver' => Env::get('COURIER_DRIVER', 'manual'),
    'timeout_seconds' => Env::int('COURIER_TIMEOUT_SECONDS', 25),

    'shiprocket' => [
        'email' => Env::get('SHIPROCKET_EMAIL', ''),
        'password' => Env::get('SHIPROCKET_PASSWORD', ''),
        // Configured on the Shiprocket webhook page. Without it, tracking
        // webhooks cannot be told apart from anyone posting to the endpoint.
        'webhook_secret' => Env::get('SHIPROCKET_WEBHOOK_SECRET', ''),
        // Must match a pickup location registered in the Shiprocket dashboard.
        'pickup_location' => Env::get('SHIPROCKET_PICKUP_LOCATION', 'Primary'),
    ],

    'sandbox' => [
        'secret' => Env::get('SANDBOX_COURIER_SECRET', 'sandbox-courier-secret-change-me'),
    ],
];
