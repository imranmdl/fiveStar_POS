<?php

declare(strict_types=1);

namespace App\Repositories;

final class PosRefundItemRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'pos_refund_items';
    }

    protected function fillable(): array
    {
        return ['pos_refund_id', 'pos_sale_item_id', 'quantity', 'amount'];
    }

    /** @return array<int, array<string, mixed>> */
    public function forRefund(int $refundId): array
    {
        return $this->db->select(
            'SELECT * FROM `pos_refund_items` WHERE `pos_refund_id` = :refund_id ORDER BY `id` ASC',
            ['refund_id' => $refundId]
        );
    }
}
