<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\CategoryService;

final class CategoryController extends BaseController
{
    public function __construct(private readonly CategoryService $categories)
    {
    }

    /** GET /api/v1/categories — nested menu tree with live product counts */
    public function index(Request $request): Response
    {
        return Response::success(
            ['categories' => $this->categories->menuTree()],
            'Categories loaded'
        );
    }

    /** GET /api/v1/categories/{slug} */
    public function show(Request $request): Response
    {
        return Response::success(
            ['category' => $this->categories->findBySlug((string) $request->routeParam('slug'))],
            'Category loaded'
        );
    }

    /** GET /api/v1/admin/categories */
    public function adminIndex(Request $request): Response
    {
        $params = $this->paginationParams($request, 'display_order', 200);
        $result = $this->categories->paginateForAdmin($params);

        return $this->paginated($result['items'], $result['total'], $params, 'Categories loaded');
    }

    /** POST /api/v1/admin/categories */
    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'name' => 'required|string|min:2|max:120',
            'slug' => 'nullable|slug|max:140',
            'parent_slug' => 'nullable|string|max:140',
            'description' => 'nullable|string|max:2000',
            'display_order' => 'nullable|int|min:1|max:9999',
            'is_featured' => 'nullable|boolean',
            'show_in_menu' => 'nullable|boolean',
            'meta_title' => 'nullable|string|max:180',
            'meta_description' => 'nullable|string|max:320',
            'item_type' => 'nullable|in:grocery,oils,clothing,footwear,toys,stationery,general',
        ]);

        return Response::created(
            ['category' => $this->categories->create($data, $request)],
            'Category created'
        );
    }

    /** PATCH /api/v1/admin/categories/{uuid} */
    public function update(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'name' => 'nullable|string|min:2|max:120',
            'slug' => 'nullable|slug|max:140',
            'parent_slug' => 'nullable|string|max:140',
            'description' => 'nullable|string|max:2000',
            'display_order' => 'nullable|int|min:1|max:9999',
            'is_featured' => 'nullable|boolean',
            'show_in_menu' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
            'meta_title' => 'nullable|string|max:180',
            'meta_description' => 'nullable|string|max:320',
            'item_type' => 'nullable|in:grocery,oils,clothing,footwear,toys,stationery,general',
        ]);

        // Apply only what the caller actually sent.
        $supplied = array_intersect_key($data, $request->all());

        if ($supplied === []) {
            throw new HttpException('No changes were supplied.', 422);
        }

        return Response::success(
            ['category' => $this->categories->update((string) $request->routeParam('uuid'), $supplied, $request)],
            'Category updated'
        );
    }

    /** POST /api/v1/admin/categories/{uuid}/image */
    public function storeImage(Request $request): Response
    {
        if (!isset($request->files['image'])) {
            throw new HttpException('No image was received.', 422, [
                'image' => ['Attach the file as a multipart field named "image".'],
            ]);
        }

        return Response::success(
            [
                'category' => $this->categories->setImage(
                    (string) $request->routeParam('uuid'),
                    $request->files['image'],
                    $request
                ),
            ],
            'Category image updated'
        );
    }

    /** DELETE /api/v1/admin/categories/{uuid} */
    public function destroy(Request $request): Response
    {
        $this->categories->delete((string) $request->routeParam('uuid'), $request);

        return Response::success([], 'Category deleted');
    }
}
