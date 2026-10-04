<?php

declare(strict_types=1);

namespace App\Repositories;

final class ImportBatchItemRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'import_batch_items';
    }

    protected function fillable(): array
    {
        return [
            'import_batch_id', 'row_number', 'sku', 'is_valid', 'validation_errors',
            'action', 'product_variant_id', 'raw_data',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function forBatch(int $batchId): array
    {
        return $this->db->select(
            'SELECT * FROM `import_batch_items` WHERE `import_batch_id` = :batch_id ORDER BY `row_number` ASC',
            ['batch_id' => $batchId]
        );
    }
}
