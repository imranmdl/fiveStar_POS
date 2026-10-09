<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Repositories\SettingRepository;

/**
 * The optional "offer for you" box printed on a till receipt.
 *
 * Read-only: it only looks up coupons and settings; nothing is reserved,
 * redeemed or changed. Coupons are redeemed online (fivestarspices.com) as
 * before. Chosen in this order:
 *
 *   1. the customer's own coupon — an active, in-date coupon whose audience is
 *      that specific registered customer, not used up (total or per-customer);
 *   2. the shop-wide coupon chosen in Admin → Shop details
 *      (receipt_offer_coupon_code) — same checks, audience "all";
 *   3. the free-text offer message (receipt_offer_text);
 *   4. nothing.
 *
 * The whole box is switched on/off with receipt_show_offer (on by default).
 */
final class ReceiptOfferService
{
    public function __construct(
        private readonly Database $db,
        private readonly SettingRepository $settings,
    ) {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function forCustomer(?int $customerId): ?array
    {
        if (!$this->settings->boolValue('receipt_show_offer', true)) {
            return null;
        }

        if ($customerId !== null) {
            $personal = $this->db->selectOne(
                $this->couponSql() . " AND c.`audience` = 'specific_customer' AND c.`specific_user_id` = :user_id
                   AND (SELECT COUNT(*) FROM `coupon_redemptions` r
                         WHERE r.`coupon_id` = c.`id` AND r.`user_id` = :user_id2 AND r.`status` = 'confirmed') < c.`per_customer_limit`
                 ORDER BY c.`valid_to` IS NULL, c.`valid_to` ASC, c.`id` DESC
                 LIMIT 1",
                ['user_id' => $customerId, 'user_id2' => $customerId]
            );

            if ($personal !== null) {
                return $this->present($personal, true);
            }
        }

        $code = strtoupper(trim((string) ($this->settings->value('receipt_offer_coupon_code') ?? '')));

        if ($code !== '') {
            $shopWide = $this->db->selectOne(
                $this->couponSql() . " AND c.`audience` = 'all' AND c.`code` = :code LIMIT 1",
                ['code' => $code]
            );

            if ($shopWide !== null) {
                return $this->present($shopWide, false);
            }
        }

        $text = trim((string) ($this->settings->value('receipt_offer_text') ?? ''));

        return $text !== '' ? ['kind' => 'message', 'personal' => false, 'message' => $text] : null;
    }

    /** Active, in-date coupons that still have uses left overall. */
    private function couponSql(): string
    {
        return "SELECT c.* FROM `coupons` c
                 WHERE c.`is_deleted` = 0 AND c.`is_active` = 1 AND c.`status` = 'active'
                   AND (c.`valid_from` IS NULL OR c.`valid_from` <= NOW())
                   AND (c.`valid_to` IS NULL OR c.`valid_to` >= NOW())
                   AND (c.`total_usage_limit` IS NULL OR c.`total_redeemed` < c.`total_usage_limit`)";
    }

    /**
     * @param array<string, mixed> $coupon
     *
     * @return array<string, mixed>
     */
    private function present(array $coupon, bool $personal): array
    {
        $value = (float) $coupon['discount_value'];
        $amount = static fn (float $v): string => '₹' . (floor($v) == $v ? number_format($v, 0) : number_format($v, 2));

        $headline = match ((string) $coupon['discount_type']) {
            'percentage' => rtrim(rtrim(number_format($value, 2), '0'), '.') . '% OFF'
                . ((float) ($coupon['max_discount_amount'] ?? 0) > 0 ? ' (up to ' . $amount((float) $coupon['max_discount_amount']) . ')' : ''),
            'flat' => $amount($value) . ' OFF',
            default => 'FREE DELIVERY',
        };

        return [
            'kind' => 'coupon',
            'personal' => $personal,
            'code' => (string) $coupon['code'],
            'title' => (string) ($coupon['title'] ?? ''),
            'headline' => $headline,
            'min_order_value' => (float) ($coupon['min_order_value'] ?? 0) > 0 ? (float) $coupon['min_order_value'] : null,
            'valid_to' => $coupon['valid_to'] !== null ? substr((string) $coupon['valid_to'], 0, 10) : null,
        ];
    }
}
