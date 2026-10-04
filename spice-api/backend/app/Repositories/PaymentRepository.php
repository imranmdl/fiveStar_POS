<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Helpers\Uuid;

final class PaymentRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'payments';
    }

    protected function fillable(): array
    {
        return [
            'order_id', 'user_id', 'gateway', 'gateway_order_id', 'gateway_payment_id',
            'attempt_number', 'amount', 'currency_code', 'status', 'method',
            'upi_vpa', 'upi_transaction_id', 'signature_verified',
            'failure_code', 'failure_reason', 'authorized_date', 'captured_date',
            'failed_date', 'expires_date', 'checkout_payload', 'gateway_response',
        ];
    }

    /** @return array<string, mixed>|null */
    public function findByGatewayOrderId(string $gateway, string $gatewayOrderId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM `payments`
              WHERE `gateway` = :gateway AND `gateway_order_id` = :gateway_order_id
              ORDER BY `id` DESC LIMIT 1',
            ['gateway' => $gateway, 'gateway_order_id' => $gatewayOrderId]
        );
    }

    /** @return array<string, mixed>|null */
    public function findByGatewayPaymentId(string $gateway, string $gatewayPaymentId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM `payments`
              WHERE `gateway` = :gateway AND `gateway_payment_id` = :gateway_payment_id
              LIMIT 1',
            ['gateway' => $gateway, 'gateway_payment_id' => $gatewayPaymentId]
        );
    }

    /**
     * Manual-gateway payment attempts an administrator has not yet verified or
     * rejected. Used by the /admin/payments/pending queue.
     *
     * @return array<int, array<string, mixed>>
     */
    public function pendingManualVerification(int $limit = 100, int $offset = 0): array
    {
        return $this->db->select(
            sprintf(
                "SELECT p.*, o.order_number, o.uuid AS order_uuid, o.ship_name, o.ship_mobile
                   FROM `payments` p
                   JOIN `orders` o ON o.id = p.order_id
                  WHERE p.`gateway` = 'manual'
                    AND p.`status` IN ('created', 'pending')
                    AND p.`is_deleted` = 0
                  ORDER BY p.`created_date` ASC
                  LIMIT %d OFFSET %d",
                max(1, $limit),
                max(0, $offset)
            )
        );
    }

    public function countPendingManualVerification(): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM `payments`
              WHERE `gateway` = 'manual' AND `status` IN ('created', 'pending') AND `is_deleted` = 0"
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function forOrder(int $orderId): array
    {
        return $this->db->select(
            'SELECT * FROM `payments` WHERE `order_id` = :order_id AND `is_deleted` = 0
              ORDER BY `attempt_number` ASC',
            ['order_id' => $orderId]
        );
    }

    /** @return array<string, mixed>|null */
    public function latestForOrder(int $orderId): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM `payments` WHERE `order_id` = :order_id AND `is_deleted` = 0
              ORDER BY `attempt_number` DESC LIMIT 1',
            ['order_id' => $orderId]
        );
    }

    /** @return array<string, mixed>|null */
    public function capturedForOrder(int $orderId): ?array
    {
        return $this->db->selectOne(
            "SELECT * FROM `payments`
              WHERE `order_id` = :order_id AND `status` = 'captured'
                AND `signature_verified` = 1 AND `is_deleted` = 0
              ORDER BY `id` DESC LIMIT 1",
            ['order_id' => $orderId]
        );
    }

    public function nextAttemptNumber(int $orderId): int
    {
        return (int) $this->db->scalar(
            'SELECT COALESCE(MAX(`attempt_number`), 0) + 1 FROM `payments` WHERE `order_id` = :order_id',
            ['order_id' => $orderId]
        );
    }

    /**
     * Records an inbound webhook before anything acts on it.
     *
     * Returns null when the event has been seen before. The UNIQUE key on
     * (gateway, event_id) is what makes redelivery safe: gateways retry
     * aggressively, and a duplicate "captured" event must not confirm an order
     * twice or pay a referral reward twice.
     *
     * @param array<string, mixed> $payload
     */
    public function recordEvent(
        string $gateway,
        string $eventId,
        string $eventType,
        array $payload,
        bool $signatureValid,
        ?string $gatewayOrderId,
        ?string $gatewayPaymentId,
        ?string $ip,
    ): ?int {
        try {
            return $this->db->insert(
                'INSERT INTO `payment_events`
                     (`uuid`, `gateway`, `event_id`, `event_type`, `gateway_order_id`,
                      `gateway_payment_id`, `signature_valid`, `payload`, `received_ip`,
                      `created_date`, `is_active`, `is_deleted`, `version`)
                 VALUES
                     (:uuid, :gateway, :event_id, :event_type, :gateway_order_id,
                      :gateway_payment_id, :signature_valid, :payload, :ip,
                      NOW(), 1, 0, 1)',
                [
                    'uuid' => Uuid::v4(),
                    'gateway' => $gateway,
                    'event_id' => $eventId,
                    'event_type' => $eventType,
                    'gateway_order_id' => $gatewayOrderId,
                    'gateway_payment_id' => $gatewayPaymentId,
                    'signature_valid' => $signatureValid ? 1 : 0,
                    'payload' => json_encode($payload),
                    'ip' => $ip,
                ]
            );
        } catch (\PDOException $exception) {
            if ($exception->getCode() === '23000') {
                return null;
            }

            throw $exception;
        }
    }

    public function markEventProcessed(int $eventId, ?int $orderId, ?string $error = null): void
    {
        $this->db->execute(
            'UPDATE `payment_events`
                SET `processed` = 1, `processed_date` = NOW(), `order_id` = :order_id,
                    `processing_error` = :error, `updated_date` = NOW(), `version` = `version` + 1
              WHERE `id` = :id',
            ['order_id' => $orderId, 'error' => $error, 'id' => $eventId]
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function eventsForOrder(int $orderId): array
    {
        return $this->db->select(
            'SELECT `uuid`, `event_type`, `signature_valid`, `processed`, `processing_error`, `created_date`
               FROM `payment_events` WHERE `order_id` = :order_id ORDER BY `id` ASC',
            ['order_id' => $orderId]
        );
    }
}
