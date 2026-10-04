<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Helpers\Str;
use App\Repositories\CategoryRepository;

final class CategoryService
{
    public function __construct(
        private readonly CategoryRepository $categories,
        private readonly FileUploadService $uploads,
        private readonly AuditService $audit,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function menuTree(): array
    {
        return $this->decorate($this->categories->tree(menuOnly: true));
    }

    /** @return array<string, mixed> */
    public function findBySlug(string $slug): array
    {
        $category = $this->categories->findBySlug($slug);

        if ($category === null) {
            throw new NotFoundException('That category does not exist.');
        }

        return [
            'uuid' => $category['uuid'],
            'slug' => $category['slug'],
            'name' => $category['name'],
            'description' => $category['description'],
            'image_url' => $this->uploads->publicUrl($category['image_path']),
            'meta' => [
                'title' => $category['meta_title'] ?? $category['name'],
                'description' => $category['meta_description'] ?? $category['description'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function create(array $data, Request $request): array
    {
        $parentId = null;

        if (!empty($data['parent_slug'])) {
            $parent = $this->categories->findBySlug((string) $data['parent_slug']);

            if ($parent === null) {
                throw new HttpException('The parent category does not exist.', 422, [
                    'parent_slug' => ['Unknown category: ' . $data['parent_slug']],
                ]);
            }

            $parentId = (int) $parent['id'];
        }

        $slug = $this->uniqueSlug($data['slug'] ?? $data['name']);

        $categoryId = $this->categories->create([
            'parent_id' => $parentId,
            'slug' => $slug,
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'display_order' => (int) ($data['display_order'] ?? 100),
            'is_featured' => (int) ($data['is_featured'] ?? 0),
            'show_in_menu' => (int) ($data['show_in_menu'] ?? 1),
            'meta_title' => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
            'item_type' => $parentId === null ? ($data['item_type'] ?? null) : null,
        ], $request->authUserId());

        $this->audit->log(
            entityName: 'categories',
            entityId: $categoryId,
            action: 'create',
            newValues: ['name' => $data['name'], 'slug' => $slug, 'parent_id' => $parentId],
            request: $request
        );

        return $this->presentAdmin((array) $this->categories->findById($categoryId));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function update(string $uuid, array $data, Request $request): array
    {
        $category = $this->requireCategory($uuid);
        $changes = [];

        if (array_key_exists('parent_slug', $data)) {
            if (empty($data['parent_slug'])) {
                $changes['parent_id'] = null;
            } else {
                $parent = $this->categories->findBySlug((string) $data['parent_slug']);

                if ($parent === null) {
                    throw new HttpException('The parent category does not exist.', 422, [
                        'parent_slug' => ['Unknown category: ' . $data['parent_slug']],
                    ]);
                }

                // Reparenting a category under its own descendant would make
                // the tree cyclic and unwalkable.
                if ((int) $parent['id'] === (int) $category['id']
                    || $this->categories->isDescendantOf((int) $parent['id'], (int) $category['id'])) {
                    throw new HttpException(
                        'A category cannot be moved inside itself or one of its own subcategories.',
                        422,
                        ['parent_slug' => ['This would create a loop in the category tree.']]
                    );
                }

                $changes['parent_id'] = (int) $parent['id'];
            }
        }

        foreach (['name', 'description', 'display_order', 'is_featured', 'show_in_menu',
            'meta_title', 'meta_description', 'is_active', 'item_type'] as $field) {
            if (array_key_exists($field, $data)) {
                $changes[$field] = $data[$field];
            }
        }

        if (!empty($data['slug']) && $data['slug'] !== $category['slug']) {
            $changes['slug'] = $this->uniqueSlug((string) $data['slug'], (int) $category['id']);
        }

        if ($changes === []) {
            throw new HttpException('No changes were supplied.', 422);
        }

        $this->categories->update((int) $category['id'], $changes, $request->authUserId());

        $this->audit->log(
            entityName: 'categories',
            entityId: (int) $category['id'],
            action: 'update',
            oldValues: array_intersect_key($category, $changes),
            newValues: $changes,
            request: $request,
            entityUuid: $uuid
        );

        return $this->presentAdmin((array) $this->categories->findById((int) $category['id']));
    }

    /**
     * @param array<string, mixed> $file A $_FILES entry
     *
     * @return array<string, mixed>
     */
    public function setImage(string $uuid, array $file, Request $request): array
    {
        $category = $this->requireCategory($uuid);
        $previousPath = $category['image_path'];

        $stored = $this->uploads->storeImage($file, 'categories');

        $this->categories->update(
            (int) $category['id'],
            ['image_path' => $stored['file_path']],
            $request->authUserId()
        );

        // Replaced artwork is removed only after the new path is committed.
        if ($previousPath !== null) {
            $this->uploads->delete($previousPath);
        }

        $this->audit->log(
            entityName: 'categories',
            entityId: (int) $category['id'],
            action: 'set_image',
            oldValues: ['image_path' => $previousPath],
            newValues: ['image_path' => $stored['file_path']],
            request: $request,
            entityUuid: $uuid
        );

        return $this->presentAdmin((array) $this->categories->findById((int) $category['id']));
    }

    public function delete(string $uuid, Request $request): void
    {
        $category = $this->requireCategory($uuid);

        if ($this->categories->hasDependents((int) $category['id'])) {
            throw new HttpException(
                'This category still has products or subcategories. Move them first.',
                409,
                ['category' => ['Reassign the contents of this category before deleting it.']]
            );
        }

        $this->categories->softDelete((int) $category['id'], $request->authUserId());

        $this->audit->log(
            entityName: 'categories',
            entityId: (int) $category['id'],
            action: 'delete',
            oldValues: ['name' => $category['name'], 'slug' => $category['slug']],
            request: $request,
            entityUuid: $uuid
        );
    }

    /**
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string, search:?string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function paginateForAdmin(array $params): array
    {
        $result = $this->categories->paginateForAdmin($params);

        $result['items'] = array_map(fn (array $row): array => array_merge($row, [
            'image_url' => $this->uploads->publicUrl($row['image_path'] ?? null),
            'is_featured' => (bool) $row['is_featured'],
            'show_in_menu' => (bool) $row['show_in_menu'],
            'is_active' => (bool) $row['is_active'],
            'product_count' => (int) $row['product_count'],
        ]), $result['items']);

        return $result;
    }

    /**
     * @param array<int, array<string, mixed>> $nodes
     *
     * @return array<int, array<string, mixed>>
     */
    private function decorate(array $nodes): array
    {
        foreach ($nodes as $index => $node) {
            $nodes[$index]['image_url'] = $this->uploads->publicUrl($node['image_path'] ?? null);
            $nodes[$index]['icon_url'] = $this->uploads->publicUrl($node['icon_path'] ?? null);
            $nodes[$index]['product_count'] = (int) $node['product_count'];
            $nodes[$index]['is_featured'] = (bool) $node['is_featured'];

            unset($nodes[$index]['image_path'], $nodes[$index]['icon_path'], $nodes[$index]['show_in_menu']);

            if (!empty($node['children'])) {
                $nodes[$index]['children'] = $this->decorate($node['children']);
            }
        }

        return $nodes;
    }

    /** @return array<string, mixed> */
    private function requireCategory(string $uuid): array
    {
        $category = $this->categories->findByUuid($uuid);

        if ($category === null) {
            throw new NotFoundException('That category does not exist.');
        }

        return $category;
    }

    /** @param array<string, mixed> $row */
    private function presentAdmin(array $row): array
    {
        return [
            'uuid' => $row['uuid'],
            'slug' => $row['slug'],
            'name' => $row['name'],
            'description' => $row['description'],
            'image_url' => $this->uploads->publicUrl($row['image_path']),
            'display_order' => (int) $row['display_order'],
            'is_featured' => (bool) $row['is_featured'],
            'show_in_menu' => (bool) $row['show_in_menu'],
            'is_active' => (bool) $row['is_active'],
            'item_type' => $row['item_type'] ?? null,
            'created_date' => $row['created_date'],
        ];
    }

    private function uniqueSlug(string $source, ?int $exceptId = null): string
    {
        $base = Str::slug($source, 130);
        $candidate = $base;
        $suffix = 2;

        while ($this->categories->slugExists($candidate, $exceptId)) {
            $candidate = $base . '-' . $suffix;
            ++$suffix;

            if ($suffix > 100) {
                return $base . '-' . bin2hex(random_bytes(3));
            }
        }

        return $candidate;
    }
}
