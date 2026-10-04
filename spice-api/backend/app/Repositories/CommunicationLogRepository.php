<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Every WhatsApp send the Invoice Tracking Center initiates (item 8's
 * "maintain a communication history" and "do not send duplicate reminders
 * accidentally").
 *
 * "Sent" here means the WhatsApp composer (wa.me deep link) was opened with
 * the rendered message — this project has no WhatsApp Business API
 * credential, so there is no delivery receipt to record. See
 * InvoiceService's own doc comment.
 */
final class CommunicationLogRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'communication_log';
    }

    protected function fillable(): array
    {
        return ['pos_sale_id', 'channel', 'template_code', 'recipient_mobile', 'message_preview', 'status', 'sent_by_user_id'];
    }

    protected function sortable(): array
    {
        return ['id', 'created_date'];
    }

    /** @return array<int, array<string, mixed>> */
    public function forSale(int $posSaleId): array
    {
        return $this->db->select(
            'SELECT c.*, u.full_name AS sent_by_name
               FROM communication_log c
               JOIN users u ON u.id = c.sent_by_user_id
              WHERE c.pos_sale_id = :sale_id AND c.is_deleted = 0
              ORDER BY c.created_date DESC',
            ['sale_id' => $posSaleId]
        );
    }

    /**
     * Whether the same template was already sent for this sale, on this
     * channel, within the given window — the actual "no accidental duplicate
     * reminders" check. Scoped per channel so a WhatsApp send that turned out
     * to have no delivery does not block the SMS fallback for the same
     * template a moment later. A one-off template (invoice_created,
     * payment_received, ...) uses a short window just to absorb a
     * double-click; a recurring reminder template uses the admin-configured
     * repeat window instead.
     */
    public function sentRecently(int $posSaleId, string $templateCode, int $withinMinutes, string $channel = 'whatsapp'): bool
    {
        return $this->db->scalar(
            'SELECT 1 FROM communication_log
              WHERE pos_sale_id = :sale_id AND template_code = :template AND channel = :channel AND is_deleted = 0
                AND created_date >= DATE_SUB(NOW(), INTERVAL :minutes MINUTE)
              LIMIT 1',
            ['sale_id' => $posSaleId, 'template' => $templateCode, 'channel' => $channel, 'minutes' => $withinMinutes]
        ) !== null;
    }
}
