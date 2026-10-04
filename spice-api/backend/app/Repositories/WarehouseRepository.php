<?php

declare(strict_types=1);

namespace App\Repositories;

final class WarehouseRepository extends BaseRepository
{
    protected function table(): string
    {
        return 'warehouses';
    }

    protected function fillable(): array
    {
        return [
            'code', 'name', 'address_line1', 'address_line2', 'city', 'state',
            'pincode', 'country', 'phone', 'is_default',
        ];
    }

    protected function sortable(): array
    {
        return ['id', 'code', 'name', 'created_date'];
    }

    /** @return array<int, array<string, mixed>> */
    public function all(bool $activeOnly = true): array
    {
        $sql = 'SELECT * FROM `warehouses` WHERE `is_deleted` = 0';

        if ($activeOnly) {
            $sql .= ' AND `is_active` = 1';
        }

        return $this->db->select($sql . ' ORDER BY `is_default` DESC, `name` ASC');
    }

    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        return $this->existsWhere('code', strtoupper($code), $exceptId);
    }

    /** @return array<string, mixed>|null */
    public function findByCode(string $code, bool $activeOnly = true): ?array
    {
        $sql = 'SELECT * FROM `warehouses` WHERE `code` = :code AND `is_deleted` = 0';

        if ($activeOnly) {
            $sql .= ' AND `is_active` = 1';
        }

        return $this->db->selectOne($sql . ' LIMIT 1', ['code' => strtoupper($code)]);
    }

    /** @return array<string, mixed>|null */
    public function findDefault(): ?array
    {
        return $this->db->selectOne(
            'SELECT * FROM `warehouses` WHERE `is_default` = 1 AND `is_deleted` = 0 LIMIT 1'
        );
    }

    /**
     * Exactly one warehouse carries is_default. Called inside the same
     * transaction as the insert/update that sets a new default.
     */
    public function clearDefaultFlag(?int $exceptId = null): void
    {
        $sql = 'UPDATE `warehouses` SET `is_default` = 0, `updated_date` = NOW(), `version` = `version` + 1
                 WHERE `is_deleted` = 0';
        $bindings = [];

        if ($exceptId !== null) {
            $sql .= ' AND `id` <> :except_id';
            $bindings['except_id'] = $exceptId;
        }

        $this->db->execute($sql, $bindings);
    }
}
