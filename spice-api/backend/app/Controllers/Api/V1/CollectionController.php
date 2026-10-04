<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\CollectionService;

/**
 * Campaign pages: a curated set of products under a slug and a template.
 *
 * A shop owner builds one for a season, points an advert at it, and customers
 * land on it and buy from it like any other page in the shop.
 */
final class CollectionController extends BaseController
{
    public function __construct(private readonly CollectionService $collections)
    {
    }

    /** Live campaign pages, for the home page's festive gifts strip. */
    public function index(Request $request): Response
    {
        return Response::success(['collections' => $this->collections->liveList()]);
    }

    /** A campaign page as a shopper sees it. */
    public function show(Request $request): Response
    {
        return Response::success(
            $this->collections->publicView((string) $request->routeParam('slug'))
        );
    }

    /** Every campaign page, for staff. */
    public function adminIndex(Request $request): Response
    {
        return Response::success([
            'collections' => $this->collections->listForAdmin($request->query('status')),
        ]);
    }

    public function adminShow(Request $request): Response
    {
        return Response::success($this->collections->adminView((string) $request->routeParam('slug')));
    }

    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'title' => 'required|string|min:3|max:180',
            'slug' => 'nullable|string|min:3|max:160',
            'subtitle' => 'nullable|string|max:320',
            'intro' => 'nullable|string|max:2000',
            'template' => 'nullable|in:grid,spotlight,story,gift',
            'cta_label' => 'nullable|string|max:60',
            'starts_date' => 'nullable|date',
            'ends_date' => 'nullable|date',
            'display_order' => 'nullable|int|min:1|max:9999',
            'meta_title' => 'nullable|string|max:180',
            'meta_description' => 'nullable|string|max:320',
        ]);

        return Response::created($this->collections->create($data, $request));
    }

    public function update(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'title' => 'nullable|string|min:3|max:180',
            'subtitle' => 'nullable|string|max:320',
            'intro' => 'nullable|string|max:2000',
            'template' => 'nullable|in:grid,spotlight,story,gift',
            'cta_label' => 'nullable|string|max:60',
            'starts_date' => 'nullable|date',
            'ends_date' => 'nullable|date',
            'display_order' => 'nullable|int|min:1|max:9999',
            'meta_title' => 'nullable|string|max:180',
            'meta_description' => 'nullable|string|max:320',
        ]);

        return Response::success($this->collections->update(
            (string) $request->routeParam('slug'), $data, $request
        ));
    }

    /** POST /api/v1/admin/collections/{slug}/image */
    public function storeImage(Request $request): Response
    {
        if (!isset($request->files['image'])) {
            throw new HttpException('No image was received.', 422, [
                'image' => ['Attach the file as a multipart field named "image".'],
            ]);
        }

        return Response::success(
            $this->collections->setHeroImage(
                (string) $request->routeParam('slug'),
                $request->files['image'],
                $request
            ),
            'Collection image updated'
        );
    }

    public function setStatus(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'status' => 'required|in:draft,published,archived',
        ]);

        return Response::success($this->collections->setStatus(
            (string) $request->routeParam('slug'), (string) $data['status'], $request
        ));
    }

    public function addItem(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'product' => 'required|string|min:1|max:200',
            'headline' => 'nullable|string|max:120',
            'display_order' => 'nullable|int|min:1|max:9999',
        ]);

        return Response::success($this->collections->addItem(
            (string) $request->routeParam('slug'), $data, $request
        ));
    }

    public function removeItem(Request $request): Response
    {
        return Response::success($this->collections->removeItem(
            (string) $request->routeParam('slug'),
            (string) $request->routeParam('item'),
            $request
        ));
    }
}
