<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Helpers\Uuid;

final class ShipmentRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'shipments';
    }

    protected function fillable(): array
    {
        return [
            'shipment_number', 'order_id', 'courier_id', 'awb_number', 'courier_shipment_id',
            'label_url', 'label_generated_date', 'manifest_id', 'pickup_request_id', 'status',
            'actual_weight_grams', 'volumetric_weight_grams', 'chargeable_weight_grams',
            'length_mm', 'width_mm', 'height_mm', 'used_default_dimensions',
            'declared_value', 'courier_charge', 'customer_paid_delivery',
            'promised_sla_min_days', 'promised_sla_max_days', 'estimated_delivery_date',
            'pickup_scheduled_date', 'picked_up_date', 'delivered_date', 'delivery_attempts',
            'last_scan_date', 'last_scan_location', 'last_scan_status',
            'rto_reason', 'failure_reason', 'booking_response',
        ];
    }

    protected function sortable(): array
    {
        return ['id', 'shipment_number', 'status', 'created_date', 'estimated_delivery_date'];
    }

    /** @return array<int, array<string, mixed>> */
    public function forOrder(int $orderId): array
    {
        return $this->db->select(
            'SELECT * FROM `shipments` WHERE `order_id` = :order_id AND `is_deleted` = 0 ORDER BY `id`',
            ['order_id' => $orderId]
        );
    }

    /** @return array<string, mixed>|null */
    public function findByAwb(int $courierId, string $awb): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM `shipments`
              WHERE `courier_id` = :courier_id AND `awb_number` = :awb AND `is_deleted` = 0
              LIMIT 1',
            ['courier_id' => $courierId, 'awb' => $awb]
        );
    }

    /**
     * Finds a shipment by AWB without knowing the courier.
     *
     * Webhooks do not always say which courier they came from, and AWBs are
     * only unique per courier. When two couriers have issued the same digits
     * this returns the most recent, which is the best available guess — the
     * ambiguity is real and worth knowing about rather than hiding.
     *
     * @return array<string, mixed>|null
     */
    public function findByAwbAnyCourier(string $awb): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM `shipments` WHERE `awb_number` = :awb AND `is_deleted` = 0
              ORDER BY `id` DESC LIMIT 1',
            ['awb' => $awb]
        );
    }

    /** @return array<string, mixed>|null */
    public function lockForUpdate(int $shipmentId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM `shipments` WHERE `id` = :id AND `is_deleted` = 0 FOR UPDATE',
            ['id' => $shipmentId]
        );
    }

    /**
     * Appends a tracking scan.
     *
     * Returns false when the scan has been seen before. Polling and webhooks
     * overlap constantly, so duplicate scans are the normal case, not an error.
     *
     * @param array<string, mixed> $raw
     */
    public function appendEvent(
        int $shipmentId,
        string $status,
        string $title,
        ?string $description,
        ?string $location,
        string $occurredAt,
        ?string $courierEventId,
        ?string $eventCode,
        string $source,
        array $raw,
        bool $customerVisible = true,
    ): bool {
        try {
            $this->db->insert(
                'INSERT INTO `shipment_events`
                     (`uuid`, `shipment_id`, `courier_event_id`, `event_code`, `status`, `title`,
                      `description`, `location`, `occurred_date`, `is_customer_visible`, `source`,
                      `raw_payload`, `created_date`, `is_active`, `is_deleted`, `version`)
                 VALUES
                     (:uuid, :shipment_id, :courier_event_id, :event_code, :status, :title,
                      :description, :location, :occurred_date, :visible, :source,
                      :raw_payload, NOW(), 1, 0, 1)',
                [
                    'uuid' => Uuid::v4(),
                    'shipment_id' => $shipmentId,
                    'courier_event_id' => $courierEventId,
                    'event_code' => $eventCode,
                    'status' => $status,
                    'title' => $title,
                    'description' => $description,
                    'location' => $location,
                    'occurred_date' => $occurredAt,
                    'visible' => $customerVisible ? 1 : 0,
                    'source' => $source,
                    'raw_payload' => json_encode($raw),
                ]
            );

            return true;
        } catch (\PDOException $exception) {
            if ($exception->getCode() === '23000') {
                return false;
            }

            throw $exception;
        }
    }

    /** @return array<int, array<string, mixed>> */
    public function eventsFor(int $shipmentId, bool $customerVisibleOnly = false): array
    {
        $sql = 'SELECT `status`, `title`, `description`, `location`, `occurred_date`, `source`
                  FROM `shipment_events`
                 WHERE `shipment_id` = :shipment_id AND `is_deleted` = 0';

        if ($customerVisibleOnly) {
            $sql .= ' AND `is_customer_visible` = 1';
        }

        $sql .= ' ORDER BY `occurred_date` ASC, `id` ASC';

        return $this->db->select($sql, ['shipment_id' => $shipmentId]);
    }

    /**
     * Shipments that should be polled for updates.
     *
     * Only those actually moving: delivered and cancelled parcels are finished,
     * and polling them wastes the courier's rate limit.
     *
     * @return array<int, array<string, mixed>>
     */
    public function needingTrackingRefresh(int $staleMinutes = 180, int $limit = 100): array
    {
        return $this->db->select(
            sprintf(
                "SELECT * FROM `shipments`
                  WHERE `status` IN ('booked','label_generated','pickup_scheduled','picked_up',
                                     'in_transit','out_for_delivery','failed_delivery','rto_initiated')
                    AND `awb_number` IS NOT NULL
                    AND `is_deleted` = 0
                    AND (`last_scan_date` IS NULL OR `last_scan_date` < DATE_SUB(NOW(), INTERVAL %d MINUTE))
                  ORDER BY COALESCE(`last_scan_date`, `created_date`) ASC
                  LIMIT %d",
                max(1, $staleMinutes),
                max(1, min($limit, 500))
            )
        );
    }

    /**
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string, search:?string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function paginateForStaff(array $params, ?string $status = null, ?int $courierId = null): array
    {
        $where = ['s.`is_deleted` = 0'];
        $bindings = [];

        if ($status !== null) {
            $where[] = 's.`status` = :status';
            $bindings['status'] = $status;
        }

        if ($courierId !== null) {
            $where[] = 's.`courier_id` = :courier_id';
            $bindings['courier_id'] = $courierId;
        }

        if (($params['search'] ?? null) !== null) {
            $where[] = '(s.`awb_number` LIKE :search_awb OR s.`shipment_number` LIKE :search_number)';
            $bindings['search_awb'] = '%' . $params['search'] . '%';
            $bindings['search_number'] = '%' . $params['search'] . '%';
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM `shipments` s WHERE {$whereSql}", $bindings);

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $items = $this->db->select(
            sprintf(
                'SELECT v.* FROM `vw_shipment_summary` v
                  WHERE v.`id` IN (SELECT s.`id` FROM `shipments` s WHERE %s)
                  ORDER BY v.`created_date` %s LIMIT %d OFFSET %d',
                $whereSql,
                $params['direction'],
                $params['per_page'],
                $params['offset']
            ),
            $bindings
        );

        return ['items' => $items, 'total' => $total];
    }

    /** @return array<int, array<string, mixed>> */
    public function readyForPickup(int $courierId): array
    {
        return $this->db->select(
            "SELECT * FROM `shipments`
              WHERE `courier_id` = :courier_id
                AND `status` IN ('booked','label_generated')
                AND `pickup_request_id` IS NULL
                AND `is_deleted` = 0
              ORDER BY `created_date`",
            ['courier_id' => $courierId]
        );
    }
}
