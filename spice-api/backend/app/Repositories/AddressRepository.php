<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Delivery addresses.
 *
 * The table was created in Phase 1 but had no CRUD until checkout needed it —
 * an order cannot be placed without somewhere to send it.
 */
final class AddressRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'user_addresses';
    }

    protected function fillable(): array
    {
        return [
            'user_id', 'label', 'contact_name', 'contact_mobile',
            'address_line1', 'address_line2', 'landmark', 'city', 'district',
            'state', 'pincode', 'country', 'latitude', 'longitude',
            'address_type', 'is_default',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function forUser(int $userId): array
    {
        return $this->db->select(
            'SELECT * FROM `user_addresses`
              WHERE `user_id` = :user_id AND `is_deleted` = 0 AND `is_active` = 1
              ORDER BY `is_default` DESC, `created_date` DESC',
            ['user_id' => $userId]
        );
    }

    /** @return array<string, mixed>|null */
    public function defaultFor(int $userId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM `user_addresses`
              WHERE `user_id` = :user_id AND `is_default` = 1
                AND `is_deleted` = 0 AND `is_active` = 1
              LIMIT 1',
            ['user_id' => $userId]
        );
    }

    public function countForUser(int $userId): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM `user_addresses`
              WHERE `user_id` = :user_id AND `is_deleted` = 0',
            ['user_id' => $userId]
        );
    }

    /**
     * Makes one address the default and clears the flag on every other.
     *
     * Done as two statements inside the caller's transaction rather than with a
     * unique index, because "exactly one default" cannot be expressed as a
     * constraint when zero is also valid (a customer with no addresses yet).
     */
    public function makeDefault(int $addressId, int $userId): void
    {
        $this->db->execute(
            'UPDATE `user_addresses`
                SET `is_default` = 0, `updated_date` = NOW(), `version` = `version` + 1
              WHERE `user_id` = :user_id AND `id` <> :id AND `is_deleted` = 0',
            ['user_id' => $userId, 'id' => $addressId]
        );

        $this->db->execute(
            'UPDATE `user_addresses`
                SET `is_default` = 1, `updated_date` = NOW(), `version` = `version` + 1
              WHERE `id` = :id',
            ['id' => $addressId]
        );
    }

    /**
     * Whether this address is referenced by any order.
     *
     * Orders snapshot the address, so deleting it does not corrupt history —
     * but promoting a different default afterwards still matters.
     */
    public function isUsedByOrder(int $addressId): bool
    {
        $exists = $this->db->scalar(
            "SELECT 1 FROM `information_schema`.`tables`
              WHERE `table_schema` = DATABASE() AND `table_name` = 'orders' LIMIT 1"
        );

        if ($exists === null) {
            return false;
        }

        return $this->db->scalar(
            'SELECT 1 FROM `orders` WHERE `source_address_id` = :id LIMIT 1',
            ['id' => $addressId]
        ) !== null;
    }
}
