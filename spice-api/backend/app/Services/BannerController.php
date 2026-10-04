<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\BannerService;

final class BannerController extends BaseController
{
    public function __construct(private readonly BannerService $banners)
    {
    }

    /** GET /api/v1/banners?placement=home_hero */
    public function index(Request $request): Response
    {
        $placement = (string) $request->query('placement', 'home_hero');

        return Response::success(
            ['banners' => $this->banners->liveForPlacement($placement)],
            'Banners loaded'
        );
    }

    /** POST /api/v1/banners/{uuid}/click — click-through telemetry */
    public function click(Request $request): Response
    {
        $this->banners->recordClick((string) $request->routeParam('uuid'));

        return Response::success([], 'Click recorded');
    }

    /** GET /api/v1/admin/banners */
    public function adminIndex(Request $request): Response
    {
        $params = $this->paginationParams($request, 'display_order', 100);
        $placement = $request->query('placement');

        $result = $this->banners->paginateForAdmin(
            $params,
            is_string($placement) && $placement !== '' ? $placement : null
        );

        return $this->paginated($result['items'], $result['total'], $params, 'Banners loaded');
    }

    /** POST /api/v1/admin/banners — multipart/form-data */
    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'title' => 'required|string|min:2|max:160',
            'subtitle' => 'nullable|string|max:240',
            'alt_text' => 'nullable|string|max:180',
            'placement' => 'required|in:home_hero,home_strip,category_top,app_home,checkout',
            'link_type' => 'nullable|in:none,category,product,url,offer,collection',
            'link_value' => 'nullable|string|max:255',
            'cta_label' => 'nullable|string|max:60',
            'display_order' => 'nullable|int|min:1|max:9999',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
        ]);

        $data['link_type'] ??= 'none';

        if (!empty($data['start_date']) && !empty($data['end_date'])
            && strtotime((string) $data['end_date']) <= strtotime((string) $data['start_date'])) {
            throw new HttpException('The banner must end after it starts.', 422, [
                'end_date' => ['Choose an end date after the start date.'],
            ]);
        }

        return Response::created(
            ['banner' => $this->banners->create($data, $request->files, $request)],
            'Banner created'
        );
    }

    /** PATCH /api/v1/admin/banners/{uuid} */
    public function update(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'title' => 'nullable|string|min:2|max:160',
            'subtitle' => 'nullable|string|max:240',
            'alt_text' => 'nullable|string|max:180',
            'placement' => 'nullable|in:home_hero,home_strip,category_top,app_home,checkout',
            'link_type' => 'nullable|in:none,category,product,url,offer,collection',
            'link_value' => 'nullable|string|max:255',
            'cta_label' => 'nullable|string|max:60',
            'display_order' => 'nullable|int|min:1|max:9999',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date',
            'is_active' => 'nullable|boolean',
        ]);

        $supplied = array_intersect_key($data, $request->all());

        if ($supplied === []) {
            throw new HttpException('No changes were supplied.', 422);
        }

        return Response::success(
            ['banner' => $this->banners->update((string) $request->routeParam('uuid'), $supplied, $request)],
            'Banner updated'
        );
    }

    /** DELETE /api/v1/admin/banners/{uuid} */
    public function destroy(Request $request): Response
    {
        $this->banners->delete((string) $request->routeParam('uuid'), $request);

        return Response::success([], 'Banner deleted');
    }
}
