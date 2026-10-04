<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Logger;
use App\Core\Request;
use App\Helpers\Uuid;
use App\Repositories\SettingRepository;

/**
 * Product reviews: writing, moderating and aggregating them.
 *
 * A REVIEW REQUIRES A DELIVERED ORDER CONTAINING THAT PRODUCT. Not an account,
 * not a purchase — a delivery. It is the most effective defence against fake
 * reviews available to a small merchant, it costs nothing here because the
 * order data is already present, and it is the difference between a rating that
 * means something and one that means whoever cared most. The rule is a setting
 * so a merchant can relax it, but it defaults to on.
 *
 * RATINGS ARE RECOMPUTED, NOT INCREMENTED. Keeping a running average by adding
 * each new star is faster and drifts: one missed decrement on a deleted review
 * and the number is quietly wrong forever with no way to detect it. Recomputing
 * from the approved rows costs one indexed aggregate and is always right.
 */
final class ReviewService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly SettingRepository $settings,
        private readonly AuditService $audit,
        private readonly Database $db,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Writes or replaces the caller's review of a product.
     *
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function submit(Request $request, string $productUuid, array $data): array
    {
        $userId = (int) $request->authUserId();
        $product = $this->requireProduct($productUuid);

        $purchase = $this->findQualifyingPurchase($userId, (int) $product['id']);

        if ($purchase === null && $this->settings->boolValue('reviews_require_purchase', true)) {
            throw new HttpException(
                'You can review this product once an order containing it has been delivered to you.',
                403,
                ['purchase' => ['No delivered order for this product was found on your account.']]
            );
        }

        $existing = $this->db->selectOne(
            'SELECT * FROM `product_reviews`
              WHERE `product_id` = :product_id AND `user_id` = :user_id AND `is_deleted` = 0
              LIMIT 1',
            ['product_id' => (int) $product['id'], 'user_id' => $userId]
        );

        $autoApprove = $this->settings->boolValue('reviews_auto_approve', false);
        $status = $autoApprove ? 'approved' : 'pending';

        if ($existing !== null) {
            $windowDays = max(1, $this->settings->intValue('reviews_edit_window_days', 30));
            $age = (time() - strtotime((string) $existing['created_date'])) / 86400;

            if ($age > $windowDays) {
                throw new HttpException(
                    sprintf('Reviews can be edited for %d days after posting.', $windowDays),
                    409
                );
            }

            $this->db->transaction(function () use ($existing, $data, $status, $userId): void {
                $this->db->execute(
                    'UPDATE `product_reviews`
                        SET `rating` = :rating, `title` = :title, `body` = :body,
                            `status` = :status, `moderated_by` = NULL, `moderated_date` = NULL,
                            `updated_by` = :actor, `updated_date` = NOW(), `version` = `version` + 1
                      WHERE `id` = :id',
                    [
                        'rating' => (int) $data['rating'],
                        'title' => $data['title'] ?? null,
                        'body' => $data['body'] ?? null,
                        // An edit returns to moderation. Otherwise an approved
                        // review is an open door: post something innocuous, wait
                        // for approval, then edit it to anything at all.
                        'status' => $status,
                        'actor' => $userId,
                        'id' => (int) $existing['id'],
                    ]
                );

                $this->recomputeProductRating((int) $existing['product_id']);
            });

            return [
                'review' => $this->present((array) $this->db->selectOne(
                    'SELECT * FROM `product_reviews` WHERE `id` = :id',
                    ['id' => (int) $existing['id']]
                ), true),
                'replaced' => true,
                'awaiting_moderation' => $status === 'pending',
            ];
        }

        $reviewId = $this->db->transaction(function () use ($product, $purchase, $userId, $data, $status): int {
            $id = (int) $this->db->insert(
                'INSERT INTO `product_reviews`
                     (`uuid`, `product_id`, `variant_id`, `user_id`, `order_id`, `rating`,
                      `title`, `body`, `is_verified_purchase`, `status`,
                      `created_by`, `created_date`, `is_active`, `is_deleted`, `version`)
                 VALUES
                     (:uuid, :product_id, :variant_id, :user_id, :order_id, :rating,
                      :title, :body, :verified, :status, :created_by, NOW(), 1, 0, 1)',
                [
                    'uuid' => Uuid::v4(),
                    'product_id' => (int) $product['id'],
                    'variant_id' => $purchase === null ? null : (int) $purchase['variant_id'],
                    'user_id' => $userId,
                    'order_id' => $purchase === null ? null : (int) $purchase['order_id'],
                    'rating' => (int) $data['rating'],
                    'title' => $data['title'] ?? null,
                    'body' => $data['body'] ?? null,
                    'verified' => $purchase === null ? 0 : 1,
                    'status' => $status,
                    'created_by' => $userId,
                ]
            );

            $this->recomputeProductRating((int) $product['id']);

            return $id;
        });

        return [
            'review' => $this->present((array) $this->db->selectOne(
                'SELECT * FROM `product_reviews` WHERE `id` = :id',
                ['id' => $reviewId]
            ), true),
            'replaced' => false,
            'awaiting_moderation' => $status === 'pending',
        ];
    }

    /**
     * Public reviews for a product.
     *
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int, summary:array<string, mixed>}
     */
    public function forProduct(string $productUuid, array $params, ?int $ratingFilter = null): array
    {
        $product = $this->requireProduct($productUuid);

        $where = ["r.`status` = 'approved'", 'r.`is_deleted` = 0', 'r.`product_id` = :product_id'];
        $bindings = ['product_id' => (int) $product['id']];

        if ($ratingFilter !== null) {
            $where[] = 'r.`rating` = :rating';
            $bindings['rating'] = $ratingFilter;
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM `product_reviews` r WHERE {$whereSql}", $bindings);

        $items = $total === 0 ? [] : $this->db->select(
            sprintf(
                'SELECT r.*, u.`full_name`
                   FROM `product_reviews` r
                   INNER JOIN `users` u ON u.`id` = r.`user_id`
                  WHERE %s
                  ORDER BY r.`is_verified_purchase` DESC, r.`helpful_count` DESC, r.`created_date` DESC
                  LIMIT %d OFFSET %d',
                $whereSql,
                $params['per_page'],
                $params['offset']
            ),
            $bindings
        );

        $summary = $this->db->selectOne(
            'SELECT * FROM `vw_product_ratings` WHERE `product_id` = :product_id',
            ['product_id' => (int) $product['id']]
        );

        return [
            'items' => array_map(fn (array $row): array => $this->present($row, false), $items),
            'total' => $total,
            'summary' => [
                'rating_average' => (float) ($summary['rating_average'] ?? 0),
                'review_count' => (int) ($summary['review_count'] ?? 0),
                'verified_count' => (int) ($summary['verified_count'] ?? 0),
                'distribution' => [
                    5 => (int) ($summary['five_star'] ?? 0),
                    4 => (int) ($summary['four_star'] ?? 0),
                    3 => (int) ($summary['three_star'] ?? 0),
                    2 => (int) ($summary['two_star'] ?? 0),
                    1 => (int) ($summary['one_star'] ?? 0),
                ],
            ],
        ];
    }

    /**
     * Moderator decision.
     *
     * @return array<string, mixed>
     */
    public function moderate(Request $request, string $reviewUuid, string $decision, ?string $note): array
    {
        if (!in_array($decision, ['approved', 'rejected', 'hidden'], true)) {
            throw new HttpException('Unknown moderation decision: ' . $decision, 422);
        }

        $review = $this->requireReview($reviewUuid);

        $this->db->transaction(function () use ($review, $decision, $note, $request): void {
            $this->db->execute(
                'UPDATE `product_reviews`
                    SET `status` = :status, `moderated_by` = :moderator, `moderated_date` = NOW(),
                        `moderation_note` = :note, `updated_by` = :actor,
                        `updated_date` = NOW(), `version` = `version` + 1
                  WHERE `id` = :id',
                [
                    'status' => $decision,
                    'moderator' => $request->authUserId(),
                    'note' => $note,
                    'actor' => $request->authUserId(),
                    'id' => (int) $review['id'],
                ]
            );

            // Media inherits a rejection but not an approval: a picture needs
            // looking at on its own terms.
            if ($decision !== 'approved') {
                $this->db->execute(
                    "UPDATE `review_media` SET `status` = 'rejected', `updated_date` = NOW(),
                            `version` = `version` + 1
                      WHERE `review_id` = :id",
                    ['id' => (int) $review['id']]
                );
            }

            $this->recomputeProductRating((int) $review['product_id']);
        });

        if ($decision === 'approved') {
            $product = $this->db->selectOne(
                'SELECT `name` FROM `products` WHERE `id` = :id',
                ['id' => (int) $review['product_id']]
            );

            $this->notifications->queue(
                'review.approved',
                'sms',
                ['product_name' => (string) ($product['name'] ?? 'your purchase')],
                [
                    'user_id' => (int) $review['user_id'],
                    'reference_type' => 'product_reviews',
                    'reference_id' => (string) $review['uuid'],
                    'dedupe_key' => 'review.approved:' . $review['uuid'],
                ]
            );
        }

        $this->audit->log(
            entityName: 'product_reviews',
            entityId: (int) $review['id'],
            action: 'moderate',
            oldValues: ['status' => $review['status']],
            newValues: ['status' => $decision, 'note' => $note],
            request: $request,
            entityUuid: $reviewUuid,
        );

        return ['review' => $this->present((array) $this->db->selectOne(
            'SELECT * FROM `product_reviews` WHERE `id` = :id',
            ['id' => (int) $review['id']]
        ), true)];
    }

    /**
     * Merchant's public reply to a review.
     *
     * @return array<string, mixed>
     */
    public function reply(Request $request, string $reviewUuid, string $body): array
    {
        $review = $this->requireReview($reviewUuid);

        if ($review['status'] !== 'approved') {
            throw new HttpException('Only a published review can be replied to.', 409);
        }

        $this->db->execute(
            'UPDATE `product_reviews`
                SET `merchant_reply` = :body, `merchant_reply_date` = NOW(),
                    `merchant_reply_by` = :actor, `updated_date` = NOW(), `version` = `version` + 1
              WHERE `id` = :id',
            ['body' => $body, 'actor' => $request->authUserId(), 'id' => (int) $review['id']]
        );

        return ['replied' => true];
    }

    /**
     * Reports a review for abuse.
     *
     * @return array<string, mixed>
     */
    public function report(Request $request, string $reviewUuid, string $reason, ?string $detail): array
    {
        $review = $this->requireReview($reviewUuid);
        $userId = (int) $request->authUserId();

        if ((int) $review['user_id'] === $userId) {
            throw new HttpException('You cannot report your own review.', 422);
        }

        try {
            $this->db->insert(
                'INSERT INTO `review_reports`
                     (`uuid`, `review_id`, `user_id`, `reason`, `detail`, `status`,
                      `created_by`, `created_date`, `is_active`, `is_deleted`, `version`)
                 VALUES (:uuid, :review_id, :user_id, :reason, :detail, \'open\',
                         :created_by, NOW(), 1, 0, 1)',
                [
                    'uuid' => Uuid::v4(),
                    'review_id' => (int) $review['id'],
                    'user_id' => $userId,
                    'reason' => $reason,
                    'detail' => $detail,
                    'created_by' => $userId,
                ]
            );
        } catch (\PDOException $exception) {
            if ($exception->getCode() === '23000') {
                // One report per person per review. A single determined
                // complainant must not be able to bury a review by refreshing.
                return ['reported' => true, 'already_reported' => true, 'hidden' => false];
            }

            throw $exception;
        }

        $count = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM `review_reports` WHERE `review_id` = :id AND `status` = 'open'",
            ['id' => (int) $review['id']]
        );

        $threshold = max(1, $this->settings->intValue('reviews_auto_hide_reports', 3));
        $hidden = false;

        if ($count >= $threshold && $review['status'] === 'approved') {
            // Hidden pending review, not deleted. A moderator decides; the
            // crowd only flags.
            $this->db->execute(
                "UPDATE `product_reviews`
                    SET `status` = 'hidden', `report_count` = :count,
                        `updated_date` = NOW(), `version` = `version` + 1
                  WHERE `id` = :id",
                ['count' => $count, 'id' => (int) $review['id']]
            );

            $this->recomputeProductRating((int) $review['product_id']);
            $hidden = true;

            $this->logger->info('Review auto-hidden after reports', [
                'review_uuid' => $review['uuid'],
                'reports' => $count,
            ], 'reviews');
        } else {
            $this->db->execute(
                'UPDATE `product_reviews` SET `report_count` = :count,
                        `updated_date` = NOW(), `version` = `version` + 1
                  WHERE `id` = :id',
                ['count' => $count, 'id' => (int) $review['id']]
            );
        }

        return ['reported' => true, 'already_reported' => false, 'hidden' => $hidden];
    }

    /**
     * Marks a review helpful or not.
     *
     * @return array<string, mixed>
     */
    public function vote(Request $request, string $reviewUuid, bool $helpful): array
    {
        $review = $this->requireReview($reviewUuid);
        $userId = (int) $request->authUserId();

        if ((int) $review['user_id'] === $userId) {
            throw new HttpException('You cannot vote on your own review.', 422);
        }

        $this->db->execute(
            'INSERT INTO `review_votes`
                 (`uuid`, `review_id`, `user_id`, `is_helpful`, `created_by`, `created_date`,
                  `is_active`, `is_deleted`, `version`)
             VALUES (:uuid, :review_id, :user_id, :helpful, :created_by, NOW(), 1, 0, 1)
             ON DUPLICATE KEY UPDATE
                 `is_helpful` = VALUES(`is_helpful`),
                 `updated_date` = NOW(),
                 `version` = `review_votes`.`version` + 1',
            [
                'uuid' => Uuid::v4(),
                'review_id' => (int) $review['id'],
                'user_id' => $userId,
                'helpful' => $helpful ? 1 : 0,
                'created_by' => $userId,
            ]
        );

        // Recounted rather than incremented, for the same reason ratings are:
        // a vote that changes from helpful to not must not leave both counters
        // one too high.
        $this->db->execute(
            'UPDATE `product_reviews` r
                SET r.`helpful_count` = (
                        SELECT COUNT(*) FROM `review_votes` v
                         WHERE v.`review_id` = r.`id` AND v.`is_helpful` = 1 AND v.`is_deleted` = 0
                    ),
                    r.`not_helpful_count` = (
                        SELECT COUNT(*) FROM `review_votes` v
                         WHERE v.`review_id` = r.`id` AND v.`is_helpful` = 0 AND v.`is_deleted` = 0
                    ),
                    r.`updated_date` = NOW(), r.`version` = r.`version` + 1
              WHERE r.`id` = :id',
            ['id' => (int) $review['id']]
        );

        $fresh = (array) $this->db->selectOne(
            'SELECT `helpful_count`, `not_helpful_count` FROM `product_reviews` WHERE `id` = :id',
            ['id' => (int) $review['id']]
        );

        return [
            'helpful_count' => (int) $fresh['helpful_count'],
            'not_helpful_count' => (int) $fresh['not_helpful_count'],
        ];
    }

    /**
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function moderationQueue(array $params, ?string $status = null): array
    {
        $where = ['r.`is_deleted` = 0'];
        $bindings = [];

        if ($status !== null) {
            $where[] = 'r.`status` = :status';
            $bindings['status'] = $status;
        } else {
            // The default queue is what needs a decision: unmoderated reviews
            // and anything the crowd has flagged.
            $where[] = "(r.`status` = 'pending' OR (r.`status` = 'hidden' AND r.`report_count` > 0))";
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM `product_reviews` r WHERE {$whereSql}", $bindings);

        $items = $total === 0 ? [] : $this->db->select(
            sprintf(
                'SELECT r.*, u.`full_name`, p.`name` AS `product_name`
                   FROM `product_reviews` r
                   INNER JOIN `users` u ON u.`id` = r.`user_id`
                   INNER JOIN `products` p ON p.`id` = r.`product_id`
                  WHERE %s
                  ORDER BY r.`report_count` DESC, r.`created_date` ASC
                  LIMIT %d OFFSET %d',
                $whereSql,
                $params['per_page'],
                $params['offset']
            ),
            $bindings
        );

        return [
            'items' => array_map(function (array $row): array {
                $view = $this->present($row, true);
                $view['product_name'] = $row['product_name'];
                $view['report_count'] = (int) $row['report_count'];

                return $view;
            }, $items),
            'total' => $total,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function mine(Request $request): array
    {
        $rows = $this->db->select(
            'SELECT r.*, p.`name` AS `product_name`, p.`slug` AS `product_slug`
               FROM `product_reviews` r
               INNER JOIN `products` p ON p.`id` = r.`product_id`
              WHERE r.`user_id` = :user_id AND r.`is_deleted` = 0
              ORDER BY r.`created_date` DESC',
            ['user_id' => (int) $request->authUserId()]
        );

        return array_map(function (array $row): array {
            $view = $this->present($row, true);
            $view['product_name'] = $row['product_name'];
            $view['product_slug'] = $row['product_slug'];

            return $view;
        }, $rows);
    }

    /**
     * Products the caller has received but not yet reviewed.
     *
     * Far more effective than a generic "leave a review" prompt: it asks about
     * a specific thing the customer actually has.
     *
     * @return array<int, array<string, mixed>>
     */
    public function awaitingReview(Request $request): array
    {
        return $this->db->select(
            "SELECT DISTINCT p.`uuid`, p.`name`, p.`slug`, o.`order_number`, o.`delivered_date`
               FROM `orders` o
               INNER JOIN `order_items` oi ON oi.`order_id` = o.`id` AND oi.`is_deleted` = 0
               INNER JOIN `products` p ON p.`id` = oi.`product_id`
              WHERE o.`user_id` = :user_id
                AND o.`status` = 'delivered'
                AND o.`is_deleted` = 0
                AND p.`is_deleted` = 0
                AND NOT EXISTS (
                    SELECT 1 FROM `product_reviews` r
                     WHERE r.`product_id` = p.`id` AND r.`user_id` = :user_id_check
                       AND r.`is_deleted` = 0
                )
              ORDER BY o.`delivered_date` DESC
              LIMIT 20",
            ['user_id' => (int) $request->authUserId(), 'user_id_check' => (int) $request->authUserId()]
        );
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    /**
     * The delivered order line that entitles this customer to review.
     *
     * @return array<string, mixed>|null
     */
    private function findQualifyingPurchase(int $userId, int $productId): ?array
    {
        return $this->db->selectOne(
            "SELECT oi.`order_id`, oi.`variant_id`
               FROM `order_items` oi
               INNER JOIN `orders` o ON o.`id` = oi.`order_id`
              WHERE o.`user_id` = :user_id
                AND oi.`product_id` = :product_id
                AND o.`status` = 'delivered'
                AND o.`is_deleted` = 0
                AND oi.`is_deleted` = 0
              ORDER BY o.`delivered_date` DESC
              LIMIT 1",
            ['user_id' => $userId, 'product_id' => $productId]
        );
    }

    /**
     * Recomputes a product's rating from its approved reviews.
     *
     * One aggregate over an indexed column. Deliberately not incremental: a
     * running total drifts the first time a decrement is missed, and nothing
     * ever detects it.
     */
    private function recomputeProductRating(int $productId): void
    {
        $this->db->execute(
            "UPDATE `products` p
                SET p.`rating_average` = COALESCE((
                        SELECT ROUND(AVG(r.`rating`), 2) FROM `product_reviews` r
                         WHERE r.`product_id` = p.`id` AND r.`status` = 'approved' AND r.`is_deleted` = 0
                    ), 0),
                    p.`rating_count` = (
                        SELECT COUNT(*) FROM `product_reviews` r
                         WHERE r.`product_id` = p.`id` AND r.`status` = 'approved' AND r.`is_deleted` = 0
                    ),
                    p.`review_count` = (
                        SELECT COUNT(*) FROM `product_reviews` r
                         WHERE r.`product_id` = p.`id` AND r.`status` = 'approved'
                           AND r.`is_deleted` = 0 AND (r.`body` IS NOT NULL AND r.`body` <> '')
                    ),
                    p.`updated_date` = NOW(), p.`version` = p.`version` + 1
              WHERE p.`id` = :id",
            ['id' => $productId]
        );
    }

    /** @return array<string, mixed> */
    private function requireProduct(string $uuid): array
    {
        $product = $this->db->selectOne(
            'SELECT * FROM `products` WHERE (`uuid` = :uuid OR `slug` = :slug) AND `is_deleted` = 0 LIMIT 1',
            ['uuid' => $uuid, 'slug' => $uuid]
        );

        if ($product === null) {
            throw new NotFoundException('That product does not exist.');
        }

        return $product;
    }

    /** @return array<string, mixed> */
    private function requireReview(string $uuid): array
    {
        $review = $this->db->selectOne(
            'SELECT * FROM `product_reviews` WHERE `uuid` = :uuid AND `is_deleted` = 0',
            ['uuid' => $uuid]
        );

        if ($review === null) {
            throw new NotFoundException('That review does not exist.');
        }

        return $review;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function present(array $row, bool $includeStatus): array
    {
        $view = [
            'uuid' => $row['uuid'],
            'rating' => (int) $row['rating'],
            'title' => $row['title'],
            'body' => $row['body'],
            'is_verified_purchase' => (bool) $row['is_verified_purchase'],
            'helpful_count' => (int) $row['helpful_count'],
            'not_helpful_count' => (int) $row['not_helpful_count'],
            'merchant_reply' => $row['merchant_reply'],
            'merchant_reply_date' => $row['merchant_reply_date'],
            'created_date' => $row['created_date'],
        ];

        if (isset($row['full_name'])) {
            // First name and an initial. A public review should not carry a
            // customer's full legal name alongside a list of what they bought.
            $parts = preg_split('/\s+/', trim((string) $row['full_name'])) ?: [];
            $view['author'] = $parts === []
                ? 'Customer'
                : $parts[0] . (count($parts) > 1 ? ' ' . strtoupper(substr((string) end($parts), 0, 1)) . '.' : '');
        }

        if ($includeStatus) {
            $view['status'] = $row['status'];
            $view['moderation_note'] = $row['moderation_note'];
        }

        return $view;
    }
}
