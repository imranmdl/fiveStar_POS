<?php

declare(strict_types=1);

namespace App\Repositories;

final class DeliveryZoneRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'delivery_zones';
    }

    protected function fillable(): array
    {
        return ['code', 'name', 'sla_min_days', 'sla_max_days', 'is_default', 'is_serviceable'];
    }

    /**
     * Resolves a pincode to a zone by longest matching prefix, so a single
     * pincode exception overrides a broad regional range without any special
     * casing in code.
     *
     * @return array<string, mixed>|null
     */
    public function findZoneForPincode(string $pincode): ?array
    {
        $digits = preg_replace('/\D/', '', $pincode) ?? '';

        if ($digits === '') {
            return null;
        }

        $prefixes = [];
        $bindings = [];

        // Build the candidate prefix list: '560001', '56000', '5600', '560', '56', '5'
        for ($length = min(6, strlen($digits)); $length >= 1; --$length) {
            $key = 'p' . $length;
            $prefixes[] = ':' . $key;
            $bindings[$key] = substr($digits, 0, $length);
        }

        return $this->db->selectOne(
            sprintf(
                'SELECT z.*, m.`pincode_prefix`, m.`label` AS `prefix_label`
                   FROM `delivery_pincode_map` m
                   INNER JOIN `delivery_zones` z ON z.`id` = m.`zone_id`
                  WHERE m.`pincode_prefix` IN (%s)
                    AND m.`is_deleted` = 0 AND m.`is_active` = 1
                    AND z.`is_deleted` = 0 AND z.`is_active` = 1
                  ORDER BY CHAR_LENGTH(m.`pincode_prefix`) DESC
                  LIMIT 1',
                implode(', ', $prefixes)
            ),
            $bindings
        );
    }

    /** @return array<string, mixed>|null */
    public function defaultZone(): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM `delivery_zones`
              WHERE `is_default` = 1 AND `is_deleted` = 0 AND `is_active` = 1
              LIMIT 1'
        );
    }

    /** @return array<string, mixed>|null */
    public function findByCode(string $code): ?array
    {
        return $this->findOneBy('code', strtoupper($code));
    }

    /**
     * The band covering this weight. The heaviest band is open-ended
     * (max_weight_grams IS NULL), so every weight resolves to something.
     *
     * @return array<string, mixed>|null
     */
    public function findSlabForWeight(int $zoneId, int $weightGrams): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM `delivery_charge_slabs`
              WHERE `zone_id` = :zone_id
                AND `min_weight_grams` <= :weight
                AND (`max_weight_grams` IS NULL OR `max_weight_grams` >= :weight_upper)
                AND `is_deleted` = 0 AND `is_active` = 1
              ORDER BY `min_weight_grams` DESC
              LIMIT 1',
            ['zone_id' => $zoneId, 'weight' => $weightGrams, 'weight_upper' => $weightGrams]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function slabsForZone(int $zoneId): array
    {
        return $this->db->select(
            'SELECT * FROM `delivery_charge_slabs`
              WHERE `zone_id` = :zone_id AND `is_deleted` = 0 AND `is_active` = 1
              ORDER BY `min_weight_grams` ASC',
            ['zone_id' => $zoneId]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function allServiceableZones(): array
    {
        return $this->db->select(
            'SELECT `uuid`, `code`, `name`, `sla_min_days`, `sla_max_days`, `is_default`, `is_serviceable`
               FROM `delivery_zones`
              WHERE `is_deleted` = 0 AND `is_active` = 1
              ORDER BY `code`'
        );
    }
}
