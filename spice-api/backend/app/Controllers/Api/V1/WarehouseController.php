<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\WarehouseService;

final class WarehouseController extends BaseController
{
    public function __construct(private readonly WarehouseService $warehouses)
    {
    }

    /** GET /api/v1/admin/warehouses */
    public function index(Request $request): Response
    {
        $activeOnly = filter_var($request->query('active_only', false), FILTER_VALIDATE_BOOLEAN);

        return Response::success($this->warehouses->list($activeOnly), 'Warehouses loaded');
    }

    /** GET /api/v1/admin/warehouses/{uuid} */
    public function show(Request $request): Response
    {
        return Response::success(
            $this->warehouses->detail((string) $request->routeParam('uuid')),
            'Warehouse loaded'
        );
    }

    /** POST /api/v1/admin/warehouses */
    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'code' => 'required|string|min:2|max:30',
            'name' => 'required|string|min:2|max:120',
            'address_line1' => 'nullable|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'pincode' => 'nullable|string|max:10',
            'country' => 'nullable|string|max:60',
            'phone' => 'nullable|string|max:15',
            'is_default' => 'nullable|boolean',
        ]);

        return Response::created($this->warehouses->create($data, $request), 'Warehouse created');
    }

    /** PATCH /api/v1/admin/warehouses/{uuid} */
    public function update(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'code' => 'nullable|string|min:2|max:30',
            'name' => 'nullable|string|min:2|max:120',
            'address_line1' => 'nullable|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'pincode' => 'nullable|string|max:10',
            'country' => 'nullable|string|max:60',
            'phone' => 'nullable|string|max:15',
            'is_default' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);

        $supplied = array_intersect_key($data, $request->all());

        return Response::success(
            $this->warehouses->update((string) $request->routeParam('uuid'), $supplied, $request),
            'Warehouse updated'
        );
    }

    /** DELETE /api/v1/admin/warehouses/{uuid} */
    public function destroy(Request $request): Response
    {
        $this->warehouses->deactivate((string) $request->routeParam('uuid'), $request);

        return Response::success([], 'Warehouse deactivated');
    }
}
