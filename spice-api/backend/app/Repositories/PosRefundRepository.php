<?php

declare(strict_types=1);

namespace App\Repositories;

final class PosRefundRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'pos_refunds';
    }

    protected function fillable(): array
    {
        return ['refund_number', 'pos_sale_id', 'refunded_by', 'reason', 'refund_amount', 'refund_method'];
    }

    /** @return array<int, array<string, mixed>> */
    public function forSale(int $saleId): array
    {
        return $this->db->select(
            'SELECT * FROM `pos_refunds` WHERE `pos_sale_id` = :sale_id ORDER BY `id` ASC',
            ['sale_id' => $saleId]
        );
    }
}
