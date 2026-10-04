<?php

declare(strict_types=1);

namespace App\Repositories;

final class PurchaseReturnItemRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'purchase_return_items';
    }

    protected function fillable(): array
    {
        return [
            'purchase_return_id', 'purchase_order_item_id', 'product_variant_id',
            'quantity', 'unit_cost', 'line_amount', 'batch_no',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function forReturn(int $purchaseReturnId): array
    {
        return $this->db->select(
            'SELECT ri.*, pv.`sku`, pv.`variant_name`
               FROM `purchase_return_items` ri
               INNER JOIN `product_variants` pv ON pv.`id` = ri.`product_variant_id`
              WHERE ri.`purchase_return_id` = :return_id AND ri.`is_deleted` = 0
              ORDER BY ri.`id` ASC',
            ['return_id' => $purchaseReturnId]
        );
    }
}
