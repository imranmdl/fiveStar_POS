<?php

declare(strict_types=1);

namespace App\Repositories;

final class AssignmentRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'order_assignments';
    }

    protected function fillable(): array
    {
        return [
            'order_id', 'assigned_to_user_id', 'assigned_by_user_id', 'status',
            'assignment_method', 'assigned_date', 'accepted_date', 'completed_date',
            'released_date', 'release_reason', 'due_date', 'notes',
        ];
    }

    /** @return array<string, mixed>|null */
    public function activeForOrder(int $orderId): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM `order_assignments`
              WHERE `order_id` = :order_id AND `status` IN ('assigned','accepted')
                AND `is_deleted` = 0
              LIMIT 1",
            ['order_id' => $orderId]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function historyForOrder(int $orderId): array
    {
        return $this->db->select(
            'SELECT a.*, u.`full_name` AS `assignee_name`, sp.`employee_code`
               FROM `order_assignments` a
               INNER JOIN `users` u ON u.`id` = a.`assigned_to_user_id`
               LEFT JOIN `staff_profiles` sp ON sp.`user_id` = a.`assigned_to_user_id`
              WHERE a.`order_id` = :order_id AND a.`is_deleted` = 0
              ORDER BY a.`id`',
            ['order_id' => $orderId]
        );
    }

    /**
     * An executive's live queue.
     *
     * Ordered by due date so the most urgent work is at the top — an executive
     * opening their queue should not have to scan for what is late.
     *
     * @return array<int, array<string, mixed>>
     */
    public function queueFor(int $userId, ?string $status = null): array
    {
        $sql = "SELECT a.*, o.`uuid` AS `order_uuid`, o.`order_number`, o.`status` AS `order_status`,
                       o.`payment_status`, o.`grand_total`, o.`ship_city`, o.`ship_pincode`,
                       o.`total_weight_grams`, o.`placed_date`,
                       (SELECT COUNT(*) FROM `order_items` oi
                         WHERE oi.`order_id` = o.`id` AND oi.`is_deleted` = 0) AS `item_count`
                  FROM `order_assignments` a
                  INNER JOIN `orders` o ON o.`id` = a.`order_id`
                 WHERE a.`assigned_to_user_id` = :user_id AND a.`is_deleted` = 0";

        $bindings = ['user_id' => $userId];

        if ($status !== null) {
            $sql .= ' AND a.`status` = :status';
            $bindings['status'] = $status;
        } else {
            $sql .= " AND a.`status` IN ('assigned','accepted')";
        }

        $sql .= ' ORDER BY a.`due_date` IS NULL, a.`due_date` ASC, a.`assigned_date` ASC';

        return $this->db->select($sql, $bindings);
    }

    /**
     * Confirmed, paid orders with nobody working on them.
     *
     * The supervisor's real dashboard: work that exists and is not moving.
     *
     * @return array<int, array<string, mixed>>
     */
    public function unassignedOrders(int $limit = 100): array
    {
        return $this->db->select(
            sprintf(
                "SELECT o.`id`, o.`uuid`, o.`order_number`, o.`status`, o.`grand_total`,
                        o.`ship_city`, o.`ship_pincode`, o.`placed_date`, o.`confirmed_date`
                   FROM `orders` o
                  WHERE o.`status` IN ('confirmed','packed')
                    AND o.`payment_status` IN ('paid','partially_refunded')
                    AND o.`is_deleted` = 0
                    AND NOT EXISTS (
                        SELECT 1 FROM `order_assignments` a
                         WHERE a.`order_id` = o.`id`
                           AND a.`status` IN ('assigned','accepted')
                           AND a.`is_deleted` = 0
                    )
                  ORDER BY o.`confirmed_date` ASC
                  LIMIT %d",
                max(1, min($limit, 500))
            )
        );
    }

    /**
     * Assignments past their due date and still open.
     *
     * @return array<int, array<string, mixed>>
     */
    public function overdue(?int $supervisorId = null): array
    {
        $sql = "SELECT a.*, o.`order_number`, u.`full_name` AS `assignee_name`,
                       TIMESTAMPDIFF(HOUR, a.`due_date`, NOW()) AS `hours_overdue`
                  FROM `order_assignments` a
                  INNER JOIN `orders` o ON o.`id` = a.`order_id`
                  INNER JOIN `users` u ON u.`id` = a.`assigned_to_user_id`
                  LEFT JOIN `staff_profiles` sp ON sp.`user_id` = a.`assigned_to_user_id`
                 WHERE a.`status` IN ('assigned','accepted')
                   AND a.`due_date` IS NOT NULL
                   AND a.`due_date` < NOW()
                   AND a.`is_deleted` = 0";

        $bindings = [];

        if ($supervisorId !== null) {
            $sql .= ' AND sp.`reports_to_user_id` = :supervisor_id';
            $bindings['supervisor_id'] = $supervisorId;
        }

        $sql .= ' ORDER BY a.`due_date` ASC';

        return $this->db->select($sql, $bindings);
    }
}
