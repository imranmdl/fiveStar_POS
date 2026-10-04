<?php

declare(strict_types=1);

namespace App\Services\Pricing;

use App\Helpers\Money;

/**
 * An order-level or delivery-level money movement that is not a line price:
 * a coupon, a referral credit, a wallet redemption, a handling surcharge.
 *
 * Phase 3 builds no adjustments of its own — product offers are already baked
 * into the unit price by the catalog's pricing view. The type exists now so
 * Phase 4 coupons and Phase 5 wallet redemption plug into a calculation that
 * already knows how to apportion them across lines for correct GST.
 */
final class PriceAdjustment
{
    public const SCOPE_ORDER = 'order';
    public const SCOPE_DELIVERY = 'delivery';

    public const TYPE_DISCOUNT = 'discount';
    public const TYPE_SURCHARGE = 'surcharge';

    public function __construct(
        public readonly string $code,
        public readonly string $label,
        public readonly Money $amount,
        public readonly string $type = self::TYPE_DISCOUNT,
        public readonly string $scope = self::SCOPE_ORDER,
    ) {
        if ($amount->isNegative()) {
            throw new \InvalidArgumentException(
                'Adjustment amounts are always positive; direction is set by $type.'
            );
        }

        if (!in_array($type, [self::TYPE_DISCOUNT, self::TYPE_SURCHARGE], true)) {
            throw new \InvalidArgumentException("Unknown adjustment type: {$type}");
        }

        if (!in_array($scope, [self::SCOPE_ORDER, self::SCOPE_DELIVERY], true)) {
            throw new \InvalidArgumentException("Unknown adjustment scope: {$scope}");
        }
    }

    public function isDiscount(): bool
    {
        return $this->type === self::TYPE_DISCOUNT;
    }

    /** Signed contribution to the grand total. */
    public function signedAmount(): Money
    {
        return $this->isDiscount()
            ? Money::zero()->subtract($this->amount)
            : $this->amount;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'label' => $this->label,
            'type' => $this->type,
            'scope' => $this->scope,
            'amount' => $this->amount->toDecimal(),
        ];
    }
}
