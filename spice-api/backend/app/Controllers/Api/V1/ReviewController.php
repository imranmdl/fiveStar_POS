<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Exceptions\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Services\ReviewService;

/** Product reviews: writing, reading and moderating. */
final class ReviewController extends BaseController
{
    public function __construct(private readonly ReviewService $reviews)
    {
    }

    /**
     * GET /api/v1/products/{identifier}/reviews
     *
     * Published reviews for a product, with a rating summary in meta.
     */
    public function index(Request $request): Response
    {
        $params = $this->paginationParams($request, 'created_date', 50);
        $rating = $request->query('rating');

        if (is_string($rating) && $rating !== '' && !in_array((int) $rating, [1, 2, 3, 4, 5], true)) {
            throw new HttpException('Rating filter must be between 1 and 5.', 422);
        }

        $result = $this->reviews->forProduct(
            (string) $request->routeParam('identifier'),
            $params,
            is_string($rating) && $rating !== '' ? (int) $rating : null
        );

        // The rating summary rides in the meta rather than the data array,
        // because the data array is the paginated list and a client mapping over
        // it should not have to skip a summary object.
        return Response::success($result['items'], 'Reviews loaded', 200, [
            'page' => $params['page'],
            'per_page' => $params['per_page'],
            'total' => $result['total'],
            'total_pages' => $params['per_page'] > 0
                ? (int) ceil($result['total'] / $params['per_page'])
                : 0,
            'summary' => $result['summary'],
        ]);
    }

    /**
     * POST /api/v1/products/{identifier}/reviews
     *
     * Write or replace a review. Requires a delivered order containing the product.
     */
    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'rating' => 'required|int|min:1|max:5',
            'title' => 'nullable|string|max:150',
            'body' => 'nullable|string|max:4000',
        ]);

        return Response::created(
            $this->reviews->submit($request, (string) $request->routeParam('identifier'), $data),
            'Review submitted'
        );
    }

    /** GET /api/v1/reviews/mine */
    public function mine(Request $request): Response
    {
        return Response::success(['reviews' => $this->reviews->mine($request)], 'Your reviews loaded');
    }

    /** GET /api/v1/reviews/awaiting */
    public function awaiting(Request $request): Response
    {
        return Response::success(
            ['products' => $this->reviews->awaitingReview($request)],
            'Products awaiting your review'
        );
    }

    /** POST /api/v1/reviews/{uuid}/report */
    public function report(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'reason' => 'required|in:spam,offensive,irrelevant,fake,other',
            'detail' => 'nullable|string|max:500',
        ]);

        return Response::success(
            $this->reviews->report($request, (string) $request->routeParam('uuid'), $data['reason'], $data['detail'] ?? null),
            'Thank you, our team will take a look'
        );
    }

    /** POST /api/v1/reviews/{uuid}/vote */
    public function vote(Request $request): Response
    {
        $data = Validator::make($request->all(), ['helpful' => 'required|boolean']);

        return Response::success(
            $this->reviews->vote($request, (string) $request->routeParam('uuid'), (bool) $data['helpful']),
            'Vote recorded'
        );
    }

    // -----------------------------------------------------------------------
    // Moderation
    // -----------------------------------------------------------------------

    /** GET /api/v1/admin/reviews */
    public function queue(Request $request): Response
    {
        $params = $this->paginationParams($request, 'created_date', 100);
        $status = $request->query('status');

        if (is_string($status) && $status !== ''
            && !in_array($status, ['pending', 'approved', 'rejected', 'hidden'], true)) {
            throw new HttpException('Unknown review status: ' . $status, 422);
        }

        $result = $this->reviews->moderationQueue(
            $params,
            is_string($status) && $status !== '' ? $status : null
        );

        return $this->paginated($result['items'], $result['total'], $params, 'Moderation queue loaded');
    }

    /** POST /api/v1/admin/reviews/{uuid}/moderate */
    public function moderate(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'decision' => 'required|in:approved,rejected,hidden',
            'note' => 'nullable|string|max:500',
        ]);

        return Response::success(
            $this->reviews->moderate($request, (string) $request->routeParam('uuid'), $data['decision'], $data['note'] ?? null),
            'Review moderated'
        );
    }

    /** POST /api/v1/admin/reviews/{uuid}/reply */
    public function reply(Request $request): Response
    {
        $data = Validator::make($request->all(), ['body' => 'required|string|min:2|max:1000']);

        return Response::success(
            $this->reviews->reply($request, (string) $request->routeParam('uuid'), $data['body']),
            'Reply published'
        );
    }
}
