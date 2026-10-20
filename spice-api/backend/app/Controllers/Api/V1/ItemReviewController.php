<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Services\ItemCorrectionService;

/**
 * Administrator-only "Item review": the queue of items staff entered or
 * changed, and the one screen that corrects an item's name, category, units,
 * prices and purchase costs everywhere they are used. See
 * ItemCorrectionService for what a correction reaches and what it never
 * rewrites.
 */
final class ItemReviewController extends BaseController
{
    public function __construct(private readonly ItemCorrectionService $corrections)
    {
    }

    /** GET /api/v1/admin/item-review?status=pending|reviewed|all&q=&page= */
    public function index(Request $request): Response
    {
        return Response::success($this->corrections->list([
            'status' => (string) $request->query('status', 'pending'),
            'q' => (string) $request->query('q', ''),
            'page' => (int) $request->query('page', 1),
        ]), 'Item review queue loaded');
    }

    /** GET /api/v1/admin/item-review/{uuid} */
    public function show(Request $request): Response
    {
        return Response::success(
            $this->corrections->detail((string) $request->routeParam('uuid')),
            'Item loaded'
        );
    }

    /** PATCH /api/v1/admin/item-review/{uuid} */
    public function update(Request $request): Response
    {
        $body = $request->all();

        foreach (['variants', 'purchase_lines', 'stock'] as $list) {
            if (isset($body[$list]) && !is_array($body[$list])) {
                throw new HttpException('The correction is not in the expected format.', 422, [$list => ['Must be a list.']]);
            }
        }

        if (isset($body['note']) && mb_strlen((string) $body['note']) > 500) {
            throw new HttpException('The note is too long.', 422, ['note' => ['Keep it under 500 characters.']]);
        }

        $result = $this->corrections->apply((string) $request->routeParam('uuid'), $body, $request);

        return Response::success(
            $result,
            $result['applied'] === [] ? 'Nothing changed' : 'Correction saved everywhere it applies'
        );
    }
}
