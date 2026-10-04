<?php

declare(strict_types=1);

namespace App\Repositories;

final class VendorRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'vendors';
    }

    protected function fillable(): array
    {
        return [
            'vendor_code', 'name', 'company_name', 'contact_person', 'phone', 'email',
            'address_line1', 'address_line2', 'city', 'state', 'pincode', 'country',
            'gstin', 'pan', 'bank_account_name', 'bank_account_number', 'bank_ifsc', 'bank_name',
            'payment_terms', 'notes',
        ];
    }

    protected function sortable(): array
    {
        return ['id', 'name', 'created_date'];
    }

    public function countActive(): int
    {
        return (int) $this->db->scalar(
            'SELECT COUNT(*) FROM `vendors` WHERE `is_deleted` = 0 AND `is_active` = 1'
        );
    }

    /**
     * @param array{page:int, per_page:int, offset:int, sort:string, direction:string, search:?string} $params
     *
     * @return array{items:array<int, array<string, mixed>>, total:int}
     */
    public function search(array $params): array
    {
        $where = ['`is_deleted` = 0'];
        $bindings = [];

        if (!empty($params['active_only'])) {
            $where[] = '`is_active` = 1';
        }

        if (($params['search'] ?? null) !== null) {
            $where[] = '(`name` LIKE :search OR `vendor_code` LIKE :search OR `gstin` LIKE :search OR `phone` LIKE :search)';
            $bindings['search'] = '%' . $params['search'] . '%';
        }

        $whereSql = implode(' AND ', $where);
        $sort = in_array($params['sort'], $this->sortable(), true) ? $params['sort'] : 'name';

        $total = (int) $this->db->scalar("SELECT COUNT(*) FROM `vendors` WHERE {$whereSql}", $bindings);

        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $items = $this->db->select(
            sprintf(
                'SELECT * FROM `vendors` WHERE %s ORDER BY `%s` %s LIMIT %d OFFSET %d',
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
}
