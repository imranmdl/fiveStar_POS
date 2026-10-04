<?php

declare(strict_types=1);

namespace App\Services\Promotions;

use App\Helpers\Money;
use App\Services\Pricing\PriceAdjustment;

/**
 * What the resolver decided, and why.
 *
 * The `messages` are as important as the numbers. A customer who types a coupon
 * and sees no change assumes the site is broken; a customer told "SPICE50 saves
 * ₹50 but Dry Fruit Week saves ₹94, so we kept the better one" understands
 * exactly what happened.
 */
final class PromotionOutcome
{
    /**
     * @param array<int, PriceAdjustment> $adjustments
     * @param array<int, string>          $messages
     * @param array<string, mixed>|null   $appliedCoupon
     * @param array<string, mixed>|null   $appliedOffer
     * @param array<int, array<string, mixed>> $rejected
     */
    public function __construct(
        public readonly array $adjustments,
        public readonly array $messages,
        public readonly ?array $appliedCoupon,
        public readonly ?array $appliedOffer,
        public readonly array $rejected,
        public readonly Money $totalPromotionDiscount,
    ) {
    }

    public static function none(): self
    {
        return new self([], [], null, null, [], Money::zero());
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'applied_coupon' => $this->appliedCoupon,
            'applied_offer' => $this->appliedOffer,
            'rejected' => $this->rejected,
            'messages' => $this->messages,
            'total_promotion_discount' => $this->totalPromotionDiscount->toDecimal(),
        ];
    }
}
