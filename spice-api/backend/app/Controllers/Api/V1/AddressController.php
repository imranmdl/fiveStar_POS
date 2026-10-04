<?php

declare(strict_types=1);

namespace App\Controllers\Api\V1;

use App\Core\BaseController;
use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Repositories\AddressRepository;
use App\Services\AuditService;
use App\Services\DeliveryChargeService;

/**
 * Delivery addresses.
 *
 * The table has existed since Phase 1 but had no endpoints until checkout
 * needed them. Serviceability is checked on save, so a customer finds out their
 * pincode is undeliverable while entering the address rather than at payment.
 */
final class AddressController extends BaseController
{
    private const MAX_ADDRESSES = 20;

    public function __construct(
        private readonly AddressRepository $addresses,
        private readonly DeliveryChargeService $delivery,
        private readonly AuditService $audit,
        private readonly Database $db,
    ) {
    }

    /** GET /api/v1/addresses */
    public function index(Request $request): Response
    {
        $rows = $this->addresses->forUser((int) $request->authUserId());

        return Response::success(
            ['addresses' => array_map([$this, 'present'], $rows)],
            'Addresses loaded'
        );
    }

    /** POST /api/v1/addresses */
    public function store(Request $request): Response
    {
        $data = $this->validate($request, true);
        $userId = (int) $request->authUserId();

        if ($this->addresses->countForUser($userId) >= self::MAX_ADDRESSES) {
            throw new HttpException(
                sprintf('You can save at most %d addresses.', self::MAX_ADDRESSES),
                409
            );
        }

        $serviceability = $this->delivery->checkServiceability((string) $data['pincode']);

        if (!$serviceability['is_serviceable']) {
            throw new HttpException(
                'We do not currently deliver to this pincode.',
                422,
                ['pincode' => [$serviceability['message']]]
            );
        }

        $isFirst = $this->addresses->countForUser($userId) === 0;

        $addressId = $this->db->transaction(function () use ($data, $userId, $isFirst): int {
            $id = $this->addresses->create([
                'user_id' => $userId,
                'label' => $data['label'] ?? 'Home',
                'contact_name' => $data['contact_name'],
                'contact_mobile' => $data['contact_mobile'],
                'address_line1' => $data['address_line1'],
                'address_line2' => $data['address_line2'] ?? null,
                'landmark' => $data['landmark'] ?? null,
                'city' => $data['city'],
                'district' => $data['district'] ?? null,
                'state' => $data['state'],
                'pincode' => $data['pincode'],
                'country' => $data['country'] ?? 'India',
                'address_type' => $data['address_type'] ?? 'home',
                // The first address a customer saves becomes the default; making
                // them pick one for a list of one is pointless friction.
                'is_default' => $isFirst ? 1 : (int) ($data['is_default'] ?? 0),
            ], $userId);

            if ($isFirst || (int) ($data['is_default'] ?? 0) === 1) {
                $this->addresses->makeDefault($id, $userId);
            }

            return $id;
        });

        $this->audit->log(
            entityName: 'user_addresses',
            entityId: $addressId,
            action: 'create',
            newValues: ['city' => $data['city'], 'pincode' => $data['pincode']],
            request: $request,
        );

        return Response::created(
            [
                'address' => $this->present((array) $this->addresses->findById($addressId)),
                'serviceability' => $serviceability,
            ],
            'Address saved'
        );
    }

    /** PATCH /api/v1/addresses/{uuid} */
    public function update(Request $request): Response
    {
        $userId = (int) $request->authUserId();
        $address = $this->requireOwned((string) $request->routeParam('uuid'), $userId);

        $data = $this->validate($request, false);
        $supplied = array_intersect_key($data, $request->all());
        unset($supplied['is_default']);

        if ($supplied === [] && !$request->has('is_default')) {
            throw new HttpException('No changes were supplied.', 422);
        }

        if (isset($supplied['pincode'])) {
            $serviceability = $this->delivery->checkServiceability((string) $supplied['pincode']);

            if (!$serviceability['is_serviceable']) {
                throw new HttpException(
                    'We do not currently deliver to this pincode.',
                    422,
                    ['pincode' => [$serviceability['message']]]
                );
            }
        }

        $this->db->transaction(function () use ($address, $supplied, $userId, $request): void {
            if ($supplied !== []) {
                $this->addresses->update((int) $address['id'], $supplied, $userId);
            }

            if ((int) $request->input('is_default', 0) === 1) {
                $this->addresses->makeDefault((int) $address['id'], $userId);
            }
        });

        return Response::success(
            ['address' => $this->present((array) $this->addresses->findById((int) $address['id']))],
            'Address updated'
        );
    }

    /** POST /api/v1/addresses/{uuid}/default */
    public function makeDefault(Request $request): Response
    {
        $userId = (int) $request->authUserId();
        $address = $this->requireOwned((string) $request->routeParam('uuid'), $userId);

        $this->addresses->makeDefault((int) $address['id'], $userId);

        return Response::success([], 'Default delivery address updated');
    }

    /** DELETE /api/v1/addresses/{uuid} */
    public function destroy(Request $request): Response
    {
        $userId = (int) $request->authUserId();
        $address = $this->requireOwned((string) $request->routeParam('uuid'), $userId);

        $this->db->transaction(function () use ($address, $userId): void {
            $this->addresses->softDelete((int) $address['id'], $userId);

            // Never leave a customer with addresses but no default; the next
            // checkout would have nothing preselected.
            if ((int) $address['is_default'] === 1) {
                $remaining = $this->addresses->forUser($userId);

                if ($remaining !== []) {
                    $this->addresses->makeDefault((int) $remaining[0]['id'], $userId);
                }
            }
        });

        return Response::success([], 'Address removed');
    }

    /** @return array<string, mixed> */
    private function validate(Request $request, bool $required): array
    {
        $prefix = $required ? 'required' : 'nullable';

        return Validator::make($request->all(), [
            'label' => 'nullable|string|max:50',
            'contact_name' => $prefix . '|string|min:2|max:120',
            'contact_mobile' => $prefix . '|mobile_in',
            'address_line1' => $prefix . '|string|min:5|max:255',
            'address_line2' => 'nullable|string|max:255',
            'landmark' => 'nullable|string|max:150',
            'city' => $prefix . '|string|min:2|max:100',
            'district' => 'nullable|string|max:100',
            'state' => $prefix . '|string|min:2|max:100',
            'pincode' => $prefix . '|digits:6',
            'country' => 'nullable|string|max:60',
            'address_type' => 'nullable|in:home,work,other',
            'is_default' => 'nullable|boolean',
        ]);
    }

    /** @return array<string, mixed> */
    private function requireOwned(string $uuid, int $userId): array
    {
        $address = $this->addresses->findByUuid($uuid);

        if ($address === null || (int) $address['user_id'] !== $userId) {
            throw new NotFoundException('That address does not exist.');
        }

        return $address;
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
            'label' => $row['label'],
            'contact_name' => $row['contact_name'],
            'contact_mobile' => $row['contact_mobile'],
            'address_line1' => $row['address_line1'],
            'address_line2' => $row['address_line2'],
            'landmark' => $row['landmark'],
            'city' => $row['city'],
            'district' => $row['district'],
            'state' => $row['state'],
            'pincode' => $row['pincode'],
            'country' => $row['country'],
            'address_type' => $row['address_type'],
            'is_default' => (bool) $row['is_default'],
        ];
    }
}
