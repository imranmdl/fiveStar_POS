<?php

declare(strict_types=1);

namespace App\Repositories;

final class VariantOptionTypeRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'variant_option_types';
    }

    protected function fillable(): array
    {
        return ['code', 'name', 'display_order'];
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select(
            'SELECT * FROM `variant_option_types` WHERE `is_deleted` = 0
              ORDER BY `display_order` ASC, `name` ASC'
        );
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        return $this->existsWhere('code', strtolower($code), $exceptId);
    }

    /**
     * The dimensions configured for a category, e.g. Size + Colour for
     * clothing. Drives the admin UI and inward validation without any
     * per-category branching in code.
     *
     * @return array<int, array<string, mixed>>
     */
    public function forCategory(int $categoryId): array
    {
        return $this->db->select(
            'SELECT t.*, cot.`is_required`, cot.`display_order` AS `category_display_order`
               FROM `category_option_types` cot
               INNER JOIN `variant_option_types` t ON t.`id` = cot.`option_type_id` AND t.`is_deleted` = 0
              WHERE cot.`category_id` = :category_id AND cot.`is_deleted` = 0
              ORDER BY cot.`display_order` ASC',
            ['category_id' => $categoryId]
        );
    }
}
