<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Database;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Helpers\Uuid;

/**
 * Reviews of the shop itself, left from the storefront home page. Nothing is
 * shown publicly until staff approve it — the form is open to anyone.
 */
final class StoreReviewController extends BaseController
{
    public function __construct(private readonly Database $db)
    {
    }

    /** GET /api/v1/store-reviews — the average and the latest published reviews. */
    public function index(Request $request): Response
    {
        $summary = $this->db->selectOne(
            "SELECT COUNT(*) AS `count`, COALESCE(AVG(`rating`), 0) AS `average`
               FROM `store_reviews` WHERE `status` = 'approved' AND `is_deleted` = 0"
        );

        $rows = $this->db->select(
            "SELECT `reviewer_name`, `rating`, `body`, `merchant_reply`, `created_date`
               FROM `store_reviews`
              WHERE `status` = 'approved' AND `is_deleted` = 0
              ORDER BY `created_date` DESC LIMIT 6"
        );

        return Response::success([
            'count' => (int) $summary['count'],
            'average' => round((float) $summary['average'], 1),
            'reviews' => array_map(static fn (array $r): array => [
                'name' => $r['reviewer_name'],
                'rating' => (int) $r['rating'],
                'body' => $r['body'],
                'reply' => $r['merchant_reply'],
                'date' => $r['created_date'],
            ], $rows),
        ], 'Store reviews loaded');
    }

    /** POST /api/v1/store-reviews */
    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'name' => 'required|string|min:2|max:120',
            'mobile' => 'nullable|mobile_in',
            'rating' => 'required|int|min:1|max:5',
            'body' => 'nullable|string|max:1000',
        ]);

        $this->db->insert(
            'INSERT INTO `store_reviews` (`uuid`, `reviewer_name`, `reviewer_mobile`, `rating`, `body`)
             VALUES (:uuid, :name, :mobile, :rating, :body)',
            [
                'uuid' => Uuid::v4(),
                'name' => $data['name'],
                'mobile' => $data['mobile'] ?? null,
                'rating' => (int) $data['rating'],
                'body' => ($data['body'] ?? '') === '' ? null : $data['body'],
            ]
        );

        return Response::created(null, 'Thank you! Your review will appear once it has been checked.');
    }

    /** GET /api/v1/admin/store-reviews?status= */
    public function queue(Request $request): Response
    {
        $status = (string) $request->query('status', '');
        $where = ['`is_deleted` = 0'];
        $bindings = [];

        if (in_array($status, ['pending', 'approved', 'rejected', 'hidden'], true)) {
            $where[] = '`status` = :status';
            $bindings['status'] = $status;
        } else {
            $where[] = "`status` = 'pending'";
        }

        $rows = $this->db->select(
            'SELECT `uuid`, `reviewer_name`, `reviewer_mobile`, `rating`, `body`, `status`,
                    `merchant_reply`, `moderation_note`, `created_date`
               FROM `store_reviews` WHERE ' . implode(' AND ', $where) . '
              ORDER BY `created_date` DESC LIMIT 100',
            $bindings
        );

        return Response::success($rows, 'Store reviews loaded');
    }

    /** POST /api/v1/admin/store-reviews/{uuid}/moderate */
    public function moderate(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'decision' => 'required|in:approved,rejected,hidden',
            'note' => 'nullable|string|max:500',
            'reply' => 'nullable|string|max:1000',
        ]);

        $review = $this->db->selectOne(
            'SELECT `id` FROM `store_reviews` WHERE `uuid` = :uuid AND `is_deleted` = 0',
            ['uuid' => (string) $request->routeParam('uuid')]
        );

        if ($review === null) {
            throw new NotFoundException('That review does not exist.');
        }

        $this->db->execute(
            'UPDATE `store_reviews`
                SET `status` = :status, `moderated_by` = :by, `moderated_date` = NOW(),
                    `moderation_note` = :note, `merchant_reply` = COALESCE(:reply, `merchant_reply`)
              WHERE `id` = :id',
            [
                'status' => $data['decision'],
                'by' => $request->authUserId(),
                'note' => $data['note'] ?? null,
                'reply' => ($data['reply'] ?? '') === '' ? null : $data['reply'],
                'id' => (int) $review['id'],
            ]
        );

        return Response::success(null, 'Review updated');
    }
}
