<?php

declare(strict_types=1);

namespace App\Services\Orders;

/**
 * The order lifecycle, and the rules about moving through it.
 *
 * Pure: no database, no clock, no request. That is what lets every transition
 * rule be unit-tested exhaustively, which matters because BR-005 lives here —
 * an order must not progress past confirmation without a verified payment, and
 * "we remembered to check in the controller" is not an enforcement mechanism.
 */
final class OrderStatus
{
    public const CREATED = 'created';
    public const AWAITING_PAYMENT = 'awaiting_payment';
    public const CONFIRMED = 'confirmed';
    public const PACKED = 'packed';
    public const READY_TO_SHIP = 'ready_to_ship';
    public const ASSIGNED = 'assigned';
    public const SHIPPED = 'shipped';
    public const OUT_FOR_DELIVERY = 'out_for_delivery';
    public const DELIVERED = 'delivered';
    public const CANCELLED = 'cancelled';
    public const RETURNED = 'returned';
    public const REFUNDED = 'refunded';

    /**
     * Allowed transitions. Anything absent from this table is refused, so a new
     * status cannot be introduced by accident anywhere else in the codebase.
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSITIONS = [
        self::CREATED => [self::AWAITING_PAYMENT, self::CANCELLED],
        self::AWAITING_PAYMENT => [self::CONFIRMED, self::CANCELLED],
        self::CONFIRMED => [self::PACKED, self::CANCELLED],
        // PACKED can go straight to ASSIGNED: booking a courier IS the act of
        // assigning one, and a merchant who books directly from the packing
        // bench never passes through READY_TO_SHIP. That status remains for
        // operations that label and stage parcels before calling a courier.
        self::PACKED => [self::READY_TO_SHIP, self::ASSIGNED, self::CANCELLED],
        self::READY_TO_SHIP => [self::ASSIGNED, self::CANCELLED],
        self::ASSIGNED => [self::SHIPPED, self::CANCELLED],
        // SHIPPED can reach DELIVERED directly. Hyperlocal and same-city
        // couriers routinely go from pickup straight to delivered with no
        // out-for-delivery scan in between, and refusing that leaves the order
        // stuck at "shipped" forever: no completion, no commission, and a
        // customer looking at a parcel they are holding while the site insists
        // it is in transit.
        self::SHIPPED => [self::OUT_FOR_DELIVERY, self::DELIVERED, self::RETURNED],
        self::OUT_FOR_DELIVERY => [self::DELIVERED, self::RETURNED],
        self::DELIVERED => [self::RETURNED],
        self::RETURNED => [self::REFUNDED],
        self::CANCELLED => [self::REFUNDED],
        self::REFUNDED => [],
    ];

    /**
     * Statuses that require a verified, captured payment before they can be
     * entered. This is BR-005 expressed as data.
     *
     * @var array<int, string>
     */
    public const REQUIRES_PAYMENT = [
        self::CONFIRMED,
        self::PACKED,
        self::READY_TO_SHIP,
        self::ASSIGNED,
        self::SHIPPED,
        self::OUT_FOR_DELIVERY,
        self::DELIVERED,
    ];

    /**
     * Statuses only staff may set.
     *
     * Defence in depth: the fulfilment routes are already role-guarded, but the
     * state machine should refuse these for a customer regardless of how it is
     * reached. It also keeps availableTransitions() honest — without this, a
     * customer's order page would render a "Mark as packed" button that the API
     * would then reject.
     *
     * @var array<int, string>
     */
    public const STAFF_ONLY = [
        self::PACKED,
        self::READY_TO_SHIP,
        self::ASSIGNED,
        self::SHIPPED,
        self::OUT_FOR_DELIVERY,
        self::DELIVERED,
        self::RETURNED,
        self::REFUNDED,
    ];

    /** Statuses from which nothing further can happen. */
    public const TERMINAL = [self::REFUNDED];

    /** Customer-facing labels for the tracking page. */
    public const LABELS = [
        self::CREATED => 'Order started',
        self::AWAITING_PAYMENT => 'Awaiting payment',
        self::CONFIRMED => 'Order confirmed',
        self::PACKED => 'Packed',
        self::READY_TO_SHIP => 'Ready to ship',
        self::ASSIGNED => 'Handed to courier',
        self::SHIPPED => 'Shipped',
        self::OUT_FOR_DELIVERY => 'Out for delivery',
        self::DELIVERED => 'Delivered',
        self::CANCELLED => 'Cancelled',
        self::RETURNED => 'Returned',
        self::REFUNDED => 'Refunded',
    ];

    /** @return array<int, string> */
    public static function all(): array
    {
        return array_keys(self::TRANSITIONS);
    }

    public static function exists(string $status): bool
    {
        return array_key_exists($status, self::TRANSITIONS);
    }

    public static function label(string $status): string
    {
        return self::LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    public static function isTerminal(string $status): bool
    {
        return in_array($status, self::TERMINAL, true);
    }

    public static function requiresPayment(string $status): bool
    {
        return in_array($status, self::REQUIRES_PAYMENT, true);
    }

    public static function isStaffOnly(string $status): bool
    {
        return in_array($status, self::STAFF_ONLY, true);
    }

    /** Whether a customer may still cancel from this status. */
    public static function isCustomerCancellable(string $status): bool
    {
        return in_array($status, [
            self::CREATED,
            self::AWAITING_PAYMENT,
            self::CONFIRMED,
            self::PACKED,
        ], true);
    }

    /** Statuses in display order, for a progress bar. */
    public static function fulfilmentSequence(): array
    {
        return [
            self::CONFIRMED,
            self::PACKED,
            self::READY_TO_SHIP,
            self::SHIPPED,
            self::OUT_FOR_DELIVERY,
            self::DELIVERED,
        ];
    }
}
