<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\ImportService;
use App\Services\VendorService;

final class VendorController extends BaseController
{
    public function __construct(
        private readonly VendorService $vendors,
        private readonly ImportService $imports,
    ) {
    }

    /**
     * POST /api/v1/admin/import/vendors
     * multipart/form-data: `file` (.csv or .xlsx) — see
     * ImportService::importVendors() for the exact column headers expected
     * (the same as GET /admin/export/vendors/template produces).
     */
    public function importVendors(Request $request): Response
    {
        if (!isset($request->files['file'])) {
            throw new HttpException('No file was received.', 422, [
                'file' => ['Attach the file as a multipart field named "file".'],
            ]);
        }

        return Response::success($this->imports->importVendors($request->files['file'], $request), 'File processed');
    }

    /** GET /api/v1/admin/vendors */
    public function index(Request $request): Response
    {
        $params = $this->paginationParams($request, 'name', 100);
        $params['active_only'] = filter_var($request->query('active_only', false), FILTER_VALIDATE_BOOLEAN);
        $result = $this->vendors->list($params);

        return $this->paginated($result['items'], $result['total'], $params, 'Vendors loaded');
    }

    /** GET /api/v1/admin/vendors/{uuid} */
    public function show(Request $request): Response
    {
        return Response::success($this->vendors->detail((string) $request->routeParam('uuid')), 'Vendor loaded');
    }

    /**
     * GET /api/v1/admin/vendors/{uuid}/history
     * Purchases + payments + returns + pending balance in one place.
     */
    public function history(Request $request): Response
    {
        return Response::success(
            $this->vendors->history((string) $request->routeParam('uuid')),
            'Vendor history loaded'
        );
    }

    /** GET /api/v1/admin/vendors/dashboard */
    public function dashboard(): Response
    {
        return Response::success($this->vendors->dashboardStats(), 'Vendor dashboard loaded');
    }

    /** POST /api/v1/admin/vendors */
    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'name' => 'required|string|min:2|max:150',
            'company_name' => 'nullable|string|max:160',
            'contact_person' => 'nullable|string|max:120',
            'phone' => 'nullable|string|max:15',
            'email' => 'nullable|email|max:150',
            'address_line1' => 'nullable|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'pincode' => 'nullable|string|max:10',
            'country' => 'nullable|string|max:60',
            'gstin' => 'nullable|string|max:20',
            'pan' => 'nullable|string|max:10',
            'bank_account_name' => 'nullable|string|max:160',
            'bank_account_number' => 'nullable|string|max:30',
            'bank_ifsc' => 'nullable|string|max:11',
            'bank_name' => 'nullable|string|max:120',
            'payment_terms' => 'nullable|string|max:120',
            'notes' => 'nullable|string|max:500',
        ]);

        // country is the one vendor field that is NOT NULL DEFAULT 'India' in
        // the schema, not a genuinely nullable column like the others above —
        // Validator::make() sets an omitted `nullable` field to null, which is
        // correct for those, but must not reach the INSERT as an explicit
        // null here or it violates the column's own NOT NULL constraint.
        $data['country'] = $data['country'] ?? 'India';

        return Response::created($this->vendors->create($data, $request), 'Vendor created');
    }

    /** PATCH /api/v1/admin/vendors/{uuid} */
    public function update(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'name' => 'nullable|string|min:2|max:150',
            'company_name' => 'nullable|string|max:160',
            'contact_person' => 'nullable|string|max:120',
            'phone' => 'nullable|string|max:15',
            'email' => 'nullable|email|max:150',
            'address_line1' => 'nullable|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'city' => 'nullable|string|max:100',
            'state' => 'nullable|string|max:100',
            'pincode' => 'nullable|string|max:10',
            'country' => 'nullable|string|max:60',
            'gstin' => 'nullable|string|max:20',
            'pan' => 'nullable|string|max:10',
            'bank_account_name' => 'nullable|string|max:160',
            'bank_account_number' => 'nullable|string|max:30',
            'bank_ifsc' => 'nullable|string|max:11',
            'bank_name' => 'nullable|string|max:120',
            'payment_terms' => 'nullable|string|max:120',
            'notes' => 'nullable|string|max:500',
        ]);

        $supplied = array_intersect_key($data, $request->all());

        return Response::success(
            $this->vendors->update((string) $request->routeParam('uuid'), $supplied, $request),
            'Vendor updated'
        );
    }

    /** DELETE /api/v1/admin/vendors/{uuid} */
    public function destroy(Request $request): Response
    {
        $this->vendors->deactivate((string) $request->routeParam('uuid'), $request);

        return Response::success([], 'Vendor deactivated');
    }
}
