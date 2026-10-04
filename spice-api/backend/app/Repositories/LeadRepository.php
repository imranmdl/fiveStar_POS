<?php

declare(strict_types=1);

namespace App\Repositories;

/**
 * Website visitors who left contact details for offers/updates — not a
 * `users` account (no password, no signup). See migration
 * 025_marketing_leads.sql for why this is a separate table.
 */
final class LeadRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'leads';
    }

    protected function fillable(): array
    {
        return [
            'full_name', 'email', 'mobile', 'source',
            'consent_marketing', 'consent_date', 'unsubscribed_date',
            'last_messaged_date', 'message_count',
        ];
    }

    protected function sortable(): array
    {
        return ['id', 'created_date', 'full_name'];
    }

    /** @return array<string, mixed>|null */
    public function findByMobile(string $mobile): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM `leads` WHERE `mobile` = :mobile AND `is_deleted` = 0',
            ['mobile' => $mobile]
        );
    }

    /**
     * Who the recurring broadcast is allowed to message: opted in, never
     * unsubscribed. Consent is the only gate here — NotificationPolicy
     * still applies quiet hours on top of this at queue time.
     *
     * @return array<int, array<string, mixed>>
     */
    public function activeConsentedForBroadcast(): array
    {
        return $this->db->select(
            'SELECT * FROM `leads`
              WHERE `consent_marketing` = 1
                AND `unsubscribed_date` IS NULL
                AND `is_deleted` = 0'
        );
    }

    /**
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function paginateForAdmin(array $params): array
    {
        $sort = in_array($params['sort'], $this->sortable(), true) ? $params['sort'] : 'created_date';

        $total = (int) $this->db->scalar('SELECT COUNT(*) FROM `leads` WHERE `is_deleted` = 0');

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $items = $this->db->select(
            sprintf(
                'SELECT * FROM `leads` WHERE `is_deleted` = 0
                  ORDER BY `%s` %s
                  LIMIT %d OFFSET %d',
                $sort,
                $params['direction'],
                $params['per_page'],
                $params['offset']
            )
        );

        return ['items' => $items, 'total' => $total];
    }

    /** @return array{total:int, consented:int, unsubscribed:int} */
    public function summary(): array
    {
        $row = $this->db->selectOne(
            'SELECT
                COUNT(*) AS `total`,
                SUM(`consent_marketing` = 1 AND `unsubscribed_date` IS NULL) AS `consented`,
                SUM(`unsubscribed_date` IS NOT NULL) AS `unsubscribed`
               FROM `leads` WHERE `is_deleted` = 0'
        );

        return [
            'total' => (int) ($row['total'] ?? 0),
            'consented' => (int) ($row['consented'] ?? 0),
            'unsubscribed' => (int) ($row['unsubscribed'] ?? 0),
        ];
    }
}
