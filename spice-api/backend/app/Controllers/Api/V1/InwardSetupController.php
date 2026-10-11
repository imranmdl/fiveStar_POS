<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\Catalog\ItemTypes;
use App\Services\Catalog\CategorySuggestionService;
use App\Services\InwardSetupService;

final class InwardSetupController extends BaseController
{
    public function __construct(
        private readonly InwardSetupService $setup,
        private readonly CategorySuggestionService $suggestions,
    ) {
    }

    /**
     * GET /api/v1/admin/inventory/suggest-category?name=…
     *
     * Category / sub-category suggestions for an item, read from its name.
     */
    public function suggestCategory(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'name' => 'required|string|min:2|max:180',
        ]);

        return Response::success($this->suggestions->suggest((string) $data['name']), 'Category suggestions');
    }

    /** GET /api/v1/admin/inventory/setup */
    public function show(Request $request): Response
    {
        return Response::success($this->setup->setup(), 'Inward setup loaded');
    }

    /** POST /api/v1/admin/inventory/quick-category */
    public function storeCategory(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'name' => 'required|string|min:2|max:120',
            'parent_uuid' => 'nullable|uuid',
            'item_type' => 'nullable|' . ItemTypes::rule(),
        ]);

        return Response::created(['category' => $this->setup->createCategory($data, $request)], 'Category created');
    }
}
