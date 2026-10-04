<?php

declare(strict_types=1);

namespace App\Repositories;

final class CartRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'carts';
    }

    protected function fillable(): array
    {
        return [
            'user_id', 'guest_token_hash', 'status', 'currency_code',
            'delivery_pincode', 'merged_into_cart_id', 'converted_order_id',
            'last_activity_date',
            // Added by migration 004. A column missing from this list is
            // silently discarded by the mass-assignment guard, so anything a
            // later migration adds MUST be listed here too.
            'applied_coupon_id', 'applied_coupon_code', 'wallet_redeem_amount',
        ];
    }

    /**
     * Guest tokens are stored as SHA-256 digests, like refresh tokens: a
     * database leak does not hand an attacker usable cart tokens.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @return array<string, mixed>|null */
    public function findActiveForUser(int $userId): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM `carts`
              WHERE `user_id` = :user_id AND `status` = 'active' AND `is_deleted` = 0
              LIMIT 1",
            ['user_id' => $userId]
        );
    }

    /** @return array<string, mixed>|null */
    public function findActiveForGuest(string $token): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM `carts`
              WHERE `guest_token_hash` = :hash AND `status` = 'active' AND `is_deleted` = 0
              LIMIT 1",
            ['hash' => self::hashToken($token)]
        );
    }

    public function touch(int $cartId): void
    {
        $this->db->execute(
            'UPDATE `carts` SET `last_activity_date` = NOW() WHERE `id` = :id',
            ['id' => $cartId]
        );
    }

    public function setPincode(int $cartId, ?string $pincode, ?int $actorId): void
    {
        $this->update($cartId, ['delivery_pincode' => $pincode], $actorId);
    }

    public function markMerged(int $cartId, int $intoCartId, ?int $actorId): void
    {
        $this->db->execute(
            "UPDATE `carts`
                SET `status` = 'merged', `merged_into_cart_id` = :into,
                    `updated_by` = :actor, `updated_date` = NOW(), `version` = `version` + 1
              WHERE `id` = :id",
            ['into' => $intoCartId, 'actor' => $actorId, 'id' => $cartId]
        );
    }

    /**
     * Housekeeping for the scheduled task in Phase 9. Kept here rather than in
     * a service because it is a pure data operation with no business decision.
     */
    public function markStaleCartsAbandoned(int $idleDays): int
    {
        return $this->db->execute(
            "UPDATE `carts`
                SET `status` = 'abandoned', `updated_date` = NOW(), `version` = `version` + 1
              WHERE `status` = 'active' AND `is_deleted` = 0
                AND `last_activity_date` < DATE_SUB(NOW(), INTERVAL :days DAY)",
            ['days' => $idleDays]
        );
    }
}
