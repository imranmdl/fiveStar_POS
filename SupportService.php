<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Helpers\Uuid;
use App\Repositories\SettingRepository;
use App\Services\Orders\NumberingService;

/**
 * Support tickets.
 *
 * One thread per ticket, with a visibility flag on each message rather than two
 * separate tables. Two tables would eventually be joined in the wrong order and
 * show a customer what a colleague said about their complaint; a database CHECK
 * additionally refuses to let a customer author an internal note at all.
 *
 * SLA is tracked on first response as well as resolution, because those are
 * different promises. Customers judge support almost entirely on how long they
 * waited to hear anything; the business judges it on how long the problem took
 * to go away.
 */
final class SupportService
{
    public function __construct(
        private readonly NotificationService $notifications,
        private readonly NumberingService $numbering,
        private readonly SettingRepository $settings,
        private readonly AuditService $audit,
        private readonly Database $db,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function open(Request $request, array $data): array
    {
        $userId = $request->authUserId();
        $orderId = null;

        if (($data['order_uuid'] ?? null) !== null) {
            $order = $this->db->selectOne(
                'SELECT `id`, `user_id` FROM `orders` WHERE `uuid` = :uuid AND `is_deleted` = 0',
                ['uuid' => (string) $data['order_uuid']]
            );

            if ($order === null) {
                throw new NotFoundException('That order does not exist.');
            }

            // Only about your own order. A ticket carries an order's details
            // into the reply thread.
            if ($userId === null || (int) $order['user_id'] !== (int) $userId) {
                throw new NotFoundException('That order does not exist.');
            }

            $orderId = (int) $order['id'];
        }

        $firstResponseHours = max(1, $this->settings->intValue('support_first_response_hours', 4));
        $resolutionHours = max(1, $this->settings->intValue('support_resolution_hours', 48));

        $ticketId = $this->db->transaction(function () use ($data, $userId, $orderId, $firstResponseHours, $resolutionHours): int {
            $id = (int) $this->db->insert(
                'INSERT INTO `support_tickets`
                     (`uuid`, `ticket_number`, `user_id`, `order_id`, `category`, `priority`,
                      `subject`, `contact_name`, `contact_mobile`, `contact_email`, `status`,
                      `first_response_due`, `resolution_due`, `last_message_date`, `source`,
                      `created_by`, `created_date`, `is_active`, `is_deleted`, `version`)
                 VALUES
                     (:uuid, :number, :user_id, :order_id, :category, :priority,
                      :subject, :contact_name, :contact_mobile, :contact_email, \'open\',
                      DATE_ADD(NOW(), INTERVAL :first_hours HOUR),
                      DATE_ADD(NOW(), INTERVAL :resolution_hours HOUR),
                      NOW(), :source, :created_by, NOW(), 1, 0, 1)',
                [
                    'uuid' => Uuid::v4(),
                    'number' => $this->numbering->nextTicketNumber(),
                    'user_id' => $userId,
                    'order_id' => $orderId,
                    'category' => $data['category'] ?? 'other',
                    'priority' => $data['priority'] ?? 'normal',
                    'subject' => $data['subject'],
                    'contact_name' => $data['contact_name'],
                    'contact_mobile' => $data['contact_mobile'],
                    'contact_email' => $data['contact_email'] ?? null,
                    'first_hours' => $firstResponseHours,
                    'resolution_hours' => $resolutionHours,
                    'source' => $data['source'] ?? 'web',
                    'created_by' => $userId,
                ]
            );

            $this->addMessage($id, $userId, 'customer', (string) $data['message'], false);

            return $id;
        });

        $ticket = $this->requireTicketById($ticketId);

        if ($userId !== null) {
            $this->notifications->queue(
                'ticket.created',
                'sms',
                [
                    'ticket_number' => (string) $ticket['ticket_number'],
                    'sla_hours' => $firstResponseHours,
                ],
                [
                    'user_id' => (int) $userId,
                    'reference_type' => 'support_tickets',
                    'reference_id' => (string) $ticket['ticket_number'],
                    'dedupe_key' => 'ticket.created:' . $ticket['ticket_number'],
                ]
            );
        }

        return ['ticket' => $this->present($ticket, staffView: false)];
    }

    /**
     * Adds a reply.
     *
     * @return array<string, mixed>
     */
    public function reply(Request $request, string $ticketUuid, string $body, bool $isInternal, bool $isStaff): array
    {
        $ticket = $isStaff
            ? $this->requireTicket($ticketUuid)
            : $this->requireOwnTicket($request, $ticketUuid);

        if (in_array($ticket['status'], ['closed'], true)) {
            throw new HttpException('This ticket is closed. Please raise a new one.', 409);
        }

        if ($isInternal && !$isStaff) {
            throw new HttpException('Only our team can add internal notes.', 403);
        }

        $this->db->transaction(function () use ($ticket, $request, $body, $isInternal, $isStaff): void {
            $this->addMessage(
                (int) $ticket['id'],
                $request->authUserId(),
                $isStaff ? 'staff' : 'customer',
                $body,
                $isInternal
            );

            $changes = ['last_message_date' => date('Y-m-d H:i:s')];

            // An internal note is not a response to the customer, so it must not
            // stop the first-response clock. Recording it as one would make the
            // SLA report flattering and useless.
            if ($isStaff && !$isInternal && $ticket['first_response_date'] === null) {
                $changes['first_response_date'] = date('Y-m-d H:i:s');
            }

            if (!$isInternal) {
                $changes['status'] = $isStaff ? 'awaiting_customer' : 'in_progress';
            }

            // A customer replying to a resolved ticket reopens it, within the
            // window. Otherwise their reply vanishes into a closed thread.
            if (!$isStaff && $ticket['status'] === 'resolved') {
                $days = max(1, $this->settings->intValue('support_reopen_days', 7));

                if ($ticket['resolved_date'] !== null
                    && (time() - strtotime((string) $ticket['resolved_date'])) <= $days * 86400) {
                    $changes['status'] = 'open';
                    $changes['resolved_date'] = null;
                    $changes['reopened_count'] = (int) $ticket['reopened_count'] + 1;
                }
            }

            $sets = [];
            $bindings = ['id' => (int) $ticket['id']];

            foreach ($changes as $column => $value) {
                $sets[] = sprintf('`%s` = :%s', $column, $column);
                $bindings[$column] = $value;
            }

            $this->db->execute(
                sprintf(
                    'UPDATE `support_tickets` SET %s, `updated_date` = NOW(), `version` = `version` + 1 WHERE `id` = :id',
                    implode(', ', $sets)
                ),
                $bindings
            );
        });

        if ($isStaff && !$isInternal && $ticket['user_id'] !== null) {
            $this->notifications->queue(
                'ticket.replied',
                'sms',
                ['ticket_number' => (string) $ticket['ticket_number']],
                [
                    'user_id' => (int) $ticket['user_id'],
                    'reference_type' => 'support_tickets',
                    'reference_id' => (string) $ticket['ticket_number'],
                    'dedupe_key' => 'ticket.replied:' . $ticket['ticket_number'] . ':' . time(),
                ]
            );
        }

        return ['ticket' => $this->present($this->requireTicketById((int) $ticket['id']), $isStaff)];
    }

    /** @return array<string, mixed> */
    public function show(Request $request, string $ticketUuid, bool $isStaff): array
    {
        $ticket = $isStaff
            ? $this->requireTicket($ticketUuid)
            : $this->requireOwnTicket($request, $ticketUuid);

        $detail = $this->present($ticket, $isStaff);

        $sql = 'SELECT m.*, u.`full_name` AS `author_name`
                  FROM `support_ticket_messages` m
                  LEFT JOIN `users` u ON u.`id` = m.`author_user_id`
                 WHERE m.`ticket_id` = :ticket_id AND m.`is_deleted` = 0';

        if (!$isStaff) {
            $sql .= ' AND m.`is_internal_note` = 0';
        }

        $sql .= ' ORDER BY m.`created_date` ASC, m.`id` ASC';

        $detail['messages'] = array_map(static fn (array $row): array => [
            'uuid' => $row['uuid'],
            'author_type' => $row['author_type'],
            'author_name' => $row['author_type'] === 'staff'
                ? ($row['author_name'] ?? 'Support team')
                : ($row['author_name'] ?? 'You'),
            'body' => $row['body'],
            'is_internal_note' => (bool) $row['is_internal_note'],
            'created_date' => $row['created_date'],
        ], $this->db->select($sql, ['ticket_id' => (int) $ticket['id']]));

        return $detail;
    }

    /**
     * Resolves a ticket.
     *
     * @return array<string, mixed>
     */
    public function resolve(Request $request, string $ticketUuid, string $note): array
    {
        $ticket = $this->requireTicket($ticketUuid);

        if (in_array($ticket['status'], ['resolved', 'closed'], true)) {
            throw new HttpException('This ticket is already ' . $ticket['status'] . '.', 409);
        }

        $this->db->transaction(function () use ($ticket, $note, $request): void {
            $this->addMessage((int) $ticket['id'], $request->authUserId(), 'staff', $note, false);

            $this->db->execute(
                "UPDATE `support_tickets`
                    SET `status` = 'resolved', `resolved_date` = NOW(), `resolution_note` = :note,
                        `first_response_date` = COALESCE(`first_response_date`, NOW()),
                        `last_message_date` = NOW(), `updated_by` = :actor,
                        `updated_date` = NOW(), `version` = `version` + 1
                  WHERE `id` = :id",
                ['note' => $note, 'actor' => $request->authUserId(), 'id' => (int) $ticket['id']]
            );
        });

        if ($ticket['user_id'] !== null) {
            $this->notifications->queue(
                'ticket.resolved',
                'sms',
                [
                    'ticket_number' => (string) $ticket['ticket_number'],
                    'reopen_days' => $this->settings->intValue('support_reopen_days', 7),
                ],
                [
                    'user_id' => (int) $ticket['user_id'],
                    'reference_type' => 'support_tickets',
                    'reference_id' => (string) $ticket['ticket_number'],
                    'dedupe_key' => 'ticket.resolved:' . $ticket['ticket_number'] . ':' . $ticket['reopened_count'],
                ]
            );
        }

        $this->audit->log(
            entityName: 'support_tickets',
            entityId: (int) $ticket['id'],
            action: 'resolve',
            newValues: ['note' => $note],
            request: $request,
            entityUuid: $ticketUuid,
        );

        return ['ticket' => $this->present($this->requireTicketById((int) $ticket['id']), true)];
    }

    /** Customer rates the support they received. */
    public function rate(Request $request, string $ticketUuid, int $rating, ?string $comment): array
    {
        $ticket = $this->requireOwnTicket($request, $ticketUuid);

        if (!in_array($ticket['status'], ['resolved', 'closed'], true)) {
            throw new HttpException('You can rate a ticket once it has been resolved.', 409);
        }

        $this->db->execute(
            'UPDATE `support_tickets`
                SET `satisfaction_rating` = :rating, `satisfaction_comment` = :comment,
                    `updated_date` = NOW(), `version` = `version` + 1
              WHERE `id` = :id',
            ['rating' => $rating, 'comment' => $comment, 'id' => (int) $ticket['id']]
        );

        return ['rated' => true];
    }

    /** Assigns a ticket to a staff member. */
    public function assign(Request $request, string $ticketUuid, string $assigneeUuid): array
    {
        $ticket = $this->requireTicket($ticketUuid);

        $assignee = $this->db->selectOne(
            'SELECT `id`, `full_name` FROM `users` WHERE `uuid` = :uuid AND `is_deleted` = 0',
            ['uuid' => $assigneeUuid]
        );

        if ($assignee === null) {
            throw new NotFoundException('That staff member does not exist.');
        }

        $this->db->execute(
            "UPDATE `support_tickets`
                SET `assigned_to_user_id` = :assignee, `status` = CASE WHEN `status` = 'open'
                        THEN 'in_progress' ELSE `status` END,
                    `updated_by` = :actor, `updated_date` = NOW(), `version` = `version` + 1
              WHERE `id` = :id",
            ['assignee' => (int) $assignee['id'], 'actor' => $request->authUserId(), 'id' => (int) $ticket['id']]
        );

        return ['assigned_to' => $assignee['full_name']];
    }

    /**
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function listForStaff(array $params, ?string $status, ?string $category): array
    {
        $where = ['t.`is_deleted` = 0'];
        $bindings = [];

        if ($status !== null) {
            $where[] = 't.`status` = :status';
            $bindings['status'] = $status;
        }

        if ($category !== null) {
            $where[] = 't.`category` = :category';
            $bindings['category'] = $category;
        }

        $whereSql = implode(' AND ', $where);
        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM `support_tickets` t WHERE {$whereSql}", $bindings);

        $items = $total === 0 ? [] : $this->db->select(
            sprintf(
                'SELECT t.* FROM `support_tickets` t WHERE %s
                  ORDER BY FIELD(t.`priority`, \'urgent\', \'high\', \'normal\', \'low\'),
                           t.`first_response_due` ASC
                  LIMIT %d OFFSET %d',
                $whereSql,
                $params['per_page'],
                $params['offset']
            ),
            $bindings
        );

        return [
            'items' => array_map(fn (array $row): array => $this->present($row, true), $items),
            'total' => $total,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function mine(Request $request): array
    {
        $rows = $this->db->select(
            'SELECT * FROM `support_tickets` WHERE `user_id` = :user_id AND `is_deleted` = 0
              ORDER BY `created_date` DESC LIMIT 100',
            ['user_id' => (int) $request->authUserId()]
        );

        return array_map(fn (array $row): array => $this->present($row, false), $rows);
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function addMessage(int $ticketId, ?int $authorId, string $authorType, string $body, bool $isInternal): void
    {
        $this->db->insert(
            'INSERT INTO `support_ticket_messages`
                 (`uuid`, `ticket_id`, `author_user_id`, `author_type`, `body`, `is_internal_note`,
                  `created_by`, `created_date`, `is_active`, `is_deleted`, `version`)
             VALUES (:uuid, :ticket_id, :author_id, :author_type, :body, :internal,
                     :created_by, NOW(), 1, 0, 1)',
            [
                'uuid' => Uuid::v4(),
                'ticket_id' => $ticketId,
                'author_id' => $authorId,
                'author_type' => $authorType,
                'body' => $body,
                'internal' => $isInternal ? 1 : 0,
                'created_by' => $authorId,
            ]
        );
    }

    /** @return array<string, mixed> */
    private function requireTicket(string $uuid): array
    {
        $ticket = $this->db->selectOne(
            'SELECT * FROM `support_tickets` WHERE `uuid` = :uuid AND `is_deleted` = 0',
            ['uuid' => $uuid]
        );

        if ($ticket === null) {
            throw new NotFoundException('That ticket does not exist.');
        }

        return $ticket;
    }

    /** @return array<string, mixed> */
    private function requireTicketById(int $id): array
    {
        $ticket = $this->db->selectOne('SELECT * FROM `support_tickets` WHERE `id` = :id', ['id' => $id]);

        if ($ticket === null) {
            throw new NotFoundException('That ticket does not exist.');
        }

        return $ticket;
    }

    /** @return array<string, mixed> */
    private function requireOwnTicket(Request $request, string $uuid): array
    {
        $ticket = $this->requireTicket($uuid);

        if ($ticket['user_id'] === null || (int) $ticket['user_id'] !== (int) $request->authUserId()) {
            throw new NotFoundException('That ticket does not exist.');
        }

        return $ticket;
    }

    /**
     * @param array<string, mixed> $ticket
     *
     * @return array<string, mixed>
     */
    private function present(array $ticket, bool $staffView): array
    {
        $view = [
            'uuid' => $ticket['uuid'],
            'ticket_number' => $ticket['ticket_number'],
            'subject' => $ticket['subject'],
            'category' => $ticket['category'],
            'priority' => $ticket['priority'],
            'status' => $ticket['status'],
            'created_date' => $ticket['created_date'],
            'last_message_date' => $ticket['last_message_date'],
            'resolved_date' => $ticket['resolved_date'],
            'resolution_note' => $ticket['resolution_note'],
            'satisfaction_rating' => $ticket['satisfaction_rating'] === null
                ? null
                : (int) $ticket['satisfaction_rating'],
        ];

        if (!$staffView) {
            return $view;
        }

        return $view + [
            'contact_name' => $ticket['contact_name'],
            'contact_mobile' => $ticket['contact_mobile'],
            'contact_email' => $ticket['contact_email'],
            'assigned_to_user_id' => $ticket['assigned_to_user_id'],
            'first_response_due' => $ticket['first_response_due'],
            'first_response_date' => $ticket['first_response_date'],
            // Computed rather than stored: a breach flag written at creation
            // time would be wrong the moment the deadline passed.
            'first_response_breached' => $ticket['first_response_date'] === null
                && $ticket['first_response_due'] !== null
                && strtotime((string) $ticket['first_response_due']) < time()
                && !in_array($ticket['status'], ['resolved', 'closed'], true),
            'resolution_due' => $ticket['resolution_due'],
            'reopened_count' => (int) $ticket['reopened_count'],
            'source' => $ticket['source'],
        ];
    }
}
