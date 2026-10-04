<?php

declare(strict_types=1);

namespace App\Repositories;

final class VariantOptionValueRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'variant_option_values';
    }

    protected function fillable(): array
    {
        return ['option_type_id', 'value', 'display_order'];
    }

    /** @return array<int, array<string, mixed>> */
    public function forType(int $optionTypeId): array
    {
        return $this->db->select(
            'SELECT * FROM `variant_option_values`
              WHERE `option_type_id` = :type_id AND `is_deleted` = 0
              ORDER BY `display_order` ASC, `value` ASC',
            ['type_id' => $optionTypeId]
        );
    }

    public function valueExists(int $optionTypeId, string $value, ?int $exceptId = null): bool
    {
        $sql = 'SELECT 1 FROM `variant_option_values`
                 WHERE `option_type_id` = :type_id AND `value` = :value AND `is_deleted` = 0';
        $bindings = ['type_id' => $optionTypeId, 'value' => $value];

        if ($exceptId !== null) {
            $sql .= ' AND `id` <> :except_id';
            $bindings['except_id'] = $exceptId;
        }

        return $this->db->scalar($sql . ' LIMIT 1', $bindings) !== null;
    }
}
