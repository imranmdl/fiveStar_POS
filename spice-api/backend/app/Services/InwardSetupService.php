<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;

/**
 * What the Purchase Inward screen needs to build its cascading
 * Category -> Sub-category -> Item pickers and per-business-type fields, in
 * one call, and a manager-level way to add a missing category/sub-category
 * without leaving the screen (the full category editor stays
 * administrator-only).
 */
final class InwardSetupService
{
    public function __construct(
        private readonly Database $db,
        private readonly CategoryService $categories,
        private readonly VariantOptionService $options,
    ) {
    }

    /** @return array<string, mixed> */
    public function setup(): array
    {
        $rows = $this->db->select(
            'SELECT c.`uuid`, c.`slug`, c.`name`, c.`item_type`, c.`parent_id`, c.`id`, p.`uuid` AS `parent_uuid`
               FROM `categories` c
               LEFT JOIN `categories` p ON p.`id` = c.`parent_id`
              WHERE c.`is_deleted` = 0 AND c.`is_active` = 1
              ORDER BY c.`display_order` ASC, c.`name` ASC'
        );

        $byId = [];
        $hasChildren = [];

        foreach ($rows as $row) {
            $byId[(int) $row['id']] = $row;

            if ($row['parent_id'] !== null) {
                $hasChildren[(int) $row['parent_id']] = true;
            }
        }

        $categories = [];

        foreach ($rows as $row) {
            $cursor = $row;
            $type = null;

            for ($depth = 0; $cursor !== null && $depth < 12; ++$depth) {
                if ($cursor['item_type'] !== null) {
                    $type = $cursor['item_type'];
                    break;
                }

                $cursor = $cursor['parent_id'] !== null ? ($byId[(int) $cursor['parent_id']] ?? null) : null;
            }

            $categories[] = [
                'uuid' => $row['uuid'],
                'slug' => $row['slug'],
                'name' => $row['name'],
                'parent_uuid' => $row['parent_uuid'],
                'item_type' => $type ?? 'general',
                'has_children' => isset($hasChildren[(int) $row['id']]),
            ];
        }

        $optionTypes = array_map(static fn (array $type): array => [
            'code' => $type['code'],
            'name' => $type['name'],
            'values' => array_map(static fn (array $v): string => (string) $v['value'], $type['values']),
        ], $this->options->listTypes());

        return ['categories' => $categories, 'option_types' => $optionTypes];
    }

    /**
     * @param array<string, mixed> $data name, parent_uuid? (sub-category), item_type? (top-level only)
     *
     * @return array<string, mixed>
     */
    public function createCategory(array $data, Request $request): array
    {
        $parentSlug = null;

        if (!empty($data['parent_uuid'])) {
            $parent = $this->db->selectOne(
                'SELECT `slug` FROM `categories` WHERE `uuid` = :uuid AND `is_deleted` = 0 LIMIT 1',
                ['uuid' => $data['parent_uuid']]
            );

            if ($parent === null) {
                throw new HttpException('That category does not exist.', 422, ['parent_uuid' => ['Unknown category.']]);
            }

            $parentSlug = (string) $parent['slug'];
        } elseif (empty($data['item_type'])) {
            throw new HttpException('Choose what kind of business this category is for.', 422, [
                'item_type' => ['Required for a new top-level category.'],
            ]);
        }

        return $this->categories->create([
            'name' => $data['name'],
            'parent_slug' => $parentSlug,
            'item_type' => $data['item_type'] ?? null,
            // A new top-level category stays off the storefront menu until an
            // administrator publishes it; a sub-category follows its parent.
            'show_in_menu' => $parentSlug === null ? 0 : 1,
        ], $request);
    }
}
