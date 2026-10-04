<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Helpers\Money;
use App\Helpers\Uuid;
use App\Repositories\CourierRepository;
use App\Repositories\OrderRepository;
use App\Repositories\SettingRepository;
use App\Services\Delivery\CourierAdapterInterface;
use App\Services\Delivery\CourierQuote;
use App\Services\Delivery\CourierSelector;
use App\Services\Delivery\ParcelSpec;

/**
 * BR-007 in practice: works out what the parcel is, asks every courier what it
 * would charge, and records why one was chosen.
 *
 * Split from CourierSelector deliberately. The selector is pure arithmetic that
 * can be tested exhaustively; this class is the messy part — database lookups,
 * live rate calls that may time out, default box sizes for unmeasured products.
 * Keeping them apart is what lets the scoring rules be verified without a
 * database and the plumbing be changed without touching the rules.
 */
final class CourierRoutingService
{
    public function __construct(
        private readonly CourierRepository $couriers,
        private readonly OrderRepository $orders,
        private readonly DeliveryChargeService $delivery,
        private readonly CourierSelector $selector,
        private readonly CourierAdapterInterface $adapter,
        private readonly SettingRepository $settings,
        private readonly Database $db,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Measures the parcel for an order.
     *
     * Volumetric weight is computed per courier because divisors differ, so the
     * value here uses the platform default and each quote recomputes it. The
     * dimensions are a crude sum: items stacked along one axis, the other two
     * taken as the largest single item. That over-estimates a well-packed box
     * and under-estimates an awkward one, which is the right direction to err —
     * a courier re-weighing a parcel and billing more is worse than quoting a
     * little high.
     */
    public function buildParcel(int $orderId): ParcelSpec
    {
        $order = (array) $this->orders->findById($orderId);
        $items = $this->orders->itemsFor($orderId);

        $defaultLength = $this->settings->intValue('default_box_length_mm', 250);
        $defaultWidth = $this->settings->intValue('default_box_width_mm', 200);
        $defaultHeight = $this->settings->intValue('default_box_height_mm', 120);
        $packagingWeight = $this->settings->intValue('packaging_weight_grams', 80);

        $totalWeight = $packagingWeight;
        $stackedHeight = 0;
        $maxLength = 0;
        $maxWidth = 0;
        $usedDefaults = false;
        $isFragile = false;

        foreach ($items as $item) {
            $variant = $this->db->selectOne(
                'SELECT `pack_length_mm`, `pack_width_mm`, `pack_height_mm`, `is_fragile`, `weight_grams`
                   FROM `product_variants` WHERE `id` = :id LIMIT 1',
                ['id' => (int) $item['variant_id']]
            );

            $quantity = (int) $item['quantity'];
            $totalWeight += (int) $item['line_weight_grams'];

            if ($variant === null
                || $variant['pack_length_mm'] === null
                || $variant['pack_width_mm'] === null
                || $variant['pack_height_mm'] === null) {
                // No measurements recorded. Assume the default box and flag it,
                // so the gap shows up in reporting instead of silently producing
                // optimistic quotes forever.
                $usedDefaults = true;
                $maxLength = max($maxLength, $defaultLength);
                $maxWidth = max($maxWidth, $defaultWidth);
                $stackedHeight += $defaultHeight * $quantity;

                continue;
            }

            $maxLength = max($maxLength, (int) $variant['pack_length_mm']);
            $maxWidth = max($maxWidth, (int) $variant['pack_width_mm']);
            $stackedHeight += (int) $variant['pack_height_mm'] * $quantity;
            $isFragile = $isFragile || (int) $variant['is_fragile'] === 1;
        }

        $maxLength = max($maxLength, $defaultLength);
        $maxWidth = max($maxWidth, $defaultWidth);
        $stackedHeight = max($stackedHeight, 20);

        // Reuses the zone the pricing engine already resolved for this pincode,
        // so a courier rate card and a customer delivery charge can never
        // disagree about which zone an address is in.
        $serviceability = $this->delivery->checkServiceability((string) $order['ship_pincode']);
        $divisor = 5000;

        return new ParcelSpec(
            actualWeightGrams: max(1, $totalWeight),
            volumetricWeightGrams: ParcelSpec::volumetricGrams($maxLength, $maxWidth, $stackedHeight, $divisor),
            lengthMm: $maxLength,
            widthMm: $maxWidth,
            heightMm: $stackedHeight,
            declaredValue: Money::fromDecimal((string) $order['grand_total']),
            destinationPincode: (string) $order['ship_pincode'],
            zoneCode: (string) ($serviceability['zone_code'] ?? 'REST'),
            isFragile: $isFragile,
            usedDefaultDimensions: $usedDefaults,
        );
    }

    /**
     * Gathers a quote from every courier.
     *
     * Ineligible couriers still produce a quote object carrying their reasons,
     * because BR-007 requires the decision to be auditable and "nothing else was
     * available" is only credible if the alternatives are listed.
     *
     * @return array<int, CourierQuote>
     */
    public function gatherQuotes(ParcelSpec $parcel): array
    {
        $quotes = [];

        foreach ($this->couriers->all() as $courier) {
            $reasons = $this->selector->ineligibilityReasons($courier, $parcel);

            $serviceability = $this->couriers->serviceabilityFor(
                (int) $courier['id'],
                $parcel->destinationPincode
            );

            if ($serviceability === null) {
                $reasons[] = 'does not serve pincode ' . $parcel->destinationPincode;
            } elseif ((int) $serviceability['is_serviceable'] !== 1) {
                $reasons[] = 'excludes pincode ' . $parcel->destinationPincode
                    . ($serviceability['notes'] !== null ? ' (' . $serviceability['notes'] . ')' : '');
            }

            // Volumetric weight recomputed with this courier's divisor: the same
            // parcel is 25% heavier to a courier using 4000 instead of 5000.
            $courierVolumetric = ParcelSpec::volumetricGrams(
                $parcel->lengthMm,
                $parcel->widthMm,
                $parcel->heightMm,
                (int) $courier['volumetric_divisor']
            );
            $chargeableWeight = max($parcel->actualWeightGrams, $courierVolumetric);

            if ($chargeableWeight > (int) $courier['max_weight_grams']
                && !in_array(
                    sprintf('parcel is %dg, above their %dg ceiling', $parcel->chargeableWeightGrams(), (int) $courier['max_weight_grams']),
                    $reasons,
                    true
                )) {
                $reasons[] = sprintf(
                    'volumetric weight %dg (their divisor %d) exceeds their %dg ceiling',
                    $chargeableWeight,
                    (int) $courier['volumetric_divisor'],
                    (int) $courier['max_weight_grams']
                );
            }

            if ($reasons !== []) {
                $quotes[] = CourierQuote::ineligible(
                    (int) $courier['id'],
                    (string) $courier['code'],
                    (string) $courier['name'],
                    $reasons
                );

                continue;
            }

            $quote = $this->quoteFor($courier, $parcel, $chargeableWeight, $serviceability);

            if ($quote === null) {
                $quotes[] = CourierQuote::ineligible(
                    (int) $courier['id'],
                    (string) $courier['code'],
                    (string) $courier['name'],
                    [sprintf('no rate card for zone %s at %dg', $parcel->zoneCode, $chargeableWeight)]
                );

                continue;
            }

            $quotes[] = $quote;
        }

        return $quotes;
    }

    /**
     * Chooses a courier for an order and records the decision.
     *
     * @return array<string, mixed>
     */
    public function selectForOrder(int $orderId, ?string $strategy = null, ?int $actorId = null): array
    {
        $parcel = $this->buildParcel($orderId);
        $quotes = $this->gatherQuotes($parcel);
        $strategy ??= (string) ($this->settings->value('courier_selection_strategy') ?? CourierSelector::STRATEGY_BALANCED);

        $outcome = $this->selector->select($quotes, $strategy);
        $order = (array) $this->orders->findById($orderId);

        $selectionId = $this->db->insert(
            'INSERT INTO `courier_selections`
                 (`uuid`, `order_id`, `selected_courier_id`, `strategy`, `destination_pincode`,
                  `chargeable_weight_grams`, `order_value`, `candidates_considered`,
                  `candidates_eligible`, `winning_score`, `reason`, `candidates`,
                  `created_by`, `created_date`, `is_active`, `is_deleted`, `version`)
             VALUES
                 (:uuid, :order_id, :courier_id, :strategy, :pincode, :weight, :value,
                  :considered, :eligible, :score, :reason, :candidates,
                  :created_by, NOW(), 1, 0, 1)',
            [
                'uuid' => Uuid::v4(),
                'order_id' => $orderId,
                'courier_id' => $outcome['selected']?->courierId,
                'strategy' => $outcome['strategy'],
                'pincode' => $parcel->destinationPincode,
                'weight' => $parcel->chargeableWeightGrams(),
                'value' => $parcel->declaredValue->toDecimal(),
                'considered' => $outcome['considered'],
                'eligible' => $outcome['eligible'],
                'score' => $outcome['score'],
                'reason' => substr($outcome['reason'], 0, 500),
                'candidates' => json_encode($outcome['candidates']),
                'created_by' => $actorId,
            ]
        );

        $this->logger->info('Courier selected', [
            'order_number' => $order['order_number'] ?? null,
            'courier' => $outcome['selected']?->courierCode,
            'strategy' => $outcome['strategy'],
            'eligible' => $outcome['eligible'],
            'reason' => $outcome['reason'],
        ], 'delivery');

        return $outcome + ['parcel' => $parcel, 'selection_id' => $selectionId];
    }

    /**
     * What a customer or staff member would be quoted, without committing.
     *
     * @return array<string, mixed>
     */
    public function previewForOrder(int $orderId, ?string $strategy = null): array
    {
        $parcel = $this->buildParcel($orderId);
        $quotes = $this->gatherQuotes($parcel);
        $strategy ??= (string) ($this->settings->value('courier_selection_strategy') ?? CourierSelector::STRATEGY_BALANCED);

        $outcome = $this->selector->select($quotes, $strategy);

        return [
            'parcel' => $parcel->toArray(),
            'strategy' => $outcome['strategy'],
            'selected' => $outcome['selected']?->toArray(),
            'reason' => $outcome['reason'],
            'considered' => $outcome['considered'],
            'eligible' => $outcome['eligible'],
            'candidates' => $outcome['candidates'],
        ];
    }

    /**
     * @param array<string, mixed>      $courier
     * @param array<string, mixed>|null $serviceability
     */
    private function quoteFor(
        array $courier,
        ParcelSpec $parcel,
        int $chargeableWeight,
        ?array $serviceability,
    ): ?CourierQuote {
        // A live rate is preferred when the adapter can give one, because a
        // negotiated card drifts from reality between contract renewals.
        if ((string) $courier['adapter'] === $this->adapter->name()) {
            $live = $this->adapter->quote($courier, $parcel);

            if ($live !== null) {
                return $live;
            }
        }

        $slab = $this->couriers->rateSlabFor((int) $courier['id'], $parcel->zoneCode, $chargeableWeight);

        if ($slab === null) {
            return null;
        }

        $cost = Money::fromDecimal((string) $slab['base_charge']);

        $extraGrams = max(0, $chargeableWeight - (int) $slab['min_weight_grams']);

        if ($extraGrams > 0 && (float) $slab['per_kg_charge'] > 0) {
            // Couriers round part-kilos up, so 1.2kg is billed as 2kg.
            $extraKg = (int) ceil($extraGrams / 1000);
            $cost = $cost->add(Money::fromDecimal((string) $slab['per_kg_charge'])->multiply($extraKg));
        }

        if ((float) $slab['fuel_surcharge_pct'] > 0) {
            $cost = $cost->add($cost->percentage((float) $slab['fuel_surcharge_pct']));
        }

        $cost = $cost->add(Money::fromDecimal((string) $slab['handling_charge']));

        return new CourierQuote(
            courierId: (int) $courier['id'],
            courierCode: (string) $courier['code'],
            courierName: (string) $courier['name'],
            cost: $cost,
            slaMinDays: (int) ($serviceability['sla_min_days'] ?? 3),
            slaMaxDays: (int) ($serviceability['sla_max_days'] ?? 7),
            reliabilityScore: (float) $courier['reliability_score'],
            priority: (int) $courier['priority'],
            isEligible: true,
            isExpress: (int) ($serviceability['is_express'] ?? 0) === 1,
            costFromRateCard: true,
        );
    }
}
