<?php

declare(strict_types=1);

namespace App\Services\Delivery;

use App\Helpers\Money;

/**
 * The contract every courier integration implements.
 *
 * Same shape as PaymentGatewayInterface, and for the same reason: courier
 * contracts get renegotiated, aggregators get replaced, and none of that should
 * reach ShipmentService.
 *
 * A note on aggregators. Shiprocket, Shipway and their peers front Delhivery,
 * Blue Dart, XpressBees and DTDC under one contract and one API. For a merchant
 * at this scale that is almost always the right choice: one integration, one
 * reconciliation, no per-courier minimum volumes. Direct integrations start to
 * pay once volume is high enough to negotiate rates individually — which is why
 * `couriers.adapter` is per-row, so a merchant can move one courier direct
 * while leaving the rest with the aggregator.
 *
 * Implementations MUST:
 *   - normalise courier-specific scan codes into TrackingUpdate constants;
 *   - be safe to call twice with the same inputs;
 *   - never throw for an ordinary business refusal — return a failed
 *     ShipmentBooking so the reason is recorded against the shipment.
 */
interface CourierAdapterInterface
{
    /** Identifier stored on every shipment, e.g. 'shiprocket'. */
    public function name(): string;

    /**
     * Live rates, when the courier offers them.
     *
     * Returns null when the adapter cannot quote, in which case the caller uses
     * the negotiated rate card. A slow or broken rate API must never stop a
     * parcel being booked.
     *
     * @param array<string, mixed> $courier
     */
    public function quote(array $courier, ParcelSpec $parcel): ?CourierQuote;

    /**
     * Books the parcel and gets an AWB.
     *
     * @param array<string, mixed> $courier
     * @param array<string, mixed> $order    Snapshot: address, contact, value
     */
    public function book(array $courier, ParcelSpec $parcel, array $order): ShipmentBooking;

    /**
     * The shipping label, as a URL or base64 PDF.
     *
     * @param array<string, mixed> $courier
     */
    public function label(array $courier, string $awbNumber): ?string;

    /**
     * Asks the courier to collect.
     *
     * @param array<string, mixed> $courier
     * @param array<int, string>   $awbNumbers
     *
     * @return array{success:bool, reference:?string, message:?string, raw:array<string, mixed>}
     */
    public function schedulePickup(array $courier, array $awbNumbers, string $pickupDate, array $contact): array;

    /**
     * Current tracking history, newest last.
     *
     * @param array<string, mixed> $courier
     *
     * @return array<int, TrackingUpdate>
     */
    public function track(array $courier, string $awbNumber): array;

    /**
     * Cancels a booked parcel.
     *
     * @param array<string, mixed> $courier
     *
     * @return array{success:bool, message:?string}
     */
    public function cancel(array $courier, string $awbNumber): array;

    /**
     * Verifies and parses an inbound tracking webhook.
     *
     * Returns null when the signature does not verify, which the caller treats
     * as "record it and act on nothing" — the same rule as payment webhooks.
     *
     * @return array{awb:string, updates:array<int, TrackingUpdate>}|null
     */
    public function parseWebhook(string $rawBody, string $signature): ?array;

    /**
     * The manifest document for a set of parcels.
     *
     * @param array<string, mixed> $courier
     * @param array<int, string>   $awbNumbers
     */
    public function manifest(array $courier, array $awbNumbers): ?string;
}
