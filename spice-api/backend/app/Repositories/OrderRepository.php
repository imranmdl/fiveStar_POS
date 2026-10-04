<?php

declare(strict_types=1);

namespace App\Repositories;

final class OrderRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'orders';
    }

    protected function fillable(): array
    {
        return [
            'order_number', 'user_id', 'cart_id', 'status', 'payment_status', 'currency_code',
            'payment_method', 'cod_approved_by', 'cod_approved_date', 'cod_declined_reason',
            'items_mrp_total', 'items_subtotal', 'product_discount', 'order_discount',
            'order_surcharge', 'delivery_charge', 'delivery_charge_before_waiver',
            'delivery_discount', 'taxable_value', 'tax_total', 'grand_total',
            'wallet_applied', 'amount_payable', 'amount_paid', 'amount_refunded', 'total_savings',
            'coupon_id', 'coupon_code', 'coupon_discount', 'offer_id', 'offer_code', 'offer_discount',
            'ship_name', 'ship_mobile', 'ship_alternate_mobile', 'ship_address_line1',
            'ship_address_line2', 'ship_landmark', 'ship_city', 'ship_state', 'ship_pincode',
            'ship_country', 'source_address_id',
            'delivery_zone_code', 'delivery_sla_min_days', 'delivery_sla_max_days',
            'expected_delivery_date', 'delivery_slot', 'delivery_instructions', 'total_weight_grams',
            'courier_code', 'courier_name', 'tracking_number', 'tracking_url', 'shipped_date',
            'is_gift', 'gift_message', 'otp_verified', 'otp_verified_date',
            'invoice_number', 'invoice_date', 'invoice_financial_year',
            'placed_date', 'confirmed_date', 'delivered_date', 'cancelled_date', 'cancelled_by',
            'cancellation_reason', 'expires_date', 'customer_note', 'internal_note',
            'placed_ip', 'placed_channel',
        ];
    }

    protected function sortable(): array
    {
        return ['id', 'order_number', 'status', 'payment_status', 'grand_total', 'created_date', 'placed_date'];
    }

    /** @return array<string, mixed>|null */
    public function findByNumber(string $orderNumber): ?array
    {
        return $this->findOneBy('order_number', strtoupper(trim($orderNumber)));
    }

    /**
     * COD orders an administrator has not yet approved or declined, oldest
     * first. Mirrors PaymentRepository::pendingManualVerification() — same
     * "queue of things one human has to look at" shape.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pendingCodApproval(int $limit = 100, int $offset = 0): array
    {
        return $this->db->select(
            sprintf(
                "SELECT * FROM `orders`
                  WHERE `payment_method` = 'cod'
                    AND `status` = 'awaiting_payment'
                    AND `cod_approved_date` IS NULL
                    AND `cod_declined_reason` IS NULL
                    AND `is_deleted` = 0
                  ORDER BY `placed_date` ASC
                  LIMIT %d OFFSET %d",
                max(1, $limit),
                max(0, $offset)
            )
        );
    }

    public function countPendingCodApproval(): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM `orders`
              WHERE `payment_method` = 'cod' AND `status` = 'awaiting_payment'
                AND `cod_approved_date` IS NULL AND `cod_declined_reason` IS NULL
                AND `is_deleted` = 0"
        );
    }

    /**
     * Locks an order row for the rest of the current transaction.
     *
     * Every status change goes through this. Payment webhooks, customer
     * cancellations and staff actions can all arrive at the same moment, and
     * without the lock two of them could each read `confirmed` and both act.
     *
     * @return array<string, mixed>|null
     */
    public function lockForUpdate(int $orderId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM `orders` WHERE `id` = :id AND `is_deleted` = 0 FOR UPDATE',
            ['id' => $orderId]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function itemsFor(int $orderId): array
    {
        return $this->db->select(
            'SELECT * FROM `order_items` WHERE `order_id` = :order_id AND `is_deleted` = 0 ORDER BY `id`',
            ['order_id' => $orderId]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function taxLinesFor(int $orderId): array
    {
        return $this->db->select(
            'SELECT * FROM `order_tax_lines` WHERE `order_id` = :order_id AND `is_deleted` = 0
              ORDER BY `gst_rate`',
            ['order_id' => $orderId]
        );
    }

    /**
     * @param bool $customerVisibleOnly Keeps internal operational notes off the
     *                                  customer's tracking page.
     *
     * @return array<int, array<string, mixed>>
     */
    public function timelineFor(int $orderId, bool $customerVisibleOnly = false): array
    {
        $sql = 'SELECT `uuid`, `from_status`, `to_status`, `payment_status`, `title`, `note`,
                       `is_customer_visible`, `changed_by_role`, `created_date`
                  FROM `order_status_history`
                 WHERE `order_id` = :order_id AND `is_deleted` = 0';

        if ($customerVisibleOnly) {
            $sql .= ' AND `is_customer_visible` = 1';
        }

        $sql .= ' ORDER BY `id` ASC';

        return $this->db->select($sql, ['order_id' => $orderId]);
    }

    public function appendTimeline(
        int $orderId,
        ?string $fromStatus,
        string $toStatus,
        string $title,
        ?string $paymentStatus = null,
        ?string $note = null,
        bool $customerVisible = true,
        ?int $changedBy = null,
        ?string $changedByRole = null,
    ): void {
        $this->db->insert(
            'INSERT INTO `order_status_history`
                 (`uuid`, `order_id`, `from_status`, `to_status`, `payment_status`, `title`, `note`,
                  `is_customer_visible`, `changed_by`, `changed_by_role`,
                  `created_by`, `created_date`, `is_active`, `is_deleted`, `version`)
             VALUES
                 (:uuid, :order_id, :from_status, :to_status, :payment_status, :title, :note,
                  :visible, :changed_by, :changed_by_role, :created_by, NOW(), 1, 0, 1)',
            [
                'uuid' => \App\Helpers\Uuid::v4(),
                'order_id' => $orderId,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'payment_status' => $paymentStatus,
                'title' => $title,
                'note' => $note,
                'visible' => $customerVisible ? 1 : 0,
                'changed_by' => $changedBy,
                'changed_by_role' => $changedByRole,
                'created_by' => $changedBy,
            ]
        );
    }

    /**
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function paginateForCustomer(int $userId, array $params, ?string $status = null): array
    {
        $where = ['o.`user_id` = :user_id', 'o.`is_deleted` = 0'];
        $bindings = ['user_id' => $userId];

        if ($status !== null) {
            $where[] = 'o.`status` = :status';
            $bindings['status'] = $status;
        }

        $whereSql = implode(' AND ', $where);

        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM `orders` o WHERE {$whereSql}", $bindings);

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $items = $this->db->select(
            sprintf(
                'SELECT o.* FROM `orders` o WHERE %s ORDER BY o.`created_date` %s LIMIT %d OFFSET %d',
                $whereSql,
                $params['direction'],
                $params['per_page'],
                $params['offset']
            ),
            $bindings
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string, search:?string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function paginateForStaff(array $params, ?string $status = null, ?string $paymentStatus = null): array
    {
        $where = ['o.`is_deleted` = 0'];
        $bindings = [];

        if ($status !== null) {
            $where[] = 'o.`status` = :status';
            $bindings['status'] = $status;
        }

        if ($paymentStatus !== null) {
            $where[] = 'o.`payment_status` = :payment_status';
            $bindings['payment_status'] = $paymentStatus;
        }

        if (($params['search'] ?? null) !== null) {
            $where[] = '(o.`order_number` LIKE :search_number OR o.`ship_mobile` LIKE :search_mobile
                         OR o.`ship_pincode` = :search_pincode OR o.`tracking_number` LIKE :search_tracking)';
            $bindings['search_number'] = '%' . $params['search'] . '%';
            $bindings['search_mobile'] = '%' . $params['search'] . '%';
            $bindings['search_pincode'] = $params['search'];
            $bindings['search_tracking'] = '%' . $params['search'] . '%';
        }

        $whereSql = implode(' AND ', $where);
        $sort = in_array($params['sort'], $this->sortable(), true) ? $params['sort'] : 'created_date';

        $total = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM `orders` o WHERE {$whereSql}",
            $bindings
        );

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $items = $this->db->select(
            sprintf(
                'SELECT s.* FROM `vw_order_summary` s WHERE s.`id` IN
                     (SELECT o.`id` FROM `orders` o WHERE %s)
                  ORDER BY s.`%s` %s LIMIT %d OFFSET %d',
                $whereSql,
                $sort,
                $params['direction'],
                $params['per_page'],
                $params['offset']
            ),
            $bindings
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Unpaid orders whose payment window has closed.
     *
     * These have to be released, not just ignored: each one is holding a coupon
     * use and a wallet debit that belong to the customer.
     *
     * @return array<int, array<string, mixed>>
     */
    public function expiredUnpaidOrders(int $limit = 200): array
    {
        return $this->db->select(
            sprintf(
                "SELECT * FROM `orders`
                  WHERE `status` IN ('created','awaiting_payment')
                    AND `payment_status` IN ('pending','processing','failed')
                    AND `expires_date` IS NOT NULL
                    AND `expires_date` < NOW()
                    AND `is_deleted` = 0
                  ORDER BY `expires_date` ASC
                  LIMIT %d",
                max(1, min($limit, 1000))
            )
        );
    }

    public function countForUser(int $userId, bool $paidOnly = true): int
    {
        $sql = 'SELECT COUNT(*) FROM `orders` WHERE `user_id` = :user_id AND `is_deleted` = 0';

        if ($paidOnly) {
            $sql .= " AND `payment_status` IN ('paid','partially_refunded')";
        }

        return (int) $this->db->scalar($sql, ['user_id' => $userId]);
    }

    /** @return array<string, mixed>|null */
    public function summaryFor(int $orderId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM `vw_order_summary` WHERE `id` = :id LIMIT 1',
            ['id' => $orderId]
        );
    }
}
