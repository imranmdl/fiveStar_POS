<?php

declare(strict_types=1);

namespace App\Repositories;

final class CourierRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'couriers';
    }

    protected function fillable(): array
    {
        return [
            'code', 'name', 'adapter', 'channel_code', 'logo_url', 'support_phone',
            'tracking_url_template', 'min_weight_grams', 'max_weight_grams', 'max_order_value',
            'max_length_mm', 'max_width_mm', 'max_height_mm', 'handles_fragile',
            'priority', 'reliability_score', 'volumetric_divisor',
            'supports_pickup', 'supports_label', 'supports_manifest', 'supports_rto',
            'is_enabled', 'disabled_reason', 'settings',
        ];
    }

    protected function sortable(): array
    {
        return ['id', 'code', 'name', 'priority', 'reliability_score', 'created_date'];
    }

    /** @return array<int, array<string, mixed>> */
    public function enabled(): array
    {
        return $this->db->select(
            'SELECT * FROM `couriers`
              WHERE `is_enabled` = 1 AND `is_active` = 1 AND `is_deleted` = 0
              ORDER BY `priority` ASC, `code` ASC'
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function all(): array
    {
        return $this->db->select(
            'SELECT * FROM `couriers` WHERE `is_deleted` = 0 ORDER BY `priority` ASC, `code` ASC'
        );
    }

    /** @return array<string, mixed>|null */
    public function findByCode(string $code): ?array
    {
        return $this->findOneBy('code', strtoupper(trim($code)));
    }

    /**
     * Serviceability by longest matching pincode prefix.
     *
     * Longest wins, so a courier can serve all of '5' but be excluded from
     * '560087' with one extra row instead of enumerating thousands of pincodes.
     * A row can also exclude, which is how a carrier that covers a region except
     * for a handful of pincodes is expressed.
     *
     * @return array<string, mixed>|null
     */
    public function serviceabilityFor(int $courierId, string $pincode): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM `courier_serviceability`
              WHERE `courier_id` = :courier_id
                AND :pincode LIKE CONCAT(`pincode_prefix`, \'%\')
                AND `is_active` = 1 AND `is_deleted` = 0
              ORDER BY CHAR_LENGTH(`pincode_prefix`) DESC
              LIMIT 1',
            ['courier_id' => $courierId, 'pincode' => $pincode]
        );
    }

    /**
     * The rate slab covering this weight.
     *
     * @return array<string, mixed>|null
     */
    public function rateSlabFor(int $courierId, string $zoneCode, int $weightGrams): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM `courier_rate_cards`
              WHERE `courier_id` = :courier_id
                AND `zone_code` = :zone_code
                AND `min_weight_grams` <= :weight
                AND (`max_weight_grams` IS NULL OR `max_weight_grams` >= :weight_upper)
                AND `is_active` = 1 AND `is_deleted` = 0
                AND (`effective_from` IS NULL OR `effective_from` <= CURDATE())
                AND (`effective_until` IS NULL OR `effective_until` >= CURDATE())
              ORDER BY `min_weight_grams` DESC
              LIMIT 1',
            [
                'courier_id' => $courierId,
                'zone_code' => $zoneCode,
                'weight' => $weightGrams,
                'weight_upper' => $weightGrams,
            ]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function serviceabilityRules(int $courierId): array
    {
        return $this->db->select(
            'SELECT * FROM `courier_serviceability`
              WHERE `courier_id` = :courier_id AND `is_deleted` = 0
              ORDER BY CHAR_LENGTH(`pincode_prefix`) DESC, `pincode_prefix`',
            ['courier_id' => $courierId]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function rateCards(int $courierId): array
    {
        return $this->db->select(
            'SELECT * FROM `courier_rate_cards`
              WHERE `courier_id` = :courier_id AND `is_deleted` = 0
              ORDER BY `zone_code`, `min_weight_grams`',
            ['courier_id' => $courierId]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function performance(): array
    {
        return $this->db->select('SELECT * FROM `vw_courier_performance` ORDER BY `total_shipments` DESC');
    }

    /**
     * Recalculates reliability from real outcomes.
     *
     * Deliveries count for the score, RTOs and losses against it, weighted so a
     * lost parcel hurts far more than a late one. Couriers with too little
     * history keep their configured score: three deliveries is not evidence.
     */
    public function recalculateReliability(int $minimumShipments = 20): int
    {
        return $this->db->execute(
            'UPDATE `couriers` c
               INNER JOIN (
                   SELECT `courier_id`,
                          COUNT(*) AS total,
                          SUM(`status` = \'delivered\') AS delivered,
                          SUM(`status` IN (\'rto_initiated\',\'rto_delivered\')) AS rto,
                          SUM(`status` = \'lost\') AS lost
                     FROM `shipments`
                    WHERE `is_deleted` = 0
                      AND `status` IN (\'delivered\',\'rto_initiated\',\'rto_delivered\',\'lost\')
                    GROUP BY `courier_id`
                   HAVING COUNT(*) >= :minimum
               ) s ON s.`courier_id` = c.`id`
                SET c.`reliability_score` = GREATEST(0, LEAST(100,
                        ROUND(((s.delivered - (s.rto * 0.5) - (s.lost * 3)) / s.total) * 100, 2)
                    )),
                    c.`updated_date` = NOW(),
                    c.`version` = c.`version` + 1',
            ['minimum' => $minimumShipments]
        );
    }
}
