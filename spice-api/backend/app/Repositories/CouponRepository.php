<?php

declare(strict_types=1);

namespace App\Repositories;

final class CouponRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'coupons';
    }

    protected function fillable(): array
    {
        return [
            'code', 'title', 'description', 'terms', 'discount_type', 'discount_value',
            'max_discount_amount', 'min_order_value', 'applies_to', 'audience',
            'specific_user_id', 'valid_from', 'valid_to', 'total_usage_limit',
            'per_customer_limit', 'stackable_with_offer', 'status',
        ];
    }

    protected function sortable(): array
    {
        return ['id', 'code', 'title', 'status', 'valid_to', 'total_redeemed', 'created_date'];
    }

    /** @return array<string, mixed>|null */
    public function findByCode(string $code): ?array
    {
        return $this->findOneBy('code', strtoupper(trim($code)));
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        return $this->existsWhere('code', strtoupper(trim($code)), $exceptId);
    }

    /**
     * Coupons a customer could plausibly use right now. Eligibility that needs
     * cart context (minimum value, category scope) is checked in CouponService;
     * this narrows the list to what is worth evaluating.
     *
     * @return array<int, array<string, mixed>>
     */
    public function availableForUser(int $userId, bool $isNewCustomer): array
    {
        return $this->db->select(
            "SELECT c.* FROM `coupons` c
              WHERE c.`status` = 'active'
                AND c.`is_deleted` = 0 AND c.`is_active` = 1
                AND (c.`valid_from` IS NULL OR c.`valid_from` <= NOW())
                AND (c.`valid_to`   IS NULL OR c.`valid_to`   >= NOW())
                AND (c.`total_usage_limit` IS NULL OR c.`total_redeemed` < c.`total_usage_limit`)
                AND (
                        c.`audience` = 'all'
                     OR (c.`audience` = 'new_customers'     AND :is_new = 1)
                     OR (c.`audience` = 'specific_customer' AND c.`specific_user_id` = :user_id)
                    )
                AND (
                        SELECT COUNT(*) FROM `coupon_redemptions` r
                         WHERE r.`coupon_id` = c.`id`
                           AND r.`user_id` = :user_id_usage
                           AND r.`status` = 'confirmed'
                           AND r.`is_deleted` = 0
                    ) < c.`per_customer_limit`
              ORDER BY c.`discount_type`, c.`discount_value` DESC",
            ['is_new' => $isNewCustomer ? 1 : 0, 'user_id' => $userId, 'user_id_usage' => $userId]
        );
    }

    /**
     * @return array<int, array<string, mixed>> Rows of {target_type, category_id, product_id}
     */
    public function targetsFor(int $couponId): array
    {
        return $this->db->select(
            'SELECT `target_type`, `category_id`, `product_id`
               FROM `coupon_targets`
              WHERE `coupon_id` = :coupon_id AND `is_deleted` = 0 AND `is_active` = 1',
            ['coupon_id' => $couponId]
        );
    }

    public function countConfirmedRedemptionsByUser(int $couponId, int $userId): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM `coupon_redemptions`
              WHERE `coupon_id` = :coupon_id AND `user_id` = :user_id
                AND `status` = 'confirmed' AND `is_deleted` = 0",
            ['coupon_id' => $couponId, 'user_id' => $userId]
        );
    }

    /**
     * Atomically claims one use of the coupon.
     *
     * The limit check and the increment are a single statement, so two customers
     * redeeming the last available use cannot both succeed. Checking with a
     * SELECT and then incrementing would let exactly that happen under load.
     */
    public function claimUsage(int $couponId): bool
    {
        return $this->db->execute(
            'UPDATE `coupons`
                SET `total_redeemed` = `total_redeemed` + 1,
                    `updated_date` = NOW(), `version` = `version` + 1
              WHERE `id` = :id
                AND `is_deleted` = 0
                AND (`total_usage_limit` IS NULL OR `total_redeemed` < `total_usage_limit`)',
            ['id' => $couponId]
        ) === 1;
    }

    /** Returns a claimed use when an order is cancelled before fulfilment. */
    public function releaseUsage(int $couponId): void
    {
        $this->db->execute(
            'UPDATE `coupons`
                SET `total_redeemed` = GREATEST(`total_redeemed` - 1, 0),
                    `updated_date` = NOW(), `version` = `version` + 1
              WHERE `id` = :id AND `is_deleted` = 0',
            ['id' => $couponId]
        );
    }

    /**
     * Housekeeping for the Phase 9 scheduler: mark passed windows as expired so
     * the admin list is honest without anyone editing rows by hand.
     */
    public function expirePassedCoupons(): int
    {
        return $this->db->execute(
            "UPDATE `coupons`
                SET `status` = 'expired', `updated_date` = NOW(), `version` = `version` + 1
              WHERE `status` = 'active' AND `is_deleted` = 0
                AND `valid_to` IS NOT NULL AND `valid_to` < NOW()"
        );
    }

    /**
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string, search:?string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function paginateForAdmin(array $params, ?string $status = null): array
    {
        $where = ['c.`is_deleted` = 0'];
        $bindings = [];

        if ($status !== null) {
            $where[] = 'c.`status` = :status';
            $bindings['status'] = $status;
        }

        if ($params['search'] !== null) {
            $where[] = '(c.`code` LIKE :search_code OR c.`title` LIKE :search_title)';
            $bindings['search_code'] = '%' . $params['search'] . '%';
            $bindings['search_title'] = '%' . $params['search'] . '%';
        }

        $whereSql = implode(' AND ', $where);
        $sort = in_array($params['sort'], $this->sortable(), true) ? $params['sort'] : 'created_date';

        $total = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM `coupons` c WHERE {$whereSql}",
            $bindings
        );

        $items = $this->db->select(
            sprintf(
                'SELECT c.*, p.`redemption_rows`, p.`total_discount_given`,
                        p.`total_order_value`, p.`unique_customers`
                   FROM `coupons` c
                   LEFT JOIN `vw_coupon_performance` p ON p.`id` = c.`id`
                  WHERE %s
                  ORDER BY c.`%s` %s
                  LIMIT %d OFFSET %d',
                $whereSql,
                $sort,
                $params['direction'],
                $params['per_page'],
                $params['offset']
            ),
            $bindings
        );

        return ['items' => $items, 'total' => $total];
    }

    public function replaceTargets(int $couponId, string $targetType, array $ids, ?int $actorId): void
    {
        $this->db->execute(
            'DELETE FROM `coupon_targets` WHERE `coupon_id` = :coupon_id',
            ['coupon_id' => $couponId]
        );

        foreach ($ids as $id) {
            $this->db->insert(
                'INSERT INTO `coupon_targets`
                     (`uuid`, `coupon_id`, `target_type`, `category_id`, `product_id`,
                      `created_by`, `created_date`, `is_active`, `is_deleted`, `version`)
                 VALUES
                     (:uuid, :coupon_id, :target_type, :category_id, :product_id,
                      :actor, NOW(), 1, 0, 1)',
                [
                    'uuid' => \App\Helpers\Uuid::v4(),
                    'coupon_id' => $couponId,
                    'target_type' => $targetType,
                    'category_id' => $targetType === 'category' ? (int) $id : null,
                    'product_id' => $targetType === 'product' ? (int) $id : null,
                    'actor' => $actorId,
                ]
            );
        }
    }

    /**
     * Slug-to-id lookups for target scoping. Kept here rather than injecting two
     * more repositories into the controller for one lookup each.
     */
    public function categoryIdBySlug(string $slug): ?int
    {
        $id = $this->db->scalar(
            'SELECT `id` FROM `categories` WHERE `slug` = :slug AND `is_deleted` = 0 LIMIT 1',
            ['slug' => strtolower($slug)]
        );

        return $id === null ? null : (int) $id;
    }

    public function productIdBySlug(string $slug): ?int
    {
        $id = $this->db->scalar(
            'SELECT `id` FROM `products` WHERE `slug` = :slug AND `is_deleted` = 0 LIMIT 1',
            ['slug' => strtolower($slug)]
        );

        return $id === null ? null : (int) $id;
    }

    /** @return array<string, mixed>|null */
    public function performance(int $couponId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM `vw_coupon_performance` WHERE `id` = :id LIMIT 1',
            ['id' => $couponId]
        );
    }
}
