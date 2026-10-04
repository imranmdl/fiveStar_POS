<?php

declare(strict_types=1);

namespace App\Repositories;

final class CouponRedemptionRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'coupon_redemptions';
    }

    protected function fillable(): array
    {
        return [
            'coupon_id', 'user_id', 'cart_id', 'order_reference',
            'discount_amount', 'order_value', 'status', 'released_reason',
        ];
    }

    /** @return array<string, mixed>|null */
    public function findForOrder(int $couponId, string $orderReference): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM `coupon_redemptions`
              WHERE `coupon_id` = :coupon_id AND `order_reference` = :order_reference
              LIMIT 1',
            ['coupon_id' => $couponId, 'order_reference' => $orderReference]
        );
    }

    public function release(int $redemptionId, string $reason, ?int $actorId): void
    {
        $this->update($redemptionId, ['status' => 'released', 'released_reason' => $reason], $actorId);
    }

    /**
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function paginateForCoupon(int $couponId, array $params): array
    {
        $total = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM `coupon_redemptions`
              WHERE `coupon_id` = :coupon_id AND `is_deleted` = 0',
            ['coupon_id' => $couponId]
        );

        $items = $this->db->select(
            sprintf(
                'SELECT r.`uuid`, r.`order_reference`, r.`discount_amount`, r.`order_value`,
                        r.`status`, r.`released_reason`, r.`created_date`,
                        u.`uuid` AS `customer_uuid`, u.`full_name` AS `customer_name`
                   FROM `coupon_redemptions` r
                   INNER JOIN `users` u ON u.`id` = r.`user_id`
                  WHERE r.`coupon_id` = :coupon_id AND r.`is_deleted` = 0
                  ORDER BY r.`created_date` %s
                  LIMIT %d OFFSET %d',
                $params['direction'],
                $params['per_page'],
                $params['offset']
            ),
            ['coupon_id' => $couponId]
        );

        return ['items' => $items, 'total' => $total];
    }
}
