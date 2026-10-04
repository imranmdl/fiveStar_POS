<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\PricingRuleService;

final class PricingRuleController extends BaseController
{
    public function __construct(private readonly PricingRuleService $rules)
    {
    }

    /** GET /api/v1/admin/pricing/rules */
    public function index(Request $request): Response
    {
        return Response::success($this->rules->list(), 'Pricing rules loaded');
    }

    /** POST /api/v1/admin/pricing/rules */
    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), $this->validationRules(required: true));

        return Response::created($this->rules->create($data, $request), 'Pricing rule created');
    }

    /** PATCH /api/v1/admin/pricing/rules/{uuid} */
    public function update(Request $request): Response
    {
        $data = Validator::make($request->all(), $this->validationRules(required: false));
        $supplied = array_intersect_key($data, $request->all());

        return Response::success(
            $this->rules->update((string) $request->routeParam('uuid'), $supplied, $request),
            'Pricing rule updated'
        );
    }

    /** DELETE /api/v1/admin/pricing/rules/{uuid} */
    public function destroy(Request $request): Response
    {
        $this->rules->deactivate((string) $request->routeParam('uuid'), $request);

        return Response::success([], 'Pricing rule deactivated');
    }

    /** @return array<string, string> */
    private function validationRules(bool $required): array
    {
        $req = $required ? 'required' : 'nullable';

        return [
            'name' => $req . '|string|min:2|max:120',
            'scope' => $req . '|in:global,category,product,variant',
            'category_uuid' => 'nullable|uuid',
            'product_uuid' => 'nullable|uuid',
            'variant_uuid' => 'nullable|uuid',
            'calculation' => $req . '|in:markup_percent,margin_percent',
            'rate' => $req . '|numeric|min:0.001|max:999',
            'tax_mode' => 'nullable|in:exclusive,inclusive',
            'priority' => 'nullable|int|min:1|max:9999',
            'status' => 'nullable|in:active,inactive',
        ];
    }
}
