<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\VariantOptionService;

/**
 * Manages the generic variant-dimension schema (Size, Colour, Pack size, ...)
 * so an administrator can configure per-category tracking dimensions and
 * per-variant values without any change to the inventory engine's code.
 */
final class VariantOptionController extends BaseController
{
    public function __construct(private readonly VariantOptionService $options)
    {
    }

    /** GET /api/v1/admin/inventory/option-types */
    public function index(Request $request): Response
    {
        return Response::success($this->options->listTypes(), 'Dimensions loaded');
    }

    /** POST /api/v1/admin/inventory/option-types */
    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'code' => 'required|string|min:2|max:40',
            'name' => 'required|string|min:2|max:80',
            'display_order' => 'nullable|int|min:1|max:9999',
        ]);

        return Response::created($this->options->createType($data, $request), 'Dimension created');
    }

    /** POST /api/v1/admin/inventory/option-types/{uuid}/values */
    public function storeValue(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'value' => 'required|string|min:1|max:60',
            'display_order' => 'nullable|int|min:1|max:9999',
        ]);

        return Response::created(
            $this->options->addValue((string) $request->routeParam('uuid'), $data, $request),
            'Value added'
        );
    }

    /** PUT /api/v1/admin/categories/{uuid}/inventory-dimensions */
    public function setCategoryDimensions(Request $request): Response
    {
        $rows = $request->input('dimensions');

        if (!is_array($rows)) {
            throw new HttpException('Send a `dimensions` array.', 422, [
                'dimensions' => ['Expected a list of {option_type_id, is_required} objects.'],
            ]);
        }

        $validated = array_map(fn (array $row): array => Validator::make($row, [
            'option_type_id' => 'required|int|min:1',
            'is_required' => 'nullable|boolean',
            'display_order' => 'nullable|int|min:1|max:9999',
        ]), $rows);

        $this->options->setCategoryDimensions((string) $request->routeParam('uuid'), $validated, $request);

        return Response::success([], 'Category dimensions updated');
    }

    /** PUT /api/v1/admin/variants/{uuid}/options */
    public function setVariantOptions(Request $request): Response
    {
        $rows = $request->input('options');

        if (!is_array($rows)) {
            throw new HttpException('Send an `options` array.', 422, [
                'options' => ['Expected a list of {option_type_id, option_value_id} objects.'],
            ]);
        }

        $validated = array_map(fn (array $row): array => Validator::make($row, [
            'option_type_id' => 'required|int|min:1',
            'option_value_id' => 'required|int|min:1',
        ]), $rows);

        $this->options->setVariantOptions((string) $request->routeParam('uuid'), $validated, $request);

        return Response::success([], 'Pack size dimensions updated');
    }
}
