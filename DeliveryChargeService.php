<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
use App\Helpers\Money;
use App\Repositories\DeliveryZoneRepository;
use App\Repositories\SettingRepository;
use App\Services\Pricing\DeliveryQuote;

/**
 * BR-006: delivery charges are calculated, never fixed.
 *
 * The calculation is deliberately separate from the pricing engine so charge
 * policy can change — new zones, renegotiated courier rates, a festive free
 * shipping campaign — without touching the arithmetic that produces totals.
 *
 * Resolution order:
 *   1. Pincode → zone by longest matching prefix, falling back to the default
 *      zone so an unmapped pincode is still quotable rather than a dead end.
 *   2. Weight → charge band. The heaviest band is open-ended with a per-kg
 *      rate, so no order weight is unquotable.
 *   3. Free-shipping waiver: the zone's own threshold if set, otherwise the
 *      global `free_delivery_threshold` setting. Remote zones deliberately have
 *      no threshold because that freight is genuinely expensive.
 *
 * This service is also what Phase 6 will consult when choosing a courier
 * (BR-007), since zone and chargeable weight are exactly the inputs that
 * decision needs.
 */
final class DeliveryChargeService
{
    public function __construct(
        private readonly DeliveryZoneRepository $zones,
        private readonly SettingRepository $settings,
        private readonly Config $config,
    ) {
    }

    /**
     * @param Money $orderValue The item subtotal, used for the free-shipping test.
     */
    public function quote(?string $pincode, int $weightGrams, Money $orderValue): DeliveryQuote
    {
        if ($pincode === null || trim($pincode) === '') {
            return DeliveryQuote::none('Enter a delivery pincode to see shipping charges');
        }

        if ($weightGrams <= 0) {
            return DeliveryQuote::none('No shippable items in the cart');
        }

        $zone = $this->zones->findZoneForPincode($pincode) ?? $this->zones->defaultZone();

        if ($zone === null) {
            // Misconfiguration rather than a customer error: no default zone
            // exists. Say so plainly instead of quoting zero and shipping free.
            return DeliveryQuote::none('Delivery charges are not configured for this destination');
        }

        if ((int) $zone['is_serviceable'] !== 1) {
            return new DeliveryQuote(
                zoneCode: (string) $zone['code'],
                zoneName: (string) $zone['name'],
                charge: Money::zero(),
                chargeBeforeWaiver: Money::zero(),
                gstRate: 0.0,
                slaMinDays: 0,
                slaMaxDays: 0,
                chargeableWeightGrams: $weightGrams,
                isServiceable: false,
                waiverReason: null,
            );
        }

        $slab = $this->zones->findSlabForWeight((int) $zone['id'], $weightGrams);

        if ($slab === null) {
            return DeliveryQuote::none(
                'No delivery rate is configured for this weight in ' . $zone['name']
            );
        }

        $charge = $this->chargeForSlab($slab, $weightGrams);
        $chargeBeforeWaiver = $charge;

        [$waived, $reason, $shortfall] = $this->evaluateWaiver($slab, $orderValue, $charge);

        if ($waived) {
            $charge = Money::zero();
        }

        return new DeliveryQuote(
            zoneCode: (string) $zone['code'],
            zoneName: (string) $zone['name'],
            charge: $charge,
            chargeBeforeWaiver: $chargeBeforeWaiver,
            gstRate: $this->deliveryGstRate(),
            slaMinDays: (int) $zone['sla_min_days'],
            slaMaxDays: (int) $zone['sla_max_days'],
            chargeableWeightGrams: $weightGrams,
            isServiceable: true,
            waiverReason: $reason,
            spendMoreForFreeDelivery: $shortfall,
        );
    }

    /**
     * Serviceability check for the pincode field on the address form, so a
     * customer learns about a problem before they reach checkout.
     *
     * @return array<string, mixed>
     */
    public function checkServiceability(string $pincode): array
    {
        $digits = preg_replace('/\D/', '', $pincode) ?? '';
        $zone = $this->zones->findZoneForPincode($digits);
        $usedDefault = false;

        if ($zone === null) {
            $zone = $this->zones->defaultZone();
            $usedDefault = true;
        }

        if ($zone === null) {
            return [
                'pincode' => $digits,
                'is_serviceable' => false,
                'message' => 'Delivery is not configured for this pincode yet.',
            ];
        }

        $serviceable = (int) $zone['is_serviceable'] === 1;

        return [
            'pincode' => $digits,
            'is_serviceable' => $serviceable,
            'zone_code' => $zone['code'],
            'zone_name' => $zone['name'],
            'matched_by_default_zone' => $usedDefault,
            'estimated_days' => [
                'min' => (int) $zone['sla_min_days'],
                'max' => (int) $zone['sla_max_days'],
            ],
            'free_delivery_above' => $this->freeThresholdForZone((int) $zone['id'])?->toDecimal(),
            'message' => $serviceable
                ? sprintf(
                    'Delivers in %d-%d days to %s.',
                    (int) $zone['sla_min_days'],
                    (int) $zone['sla_max_days'],
                    $zone['name']
                )
                : 'We do not currently deliver to this pincode.',
        ];
    }

    /**
     * Published rate card, so the storefront can show a shipping policy page
     * generated from the same data that charges the customer.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rateCard(): array
    {
        $card = [];

        foreach ($this->zones->allServiceableZones() as $zone) {
            $zoneRow = $this->zones->findByCode((string) $zone['code']);

            if ($zoneRow === null) {
                continue;
            }

            $bands = [];

            foreach ($this->zones->slabsForZone((int) $zoneRow['id']) as $slab) {
                $bands[] = [
                    'from_grams' => (int) $slab['min_weight_grams'],
                    'to_grams' => $slab['max_weight_grams'] === null
                        ? null
                        : (int) $slab['max_weight_grams'],
                    'charge' => (float) $slab['charge_amount'],
                    'per_extra_kg' => (float) $slab['per_extra_kg_amount'],
                    'free_above_order_value' => $slab['free_above_order_value'] === null
                        ? null
                        : (float) $slab['free_above_order_value'],
                ];
            }

            $card[] = [
                'zone_code' => $zone['code'],
                'zone_name' => $zone['name'],
                'estimated_days' => [
                    'min' => (int) $zone['sla_min_days'],
                    'max' => (int) $zone['sla_max_days'],
                ],
                'is_serviceable' => (bool) $zone['is_serviceable'],
                'weight_bands' => $bands,
            ];
        }

        return $card;
    }

    /**
     * @param array<string, mixed> $slab
     */
    private function chargeForSlab(array $slab, int $weightGrams): Money
    {
        $charge = Money::fromDecimal((string) $slab['charge_amount']);
        $bandCeiling = $slab['max_weight_grams'];

        // Open-ended top band: add the per-kg rate for every kilo above it,
        // rounding part-kilos up the way couriers actually bill.
        if ($bandCeiling !== null) {
            return $charge;
        }

        $perExtraKg = Money::fromDecimal((string) $slab['per_extra_kg_amount']);

        if ($perExtraKg->isZero()) {
            return $charge;
        }

        $overflowGrams = $weightGrams - (int) $slab['min_weight_grams'];

        if ($overflowGrams <= 0) {
            return $charge;
        }

        $extraKilos = (int) ceil($overflowGrams / 1000);

        return $charge->add($perExtraKg->multiply($extraKilos));
    }

    /**
     * @param array<string, mixed> $slab
     *
     * @return array{0:bool, 1:?string, 2:?Money}
     */
    private function evaluateWaiver(array $slab, Money $orderValue, Money $charge): array
    {
        $threshold = $slab['free_above_order_value'] === null
            ? $this->globalFreeThreshold()
            : Money::fromDecimal((string) $slab['free_above_order_value']);

        if ($threshold === null || !$threshold->isPositive()) {
            return [false, null, null];
        }

        if ($orderValue->greaterThanOrEqual($threshold)) {
            return [
                true,
                sprintf('Free delivery on orders above %s', $threshold->format()),
                null,
            ];
        }

        if ($charge->isZero()) {
            return [false, null, null];
        }

        // Tell the customer exactly how much more unlocks free delivery. This
        // is the single most effective line of copy on a cart page.
        return [false, null, $threshold->subtract($orderValue)];
    }

    private function freeThresholdForZone(int $zoneId): ?Money
    {
        foreach ($this->zones->slabsForZone($zoneId) as $slab) {
            if ($slab['free_above_order_value'] !== null) {
                return Money::fromDecimal((string) $slab['free_above_order_value']);
            }
        }

        return $this->globalFreeThreshold();
    }

    private function globalFreeThreshold(): ?Money
    {
        $value = $this->settings->value('free_delivery_threshold');

        if ($value === null || $value === '' || (float) $value <= 0) {
            return null;
        }

        return Money::fromDecimal($value);
    }

    private function deliveryGstRate(): float
    {
        $configured = $this->settings->value('delivery_gst_rate');

        if ($configured !== null && $configured !== '') {
            return (float) $configured;
        }

        return (float) $this->config->get('commerce.delivery_gst_rate', 18.0);
    }
}
