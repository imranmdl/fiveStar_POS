<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Repositories\WarehouseRepository;

final class WarehouseService
{
    public function __construct(
        private readonly WarehouseRepository $warehouses,
        private readonly AuditService $audit,
        private readonly Database $db,
    ) {
    }

    /** @return array<int, array<string, mixed>> */
    public function list(bool $activeOnly = false): array
    {
        return array_map([$this, 'present'], $this->warehouses->all($activeOnly));
    }

    /** @return array<string, mixed> */
    public function detail(string $uuid): array
    {
        return $this->present($this->requireByUuid($uuid));
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function create(array $data, Request $request): array
    {
        $code = strtoupper((string) $data['code']);

        if ($this->warehouses->codeExists($code)) {
            throw new HttpException('A warehouse with that code already exists.', 422, [
                'code' => ['That warehouse code is already in use.'],
            ]);
        }

        $makeDefault = (bool) ($data['is_default'] ?? false) || $this->warehouses->findDefault() === null;

        $id = $this->db->transaction(function () use ($data, $code, $makeDefault, $request): int {
            if ($makeDefault) {
                $this->warehouses->clearDefaultFlag();
            }

            return $this->warehouses->create([
                'code' => $code,
                'name' => $data['name'],
                'address_line1' => $data['address_line1'] ?? null,
                'address_line2' => $data['address_line2'] ?? null,
                'city' => $data['city'] ?? null,
                'state' => $data['state'] ?? null,
                'pincode' => $data['pincode'] ?? null,
                'country' => $data['country'] ?? 'India',
                'phone' => $data['phone'] ?? null,
                'is_default' => $makeDefault ? 1 : 0,
            ], $request->authUserId());
        });

        $warehouse = (array) $this->warehouses->findById($id);

        $this->audit->log(
            entityName: 'warehouses',
            entityId: $id,
            action: 'create',
            newValues: $warehouse,
            request: $request,
            entityUuid: (string) $warehouse['uuid'],
        );

        return $this->present($warehouse);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    public function update(string $uuid, array $data, Request $request): array
    {
        $warehouse = $this->requireByUuid($uuid);
        $id = (int) $warehouse['id'];

        if (isset($data['code'])) {
            $data['code'] = strtoupper((string) $data['code']);

            if ($this->warehouses->codeExists($data['code'], $id)) {
                throw new HttpException('A warehouse with that code already exists.', 422, [
                    'code' => ['That warehouse code is already in use.'],
                ]);
            }
        }

        $makesDefault = (bool) ($data['is_default'] ?? false);

        if (!$makesDefault && (bool) $warehouse['is_default'] && array_key_exists('is_default', $data)) {
            throw new HttpException(
                'At least one warehouse must stay marked as default. Make another warehouse default first.',
                422
            );
        }

        $this->db->transaction(function () use ($id, $data, $makesDefault, $request): void {
            if ($makesDefault) {
                $this->warehouses->clearDefaultFlag($id);
            }

            $this->warehouses->update($id, $data, $request->authUserId());
        });

        $fresh = (array) $this->warehouses->findById($id);

        $this->audit->log(
            entityName: 'warehouses',
            entityId: $id,
            action: 'update',
            oldValues: $warehouse,
            newValues: $fresh,
            request: $request,
            entityUuid: $uuid,
        );

        return $this->present($fresh);
    }

    public function deactivate(string $uuid, Request $request): void
    {
        $warehouse = $this->requireByUuid($uuid);

        if ((bool) $warehouse['is_default']) {
            throw new HttpException(
                'The default warehouse cannot be deactivated. Make another warehouse default first.',
                422
            );
        }

        $this->warehouses->update((int) $warehouse['id'], ['is_active' => 0], $request->authUserId());

        $this->audit->log(
            entityName: 'warehouses',
            entityId: (int) $warehouse['id'],
            action: 'deactivate',
            request: $request,
            entityUuid: $uuid,
        );
    }

    /** @return array<string, mixed> */
    private function requireByUuid(string $uuid): array
    {
        $warehouse = $this->warehouses->findByUuid($uuid);

        if ($warehouse === null) {
            throw new NotFoundException('That warehouse does not exist.');
        }

        return $warehouse;
    }

    /**
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    private function present(array $row): array
    {
        return [
            'uuid' => $row['uuid'],
            'code' => $row['code'],
            'name' => $row['name'],
            'address_line1' => $row['address_line1'],
            'address_line2' => $row['address_line2'],
            'city' => $row['city'],
            'state' => $row['state'],
            'pincode' => $row['pincode'],
            'country' => $row['country'],
            'phone' => $row['phone'],
            'is_default' => (bool) $row['is_default'],
            'is_active' => (bool) $row['is_active'],
        ];
    }
}
