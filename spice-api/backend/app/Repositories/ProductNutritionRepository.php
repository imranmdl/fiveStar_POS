<?php

declare(strict_types=1);

namespace App\Repositories;

final class ProductNutritionRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'product_nutrition';
    }

    protected function fillable(): array
    {
        return [
            'product_id', 'serving_size_g', 'energy_kcal', 'protein_g', 'total_fat_g',
            'saturated_fat_g', 'trans_fat_g', 'carbohydrate_g', 'total_sugar_g',
            'added_sugar_g', 'dietary_fibre_g', 'sodium_mg', 'iron_mg', 'calcium_mg',
            'allergen_info',
        ];
    }

    /** @return array<string, mixed>|null */
    public function forProduct(int $productId): ?array
    {
        return $this->findOneBy('product_id', $productId);
    }

    /** @param array<string, mixed> $attributes */
    public function upsertForProduct(int $productId, array $attributes, ?int $actorId): void
    {
        $existing = $this->forProduct($productId);
        $attributes['product_id'] = $productId;

        if ($existing === null) {
            $this->create($attributes, $actorId);

            return;
        }

        $this->update((int) $existing['id'], $attributes, $actorId);
    }
}
