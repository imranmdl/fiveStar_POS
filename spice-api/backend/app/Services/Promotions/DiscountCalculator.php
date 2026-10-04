<?php

declare(strict_types=1);

namespace App\Services\Promotions;

use App\Helpers\Money;
use App\Services\Pricing\PriceAdjustment;

/**
 * Pure discount arithmetic, shared by coupons and offers.
 *
 * Kept free of the database so it can be unit-tested exhaustively — see
 * bin/test_promotions.php. Every rule that decides *how much* comes off lives
 * here; every rule that decides *whether* a promotion applies lives in
 * CouponService / OfferService.
 *
 * Three caps are applied, in this order, and all of them matter:
 *   1. The configured maximum discount, if any.
 *   2. The eligible subtotal — a discount can never exceed the value of the
 *      lines it applies to. Without this, a ₹500 flat coupon on a ₹300 cart
 *      would pay the customer to shop.
 *   3. For free-delivery promotions, the actual delivery charge, so a waiver on
 *      an already-free order is worth nothing rather than becoming credit.
 */
final class DiscountCalculator
{
    /**
     * @param string $discountType percentage|flat|free_delivery|none
     *
     * @return array{amount:Money, scope:string}
     */
    public static function compute(
        string $discountType,
        float $discountValue,
        ?Money $maxDiscount,
        Money $eligibleSubtotal,
        Money $deliveryCharge,
    ): array {
        return match ($discountType) {
            'percentage' => [
                'amount' => self::capped(
                    $eligibleSubtotal->percentage($discountValue),
                    $maxDiscount,
                    $eligibleSubtotal
                ),
                'scope' => PriceAdjustment::SCOPE_ORDER,
            ],
            'flat' => [
                'amount' => self::capped(
                    Money::fromDecimal($discountValue),
                    $maxDiscount,
                    $eligibleSubtotal
                ),
                'scope' => PriceAdjustment::SCOPE_ORDER,
            ],
            'free_delivery' => [
                // Worth exactly the charge being waived, never more.
                'amount' => self::capped($deliveryCharge, $maxDiscount, $deliveryCharge),
                'scope' => PriceAdjustment::SCOPE_DELIVERY,
            ],
            'none' => [
                'amount' => Money::zero(),
                'scope' => PriceAdjustment::SCOPE_ORDER,
            ],
            default => throw new \InvalidArgumentException("Unknown discount type: {$discountType}"),
        };
    }

    private static function capped(Money $amount, ?Money $maxDiscount, Money $ceiling): Money
    {
        if ($maxDiscount !== null && $maxDiscount->isPositive()) {
            $amount = $amount->min($maxDiscount);
        }

        return $amount->min($ceiling)->clampAtZero();
    }

    /**
     * Human-readable summary of a discount rule, for coupon lists and terms.
     */
    public static function describe(
        string $discountType,
        float $discountValue,
        ?Money $maxDiscount,
        ?int $buyQuantity = null,
        ?int $getQuantity = null,
    ): string {
        // Buy-X-get-Y is described from its quantities, not from a percentage.
        // The value it is worth depends on the basket, so there is no single
        // figure to quote here — only the rule.
        if ($discountType === 'free_items') {
            if ($buyQuantity === null || $getQuantity === null) {
                return 'Buy more, get free items';
            }

            return $buyQuantity === 1 && $getQuantity === 1
                ? 'Buy one get one free'
                : sprintf('Buy %d get %d free', $buyQuantity, $getQuantity);
        }

        return match ($discountType) {
            'percentage' => $maxDiscount === null
                ? sprintf('%s%% off', self::trimNumber($discountValue))
                : sprintf('%s%% off up to %s', self::trimNumber($discountValue), $maxDiscount->format()),
            'flat' => sprintf('%s off', Money::fromDecimal($discountValue)->format()),
            'free_delivery' => 'Free delivery',
            'none' => 'No automatic discount',
            default => throw new \InvalidArgumentException("Unknown discount type: {$discountType}"),
        };
    }

    private static function trimNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }
}
