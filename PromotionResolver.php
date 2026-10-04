<?php

declare(strict_types=1);

namespace App\Services\Promotions;

use App\Core\Exceptions\HttpException;
use App\Helpers\Money;
use App\Services\CouponService;
use App\Services\OfferService;
use App\Services\Pricing\PriceAdjustment;

/**
 * THE STACKING RULES. All of them. In one place.
 *
 * Discount logic becomes unmaintainable when "can X combine with Y" is answered
 * in five different files. So every combination decision lives here, and the
 * rules are short enough to state in full:
 *
 *   1. AT MOST ONE COUPON per order. Customer-entered, explicit.
 *
 *   2. AT MOST ONE AUTOMATIC OFFER. If several apply, the one worth most to the
 *      customer wins; ties break on the offer's `priority`.
 *
 *   3. COUPON + OFFER only if the offer says so. Each offer carries
 *      `stackable_with_coupon`. When it does not and both apply, the larger
 *      single discount wins and the customer is told which one and why. Silently
 *      dropping the coupon they typed is the worst possible outcome — they
 *      assume the site is broken.
 *
 *   4. WALLET CREDIT IS NOT A DISCOUNT and is not resolved here. It is a payment
 *      tender applied after the total is computed, so it never reduces the
 *      transaction value and never reduces GST. See CartService.
 *
 *   5. DELIVERY-SCOPED AND ORDER-SCOPED DISCOUNTS DO NOT COMPETE. A free-delivery
 *      coupon and a percentage-off offer are not alternatives, so both can stand
 *      even under rule 3 — they reduce different things.
 *
 * Changing these rules means changing this file and nothing else. That is the
 * point of it existing.
 */
final class PromotionResolver
{
    public function __construct(
        private readonly CouponService $coupons,
        private readonly OfferService $offers,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>> $cartLines Rows from vw_cart_lines
     * @param string|null                      $couponCode Applied coupon, if any
     */
    public function resolve(
        array $cartLines,
        ?int $userId,
        ?string $couponCode,
        Money $itemsSubtotal,
        Money $deliveryCharge,
    ): PromotionOutcome {
        if ($cartLines === []) {
            return PromotionOutcome::none();
        }

        $messages = [];
        $rejected = [];

        // --- Rule 1: resolve the coupon -----------------------------------
        $coupon = null;
        $couponAdjustment = null;

        if ($couponCode !== null && $couponCode !== '') {
            if ($userId === null) {
                // Guests cannot hold coupons: per-customer limits and
                // new-customer audiences are meaningless without an account.
                $rejected[] = [
                    'type' => 'coupon',
                    'code' => strtoupper($couponCode),
                    'reason' => 'Sign in to use a coupon code.',
                ];
            } else {
                try {
                    $result = $this->coupons->validateForCart(
                        $couponCode,
                        $userId,
                        $cartLines,
                        $itemsSubtotal,
                        $deliveryCharge
                    );

                    $coupon = $result['coupon'];
                    $couponAdjustment = $result['adjustment'];
                } catch (HttpException $exception) {
                    // A coupon that was valid when applied can stop being valid
                    // as the cart changes. Report it rather than failing the
                    // whole cart read.
                    $rejected[] = [
                        'type' => 'coupon',
                        'code' => strtoupper($couponCode),
                        'reason' => $exception->getMessage(),
                    ];
                    $messages[] = sprintf(
                        'Coupon %s no longer applies: %s',
                        strtoupper($couponCode),
                        $exception->getMessage()
                    );
                }
            }
        }

        // --- Rule 2: pick the single best automatic offer ------------------
        $candidates = $this->offers->applicableAutoDiscounts(
            $cartLines,
            $itemsSubtotal,
            $deliveryCharge
        );

        $bestOffer = null;
        $offerAdjustment = null;

        foreach ($candidates as $candidate) {
            if ($bestOffer === null) {
                $bestOffer = $candidate['offer'];
                $offerAdjustment = $candidate['adjustment'];

                continue;
            }

            $isBetter = $candidate['adjustment']->amount->greaterThan($offerAdjustment->amount)
                || ($candidate['adjustment']->amount->equals($offerAdjustment->amount)
                    && (int) $candidate['offer']['priority'] < (int) $bestOffer['priority']);

            if ($isBetter) {
                $bestOffer = $candidate['offer'];
                $offerAdjustment = $candidate['adjustment'];
            }
        }

        // --- Rule 3 and 5: decide whether both can stand -------------------
        if ($couponAdjustment !== null && $offerAdjustment !== null) {
            $competing = $couponAdjustment->scope === $offerAdjustment->scope;
            $stackable = (int) $bestOffer['stackable_with_coupon'] === 1
                && (int) $coupon['stackable_with_offer'] === 1;

            if ($competing && !$stackable) {
                if ($offerAdjustment->amount->greaterThan($couponAdjustment->amount)) {
                    $messages[] = sprintf(
                        '%s saves you %s, more than coupon %s (%s), so we applied the better one.',
                        $bestOffer['title'],
                        $offerAdjustment->amount->format(),
                        $coupon['code'],
                        $couponAdjustment->amount->format()
                    );
                    $rejected[] = [
                        'type' => 'coupon',
                        'code' => (string) $coupon['code'],
                        'reason' => sprintf(
                            'Not combinable with %s, which saves you more.',
                            $bestOffer['title']
                        ),
                    ];

                    $coupon = null;
                    $couponAdjustment = null;
                } else {
                    $messages[] = sprintf(
                        'Coupon %s saves you %s, so it was applied instead of %s.',
                        $coupon['code'],
                        $couponAdjustment->amount->format(),
                        $bestOffer['title']
                    );
                    $rejected[] = [
                        'type' => 'offer',
                        'code' => (string) $bestOffer['code'],
                        'reason' => sprintf('Not combinable with coupon %s.', $coupon['code']),
                    ];

                    $bestOffer = null;
                    $offerAdjustment = null;
                }
            }
        }

        // --- Assemble ------------------------------------------------------
        $adjustments = [];
        $total = Money::zero();

        if ($offerAdjustment !== null) {
            $adjustments[] = $offerAdjustment;
            $total = $total->add($offerAdjustment->amount);
            $messages[] = sprintf(
                '%s applied: %s off.',
                $bestOffer['title'],
                $offerAdjustment->amount->format()
            );
        }

        if ($couponAdjustment !== null) {
            $adjustments[] = $couponAdjustment;
            $total = $total->add($couponAdjustment->amount);
            $messages[] = sprintf(
                'Coupon %s applied: %s off.',
                $coupon['code'],
                $couponAdjustment->amount->format()
            );
        }

        return new PromotionOutcome(
            adjustments: $adjustments,
            messages: $messages,
            appliedCoupon: $coupon === null || $couponAdjustment === null ? null : [
                'code' => $coupon['code'],
                'title' => $coupon['title'],
                'discount_type' => $coupon['discount_type'],
                'discount_amount' => $couponAdjustment->amount->toDecimal(),
                'scope' => $couponAdjustment->scope,
            ],
            appliedOffer: $bestOffer === null || $offerAdjustment === null ? null : [
                'code' => $bestOffer['code'],
                'title' => $bestOffer['title'],
                'offer_type' => $bestOffer['offer_type'],
                'discount_type' => $bestOffer['discount_type'],
                'discount_amount' => $offerAdjustment->amount->toDecimal(),
                'scope' => $offerAdjustment->scope,
                'is_automatic' => true,
            ],
            rejected: $rejected,
            totalPromotionDiscount: $total,
        );
    }

    /**
     * Checks a coupon before it is stored on the cart, so a code that cannot be
     * used is refused at the point the customer types it rather than accepted
     * and then silently ignored.
     *
     * @param array<int, array<string, mixed>> $cartLines
     *
     * @return array<string, mixed> The validated coupon row
     */
    public function assertCouponApplicable(
        string $couponCode,
        int $userId,
        array $cartLines,
        Money $itemsSubtotal,
        Money $deliveryCharge,
    ): array {
        if ($cartLines === []) {
            throw new HttpException(
                'Add something to your cart before applying a coupon.',
                422,
                ['coupon_code' => ['Your cart is empty.']]
            );
        }

        $result = $this->coupons->validateForCart(
            $couponCode,
            $userId,
            $cartLines,
            $itemsSubtotal,
            $deliveryCharge
        );

        return $result['coupon'];
    }
}
