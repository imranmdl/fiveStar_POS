<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Repositories\PriceChangeLogRepository;
use App\Repositories\PricingRuleRepository;
use App\Repositories\ProductRepository;
use App\Repositories\ProductVariantRepository;

/**
 * All pricing math lives here, the same way all stock math lives in
 * InventoryService. This is the only place allowed to write to
 * product_variants.selling_price as a side effect of a purchase — and even
 * here, only when a human decided to, or an explicitly configured
 * 'auto_apply' rule says so. Never as an automatic consequence of recording
 * inventory: PurchaseOrderService and InventoryService remain exactly as
 * Phase 1/2 left them, unaware this class exists except for one small,
 * clearly-marked call site.
 */
final class PricingService
{
    public function __construct(
        private readonly PricingRuleRepository $rules,
        private readonly PriceChangeLogRepository $log,
        private readonly ProductVariantRepository $variants,
        private readonly ProductRepository $products,
        private readonly AuditService $audit,
        private readonly Database $db,
    ) {
    }

    /** @return array<string, mixed>|null */
    public function resolveRule(int $variantId): ?array
    {
        $variant = $this->variants->findById($variantId);
        $product = $variant === null ? null : $this->products->findById((int) $variant['product_id']);

        if ($product === null) {
            return null;
        }

        return $this->rules->resolveForVariant($variantId, (int) $product['id'], (int) $product['category_id']);
    }

    /**
     * The "real-time online pricing" view: every pack size next to what it
     * actually costs right now (the average of the last purchase inward cost
     * per warehouse), what its rule says the price should be at that cost,
     * and what the shop currently charges — so a stale price left over from
     * before a purchase or a rule change is visible immediately, not
     * discovered later at a loss. Nothing here is written; it is the same
     * read computePurchaseImpact() does per line, laid out for the whole
     * catalogue instead of one purchase order.
     *
     * @return array<int, array<string, mixed>>
     */
    public function liveSnapshot(): array
    {
        $rows = $this->db->select(
            "SELECT v.`id`, v.`uuid`, v.`sku`, v.`variant_name`, v.`selling_price`, v.`mrp`,
                    p.`name` AS `product_name`, p.`status` AS `product_status`, p.`gst_rate`,
                    p.`id` AS `product_id`, p.`category_id`,
                    (SELECT AVG(s.`average_cost`) FROM `inventory_stock` s
                      WHERE s.`product_variant_id` = v.`id` AND s.`average_cost` IS NOT NULL) AS `average_cost`,
                    (SELECT MAX(poi.`unit_cost`)
                       FROM `purchase_order_items` poi
                       INNER JOIN `purchase_orders` po ON po.`id` = poi.`purchase_order_id`
                      WHERE poi.`product_variant_id` = v.`id` AND poi.`is_deleted` = 0
                      ORDER BY po.`purchase_date` DESC, poi.`id` DESC LIMIT 1) AS `last_purchase_cost`
               FROM `product_variants` v
               INNER JOIN `products` p ON p.`id` = v.`product_id`
              WHERE v.`is_deleted` = 0
              ORDER BY p.`name`, v.`weight_grams`"
        );

        return array_map(function (array $row): array {
            $rule = $this->rules->resolveForVariant((int) $row['id'], (int) $row['product_id'], (int) $row['category_id']);
            $averageCost = $row['average_cost'] !== null ? (float) $row['average_cost'] : null;
            $lastCost = $row['last_purchase_cost'] !== null ? (float) $row['last_purchase_cost'] : null;
            $currentPrice = (float) $row['selling_price'];

            $suggested = ($rule !== null && $averageCost !== null)
                ? $this->computeSuggestedPrice($rule, $averageCost, (float) $row['gst_rate'])
                : null;

            return [
                'variant_uuid' => $row['uuid'],
                'sku' => $row['sku'],
                'variant_name' => $row['variant_name'],
                'product_name' => $row['product_name'],
                'product_status' => $row['product_status'],
                'average_cost' => $averageCost,
                'last_purchase_cost' => $lastCost,
                'current_price' => $currentPrice,
                'mrp' => (float) $row['mrp'],
                'rule_name' => $rule['name'] ?? null,
                'rule_summary' => $rule === null ? null : sprintf(
                    '%s %s%% (%s)',
                    $rule['calculation'] === 'markup_percent' ? 'Markup' : 'Margin',
                    rtrim(rtrim(number_format((float) $rule['rate'], 2), '0'), '.'),
                    $rule['tax_mode']
                ),
                'suggested_price' => $suggested,
                // What the rule computes has drifted from what the shop actually
                // charges — a purchase came in (or the rule changed) since the
                // price was last touched, and 'never'/'ask' modes leave that gap
                // open until someone acts on it.
                'is_out_of_sync' => $suggested !== null && abs($suggested - $currentPrice) > 0.005,
            ];
        }, $rows);
    }

    /**
     * markup_percent: cost * (1 + rate/100).
     * margin_percent: cost / (1 - rate/100) — margin is a percentage of the
     * SELLING price, the standard retail definition; that is what distinguishes
     * it from markup, which is a percentage of cost.
     *
     * tax_mode='exclusive' treats $costBasis as pre-tax and adds GST on top to
     * reach the inclusive customer price — product_variants.selling_price and
     * .mrp are always stored GST-inclusive (see Money.php's documented Indian
     * MRP convention). tax_mode='inclusive' applies the rate directly.
     */
    public function computeSuggestedPrice(array $rule, float $costBasis, float $gstRate): float
    {
        $net = $rule['calculation'] === 'markup_percent'
            ? $costBasis * (1 + ((float) $rule['rate']) / 100)
            : $costBasis / (1 - ((float) $rule['rate']) / 100);

        $price = $rule['tax_mode'] === 'exclusive'
            ? $net * (1 + $gstRate / 100)
            : $net;

        return round($price, 2, PHP_ROUND_HALF_UP);
    }

    /**
     * Called once per purchase-order line by PurchaseOrderService, only when
     * the configured mode isn't 'never'. Resolves the applicable rule (if
     * any) and reports whether that mode calls for a prompt — it never
     * writes anything itself.
     *
     * @return array{should_prompt:bool, rule_id:?int, current_price:float, purchase_price:float,
     *               average_cost:float, suggested_use_average:?float, suggested_use_new:?float}
     */
    public function evaluatePurchaseImpact(
        int $variantId,
        float $purchasePrice,
        float $averageCost,
        float $currentSellingPrice,
        string $mode,
    ): array {
        $variant = $this->variants->findById($variantId);
        $product = $variant === null ? null : $this->products->findById((int) $variant['product_id']);
        $rule = $product === null ? null : $this->rules->resolveForVariant(
            $variantId,
            (int) $product['id'],
            (int) $product['category_id']
        );

        $result = [
            'should_prompt' => false,
            'rule_id' => $rule === null ? null : (int) $rule['id'],
            'current_price' => $currentSellingPrice,
            'purchase_price' => $purchasePrice,
            'average_cost' => $averageCost,
            'suggested_use_average' => null,
            'suggested_use_new' => null,
        ];

        if ($rule === null || $product === null) {
            return $result;
        }

        $gstRate = (float) $product['gst_rate'];
        $suggestedAverage = $this->computeSuggestedPrice($rule, $averageCost, $gstRate);
        $suggestedNew = $this->computeSuggestedPrice($rule, $purchasePrice, $gstRate);

        $result['suggested_use_average'] = $suggestedAverage;
        $result['suggested_use_new'] = $suggestedNew;

        $result['should_prompt'] = match ($mode) {
            'always_ask' => abs($suggestedAverage - $currentSellingPrice) > 0.005
                || abs($suggestedNew - $currentSellingPrice) > 0.005,
            'ask_on_increase' => $suggestedAverage > $currentSellingPrice + 0.005,
            'ask_on_decrease' => $suggestedAverage < $currentSellingPrice - 0.005,
            // 'auto_apply' never prompts (the caller applies immediately instead)
            // and 'never' is short-circuited by the caller before this runs.
            default => false,
        };

        return $result;
    }

    /**
     * Applies one of the brief's four decisions (manual / use_average /
     * use_new / keep_old) — or the system's 'auto_apply' — and logs it,
     * whatever the outcome. 'keep_old' changes nothing but is still recorded,
     * per the brief's explicit requirement that the decision itself is
     * audited, not just the price.
     *
     * 'auto_apply' is the one decision this never throws for when nothing can
     * be computed (no rule resolves): it is a side effect of recording a
     * purchase, and a missing pricing rule must not fail that transaction.
     * Every other decision throws a clear 422 instead.
     *
     * @return array<string, mixed>
     */
    public function applyDecision(
        int $variantId,
        string $decision,
        ?float $manualPrice,
        ?float $purchasePrice,
        ?float $averageCost,
        string $referenceType,
        ?int $referenceId,
        ?string $reason,
        ?int $performedBy,
        ?Request $request = null,
    ): array {
        $variant = $this->variants->findById($variantId);

        if ($variant === null) {
            throw new NotFoundException('That pack size does not exist.');
        }

        $product = $this->products->findById((int) $variant['product_id']);

        if ($product === null) {
            throw new NotFoundException('That product no longer exists.');
        }

        $oldPrice = (float) $variant['selling_price'];
        $mrp = (float) $variant['mrp'];
        $ruleId = null;
        $newPrice = $oldPrice;

        switch ($decision) {
            case 'manual':
                if ($manualPrice === null) {
                    throw new HttpException('A manual price is required for this decision.', 422, [
                        'manual_price' => ['Enter the new selling price.'],
                    ]);
                }

                $newPrice = round($manualPrice, 2, PHP_ROUND_HALF_UP);
                break;

            case 'use_average':
            case 'use_new':
                $costBasis = $decision === 'use_average' ? $averageCost : $purchasePrice;

                if ($costBasis === null) {
                    throw new HttpException(
                        $decision === 'use_average'
                            ? 'No average cost is available for this pack size yet.'
                            : 'No purchase price is available for this decision.',
                        422
                    );
                }

                $rule = $this->rules->resolveForVariant($variantId, (int) $product['id'], (int) $product['category_id']);

                if ($rule === null) {
                    throw new HttpException(
                        'No pricing rule is configured for this pack size, its product, category or globally. '
                            . 'Set one up on the Pricing screen, or choose "Manually set price" instead.',
                        422
                    );
                }

                $ruleId = (int) $rule['id'];
                $newPrice = $this->computeSuggestedPrice($rule, $costBasis, (float) $product['gst_rate']);
                break;

            case 'keep_old':
                $newPrice = $oldPrice;
                break;

            case 'auto_apply':
                if ($averageCost === null) {
                    return $this->unchangedResult($oldPrice, $decision);
                }

                $rule = $this->rules->resolveForVariant($variantId, (int) $product['id'], (int) $product['category_id']);

                if ($rule === null) {
                    // A side effect of recording a purchase: a missing rule
                    // must not fail that transaction, so this quietly does
                    // nothing rather than throwing.
                    return $this->unchangedResult($oldPrice, $decision);
                }

                $ruleId = (int) $rule['id'];
                $newPrice = $this->computeSuggestedPrice($rule, $averageCost, (float) $product['gst_rate']);
                break;

            default:
                throw new HttpException('Unknown pricing decision: ' . $decision, 422);
        }

        if ($newPrice <= 0) {
            throw new HttpException('The resulting price must be greater than zero.', 422);
        }

        if ($newPrice > $mrp) {
            throw new HttpException(
                sprintf(
                    'The resulting price (%.2f) would exceed the MRP (%.2f). Raise the MRP first, or choose a different decision.',
                    $newPrice,
                    $mrp
                ),
                422
            );
        }

        // Mirrors chk_variants_offer_below_selling: a live offer price must
        // stay below the selling price. Checked here so the failure is a
        // clear message, not a raw database constraint error.
        if ($variant['offer_price'] !== null && $newPrice <= (float) $variant['offer_price']) {
            throw new HttpException(
                sprintf(
                    'The resulting price (%.2f) would not be above the active offer price (%.2f). '
                        . 'End the offer first, or choose a different decision.',
                    $newPrice,
                    (float) $variant['offer_price']
                ),
                422
            );
        }

        $changed = abs($newPrice - $oldPrice) > 0.001;

        if ($changed) {
            $this->variants->update(
                $variantId,
                ['selling_price' => number_format($newPrice, 2, '.', '')],
                $performedBy
            );
        }

        $logId = $this->log->create([
            'product_variant_id' => $variantId,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'old_selling_price' => number_format($oldPrice, 2, '.', ''),
            'new_selling_price' => number_format($newPrice, 2, '.', ''),
            'purchase_price' => $purchasePrice === null ? null : number_format($purchasePrice, 4, '.', ''),
            'average_cost' => $averageCost === null ? null : number_format($averageCost, 4, '.', ''),
            'decision' => $decision,
            'pricing_rule_id' => $ruleId,
            'reason' => $reason,
            'performed_by' => $performedBy,
        ], $performedBy);

        $this->audit->log(
            entityName: 'product_variants',
            entityId: $variantId,
            action: 'price_change',
            oldValues: ['selling_price' => $oldPrice],
            newValues: ['selling_price' => $newPrice, 'decision' => $decision],
            request: $request,
            entityUuid: (string) $variant['uuid'],
            notes: $reason,
        );

        return [
            'changed' => $changed,
            'old_selling_price' => $oldPrice,
            'new_selling_price' => $newPrice,
            'decision' => $decision,
            'log_id' => $logId,
        ];
    }

    /** @return array<string, mixed> */
    private function unchangedResult(float $price, string $decision): array
    {
        return [
            'changed' => false,
            'old_selling_price' => $price,
            'new_selling_price' => $price,
            'decision' => $decision,
            'log_id' => null,
        ];
    }
}
