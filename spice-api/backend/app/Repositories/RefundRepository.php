<?php

declare(strict_types=1);

namespace App\Repositories;

final class RefundRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'refunds';
    }

    protected function fillable(): array
    {
        return [
            'order_id', 'payment_id', 'gateway', 'gateway_refund_id', 'total_amount',
            'gateway_amount', 'wallet_amount', 'reason', 'status', 'failure_reason',
            'completed_date', 'idempotency_key', 'gateway_response',
        ];
    }

    /** @return array<string, mixed>|null */
    public function findByIdempotencyKey(string $key): ?array
    {
        return $this->findOneBy('idempotency_key', $key);
    }

    /** @return array<int, array<string, mixed>> */
    public function forOrder(int $orderId): array
    {
        return $this->db->select(
            'SELECT * FROM `refunds` WHERE `order_id` = :order_id AND `is_deleted` = 0 ORDER BY `id`',
            ['order_id' => $orderId]
        );
    }

    public function totalRefundedFor(int $orderId): string
    {
        return (string) ($this->db->scalar(
            "SELECT COALESCE(SUM(`total_amount`), 0) FROM `refunds`
              WHERE `order_id` = :order_id AND `status` = 'completed' AND `is_deleted` = 0",
            ['order_id' => $orderId]
        ) ?? '0.00');
    }

    /**
     * Every refund still waiting on the gateway — what the Wallets
     * dashboard's "Pending refunds" tile totals up. Joined to the order and
     * customer so the admin drilling into that number sees which orders are
     * actually waiting, not just a count.
     *
     * @param array{page:int, per_page:int, offset:int} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function pendingForAdmin(array $params): array
    {
        $where = "r.`status` IN ('pending', 'processing') AND r.`is_deleted` = 0";

        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM `refunds` r WHERE {$where}");

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $items = $this->db->select(
            sprintf(
                "SELECT r.`uuid`, r.`total_amount`, r.`gateway_amount`, r.`wallet_amount`,
                        r.`reason`, r.`status`, r.`gateway`, r.`created_date`,
                        o.`uuid` AS `order_uuid`, o.`order_number`,
                        u.`full_name` AS `customer_name`, u.`mobile` AS `customer_mobile`
                   FROM `refunds` r
                   INNER JOIN `orders` o ON o.`id` = r.`order_id`
                   INNER JOIN `users` u ON u.`id` = o.`user_id`
                  WHERE {$where}
                  ORDER BY r.`created_date` ASC
                  LIMIT %d OFFSET %d",
                $params['per_page'],
                $params['offset']
            )
        );

        return ['items' => $items, 'total' => $total];
    }
}
