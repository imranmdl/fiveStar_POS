<?php

declare(strict_types=1);

namespace App\Repositories;

final class CategoryRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'categories';
    }

    protected function fillable(): array
    {
        return [
            'parent_id', 'slug', 'name', 'description', 'image_path', 'icon_path',
            'display_order', 'is_featured', 'show_in_menu', 'meta_title', 'meta_description', 'item_type',
        ];
    }

    protected function sortable(): array
    {
        return ['id', 'name', 'display_order', 'created_date'];
    }

    /**
     * Full menu tree in one query, nested in PHP. Two levels are used today but
     * the code handles arbitrary depth.
     *
     * @return array<int, array<string, mixed>>
     */
    public function tree(bool $menuOnly = true): array
    {
        $rows = $this->db->select(
            'SELECT `id`, `uuid`, `parent_id`, `parent_slug`, `slug`, `name`, `image_path`,
                    `icon_path`, `display_order`, `is_featured`, `show_in_menu`, `product_count`
               FROM `vw_category_tree`
              WHERE (:menu_only = 0 OR `show_in_menu` = 1)
              ORDER BY `display_order` ASC, `name` ASC',
            ['menu_only' => $menuOnly ? 1 : 0]
        );

        $byParent = [];

        foreach ($rows as $row) {
            $byParent[(int) ($row['parent_id'] ?? 0)][] = $row;
        }

        $build = static function (int $parentId) use (&$build, $byParent): array {
            $branch = [];

            foreach ($byParent[$parentId] ?? [] as $node) {
                $children = $build((int) $node['id']);

                unset($node['id'], $node['parent_id']);
                $node['children'] = $children;
                $branch[] = $node;
            }

            return $branch;
        };

        return $build(0);
    }

    /** @return array<string, mixed>|null */
    public function findBySlug(string $slug): ?array
    {
        return $this->findOneBy('slug', strtolower($slug));
    }

    /**
     * A category's whole top-level tree: itself and its children if it's a
     * top-level category, or its parent and all of the parent's children if
     * it's a child category. Categories in this catalog are at most two
     * levels deep, so no recursion is needed.
     *
     * @return array<int, int>
     */
    public function rootAndDescendantIds(int $categoryId): array
    {
        $category = $this->findById($categoryId);
        $rootId = $category !== null && $category['parent_id'] !== null
            ? (int) $category['parent_id']
            : $categoryId;

        // Two separately-named placeholders for the same value: this
        // connection's prepared statements don't support reusing one named
        // parameter twice in a query (same reason OfferRepository binds
        // `offer_id`/`offer_id_parent` separately for an identical value).
        $rows = $this->db->select(
            'SELECT `id` FROM `categories`
              WHERE (`id` = :root OR `parent_id` = :root_parent) AND `is_deleted` = 0',
            ['root' => $rootId, 'root_parent' => $rootId]
        );

        return array_map(static fn (array $row): int => (int) $row['id'], $rows);
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        return $this->existsWhere('slug', strtolower($slug), $exceptId);
    }

    /**
     * A category with published products or child categories must not be
     * deleted; the caller turns this into a 409 rather than orphaning data.
     */
    public function hasDependents(int $categoryId): bool
    {
        $products = (int) $this->db->scalar(
            'SELECT COUNT(*) FROM `products` WHERE `category_id` = :id AND `is_deleted` = 0',
            ['id' => $categoryId]
        );

        if ($products > 0) {
            return true;
        }

        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM `categories` WHERE `parent_id` = :id AND `is_deleted` = 0',
            ['id' => $categoryId]
        ) > 0;
    }

    /**
     * Guards against a category being made a descendant of itself, which would
     * make the tree unwalkable.
     */
    public function isDescendantOf(int $candidateParentId, int $categoryId): bool
    {
        $currentId = $candidateParentId;
        $guard = 0;

        while ($currentId !== 0 && $guard < 50) {
            if ($currentId === $categoryId) {
                return true;
            }

            $parent = $this->db->scalar(
                'SELECT `parent_id` FROM `categories` WHERE `id` = :id LIMIT 1',
                ['id' => $currentId]
            );

            $currentId = $parent === null ? 0 : (int) $parent;
            ++$guard;
        }

        return false;
    }

    /**
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string, search:?string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function paginateForAdmin(array $params): array
    {
        $where = ['c.`is_deleted` = 0'];
        $bindings = [];

        if ($params['search'] !== null) {
            $where[] = '(c.`name` LIKE :search OR c.`slug` LIKE :search_slug)';
            $bindings['search'] = '%' . $params['search'] . '%';
            $bindings['search_slug'] = '%' . $params['search'] . '%';
        }

        $whereSql = implode(' AND ', $where);
        $sort = in_array($params['sort'], $this->sortable(), true) ? $params['sort'] : 'display_order';

        $total = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM `categories` c WHERE {$whereSql}",
            $bindings
        );

        $items = $this->db->select(
            sprintf(
                'SELECT c.`uuid`, c.`slug`, c.`name`, c.`description`, c.`image_path`,
                        c.`display_order`, c.`is_featured`, c.`show_in_menu`, c.`is_active`, c.`item_type`,
                        c.`created_date`, parent.`slug` AS `parent_slug`, parent.`name` AS `parent_name`,
                        (SELECT COUNT(*) FROM `products` pr
                          WHERE pr.`category_id` = c.`id` AND pr.`is_deleted` = 0) AS `product_count`
                 FROM `categories` c
                 LEFT JOIN `categories` parent ON parent.`id` = c.`parent_id`
                 WHERE %s
                 ORDER BY c.`%s` %s
                 LIMIT %d OFFSET %d',
                $whereSql,
                $sort,
                $params['direction'],
                $params['per_page'],
                $params['offset']
            ),
            $bindings
        );

        return ['items' => $items, 'total' => $total];
    }
}
