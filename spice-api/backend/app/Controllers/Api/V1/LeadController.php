<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\MarketingService;

/** Website visitor capture (public) and the leads list (staff). */
final class LeadController extends BaseController
{
    public function __construct(private readonly MarketingService $marketing)
    {
    }

    /** POST /api/v1/leads — the storefront capture popup. */
    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'full_name' => 'nullable|string|max:120',
            'email' => 'required|email|max:190',
            'mobile' => 'required|mobile_in',
            'consent' => 'required|boolean',
            'source' => 'nullable|string|max:60',
        ]);

        return Response::created(
            ['lead' => $this->marketing->captureLead($data, $request)],
            $data['consent']
                ? 'Thanks — we will keep you posted.'
                : 'Saved. You can opt in to offers any time.'
        );
    }

    /** POST /api/v1/leads/{uuid}/unsubscribe */
    public function unsubscribe(Request $request): Response
    {
        $this->marketing->unsubscribe((string) $request->routeParam('uuid'));

        return Response::success([], 'Unsubscribed.');
    }

    /** GET /api/v1/admin/leads */
    public function index(Request $request): Response
    {
        $params = $this->paginationParams($request, 'created_date', 50);
        $result = $this->marketing->paginateForAdmin($params);

        return Response::success($result['items'], 'Leads loaded', 200, [
            'page' => $params['page'],
            'per_page' => $params['per_page'],
            'total' => $result['total'],
            'total_pages' => $params['per_page'] > 0 ? (int) ceil($result['total'] / $params['per_page']) : 0,
            'summary' => $this->marketing->summary(),
        ]);
    }
}
