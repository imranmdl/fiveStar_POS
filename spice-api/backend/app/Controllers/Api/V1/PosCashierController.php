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
use App\Repositories\UserRepository;
use App\Services\AuditService;

/**
 * Till operators (role `cashier`): one login per person, so every counter sale
 * is attributed to whoever actually rang it up. Administrator only — a cashier
 * must not be able to create, suspend or read another cashier's takings.
 */
final class PosCashierController extends BaseController
{
    public function __construct(
        private readonly Database $db,
        private readonly UserRepository $users,
        private readonly AuditService $audit,
    ) {
    }

    /** GET /api/v1/admin/pos/cashiers — every cashier with today's figures. */
    public function index(Request $request): Response
    {
        $rows = $this->db->select(
            "SELECT u.`uuid`, u.`full_name`, u.`mobile`, u.`email`, u.`status`, u.`is_active`,
                    u.`last_login_date`, u.`created_date`,
                    COUNT(s.`id`)                              AS `sales_today`,
                    COALESCE(SUM(s.`grand_total`), 0)          AS `total_today`,
                    MAX(s.`created_date`)                      AS `last_sale_date`,
                    GROUP_CONCAT(DISTINCT w.`name` ORDER BY w.`name` SEPARATOR ', ') AS `shops_today`
               FROM `users` u
               INNER JOIN `roles` r ON r.`id` = u.`role_id` AND r.`code` = 'cashier'
               LEFT JOIN `pos_sales` s ON s.`cashier_id` = u.`id`
                    AND s.`status` = 'completed' AND s.`is_deleted` = 0
                    AND DATE(s.`created_date`) = CURDATE()
               LEFT JOIN `warehouses` w ON w.`id` = s.`warehouse_id`
              WHERE u.`is_deleted` = 0
              GROUP BY u.`id`
              ORDER BY u.`full_name`"
        );

        return Response::success(['cashiers' => array_map(static fn (array $r): array => [
            'uuid' => $r['uuid'],
            'full_name' => $r['full_name'],
            'mobile' => $r['mobile'],
            'email' => $r['email'],
            'status' => $r['status'],
            'is_active' => (int) $r['is_active'] === 1 && $r['status'] === 'active',
            'last_login_date' => $r['last_login_date'],
            'last_sale_date' => $r['last_sale_date'],
            'sales_today' => (int) $r['sales_today'],
            'total_today' => round((float) $r['total_today'], 2),
            'shops_today' => $r['shops_today'],
        ], $rows)], 'Cashiers loaded');
    }

    /** POST /api/v1/admin/pos/cashiers — a new till login. */
    public function store(Request $request): Response
    {
        $data = Validator::make($request->all(), [
            'full_name' => 'required|string|min:3|max:120',
            'mobile' => 'required|mobile_in',
            'email' => 'nullable|email|max:150',
            'password' => 'required|password|max:72',
        ]);

        if ($this->users->mobileExists($data['mobile'])) {
            throw new HttpException('That mobile number already has a login.', 409, [
                'mobile' => ['This mobile number is already registered.'],
            ]);
        }

        if (!empty($data['email']) && $this->users->emailExists((string) $data['email'])) {
            throw new HttpException('That email address already has a login.', 409, [
                'email' => ['This email address is already registered.'],
            ]);
        }

        $roleId = (int) $this->db->scalar("SELECT `id` FROM `roles` WHERE `code` = 'cashier' LIMIT 1");

        do {
            $referral = 'C' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 9));
        } while ($this->users->referralCodeExists($referral));

        $userId = $this->users->create([
            'role_id' => $roleId,
            'full_name' => $data['full_name'],
            'mobile' => $data['mobile'],
            'email' => empty($data['email']) ? null : strtolower((string) $data['email']),
            'password_hash' => password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]),
            'status' => 'active',
            'mobile_verified_date' => date('Y-m-d H:i:s'),
            'referral_code' => $referral,
        ], $request->authUserId());

        $this->audit->log(
            entityName: 'users',
            entityId: $userId,
            action: 'cashier_created',
            newValues: ['full_name' => $data['full_name']],
            request: $request,
        );

        $user = $this->users->findById($userId);

        return Response::created(['uuid' => $user['uuid']], 'Cashier login created');
    }

    /** PATCH /api/v1/admin/pos/cashiers/{uuid} — suspend/reactivate or reset the password. */
    public function update(Request $request): Response
    {
        $user = $this->requireCashier((string) $request->routeParam('uuid'));

        $data = Validator::make($request->all(), [
            'status' => 'nullable|in:active,suspended',
            'password' => 'nullable|password|max:72',
        ]);

        $changes = [];

        if (!empty($data['status'])) {
            $changes['status'] = $data['status'];
        }

        if (!empty($data['password'])) {
            $changes['password_hash'] = password_hash($data['password'], PASSWORD_BCRYPT, ['cost' => 12]);
        }

        if ($changes === []) {
            throw new HttpException('No changes were supplied.', 422);
        }

        // Signs the person out everywhere: a suspended or re-keyed till must
        // not keep working on a token issued before the change.
        $changes['tokens_valid_from'] = date('Y-m-d H:i:s');

        $this->users->update((int) $user['id'], $changes, $request->authUserId());

        $this->audit->log(
            entityName: 'users',
            entityId: (int) $user['id'],
            action: 'cashier_updated',
            newValues: ['status' => $data['status'] ?? null, 'password_reset' => !empty($data['password'])],
            request: $request,
        );

        return Response::success(null, 'Cashier updated');
    }

    /**
     * GET /api/v1/admin/pos/cashiers/{uuid}/history?from=&to=
     * Defaults to today. Totals exclude voided sales; voids are counted separately.
     */
    public function history(Request $request): Response
    {
        $user = $this->requireCashier((string) $request->routeParam('uuid'));

        $today = date('Y-m-d');
        $from = (string) ($request->query('from') ?: $today);
        $to = (string) ($request->query('to') ?: $today);

        if (strtotime($from) === false || strtotime($to) === false || $to < $from) {
            throw new HttpException('Choose a valid date range.', 422);
        }

        if ((strtotime($to) - strtotime($from)) / 86400 > 366) {
            throw new HttpException('Choose a range of a year or less.', 422);
        }

        $bind = ['cashier' => (int) $user['id'], 'from' => $from, 'to' => $to];

        $summary = $this->db->selectOne(
            "SELECT COUNT(*) AS `sales`, COALESCE(SUM(`grand_total`), 0) AS `total`,
                    COALESCE(SUM(`discount_amount`), 0) AS `discount`
               FROM `pos_sales`
              WHERE `cashier_id` = :cashier AND `status` = 'completed' AND `is_deleted` = 0
                AND DATE(`created_date`) BETWEEN :from AND :to",
            $bind
        );

        $voided = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM `pos_sales`
              WHERE `cashier_id` = :cashier AND `status` = 'voided' AND `is_deleted` = 0
                AND DATE(`created_date`) BETWEEN :from AND :to",
            $bind
        );

        $byMethod = $this->db->select(
            "SELECT `payment_method`, COUNT(*) AS `sales`, COALESCE(SUM(`grand_total`), 0) AS `total`
               FROM `pos_sales`
              WHERE `cashier_id` = :cashier AND `status` = 'completed' AND `is_deleted` = 0
                AND DATE(`created_date`) BETWEEN :from AND :to
              GROUP BY `payment_method` ORDER BY `total` DESC",
            $bind
        );

        $byShop = $this->db->select(
            "SELECT w.`name` AS `shop`, COUNT(*) AS `sales`, COALESCE(SUM(s.`grand_total`), 0) AS `total`,
                    MIN(s.`created_date`) AS `first_sale`, MAX(s.`created_date`) AS `last_sale`
               FROM `pos_sales` s
               INNER JOIN `warehouses` w ON w.`id` = s.`warehouse_id`
              WHERE s.`cashier_id` = :cashier AND s.`status` = 'completed' AND s.`is_deleted` = 0
                AND DATE(s.`created_date`) BETWEEN :from AND :to
              GROUP BY w.`id`, w.`name` ORDER BY `total` DESC",
            $bind
        );

        $byDay = $this->db->select(
            "SELECT DATE(`created_date`) AS `date`, COUNT(*) AS `sales`, COALESCE(SUM(`grand_total`), 0) AS `total`
               FROM `pos_sales`
              WHERE `cashier_id` = :cashier AND `status` = 'completed' AND `is_deleted` = 0
                AND DATE(`created_date`) BETWEEN :from AND :to
              GROUP BY DATE(`created_date`) ORDER BY `date` DESC",
            $bind
        );

        $sales = $this->db->select(
            "SELECT s.`uuid`, s.`sale_number`, s.`created_date`, s.`payment_method`, s.`grand_total`,
                    s.`status`, w.`name` AS `shop`
               FROM `pos_sales` s
               INNER JOIN `warehouses` w ON w.`id` = s.`warehouse_id`
              WHERE s.`cashier_id` = :cashier AND s.`is_deleted` = 0
                AND DATE(s.`created_date`) BETWEEN :from AND :to
              ORDER BY s.`created_date` DESC LIMIT 200",
            $bind
        );

        $money = static fn (mixed $v): float => round((float) $v, 2);

        return Response::success([
            'cashier' => [
                'uuid' => $user['uuid'],
                'full_name' => $user['full_name'],
                'mobile' => $user['mobile'],
                'last_login_date' => $user['last_login_date'],
            ],
            'from' => $from,
            'to' => $to,
            'summary' => [
                'sales' => (int) $summary['sales'],
                'total' => $money($summary['total']),
                'discount' => $money($summary['discount']),
                'voided' => $voided,
            ],
            'by_payment_method' => array_map(static fn (array $r): array => [
                'payment_method' => $r['payment_method'], 'sales' => (int) $r['sales'], 'total' => $money($r['total']),
            ], $byMethod),
            'by_shop' => array_map(static fn (array $r): array => [
                'shop' => $r['shop'], 'sales' => (int) $r['sales'], 'total' => $money($r['total']),
                'first_sale' => $r['first_sale'], 'last_sale' => $r['last_sale'],
            ], $byShop),
            'by_day' => array_map(static fn (array $r): array => [
                'date' => $r['date'], 'sales' => (int) $r['sales'], 'total' => $money($r['total']),
            ], $byDay),
            'sales' => array_map(static fn (array $r): array => [
                'sale_number' => $r['sale_number'], 'created_date' => $r['created_date'],
                'payment_method' => $r['payment_method'], 'total' => $money($r['grand_total']),
                'status' => $r['status'], 'shop' => $r['shop'],
            ], $sales),
        ], 'Cashier history loaded');
    }

    /** @return array<string, mixed> */
    private function requireCashier(string $uuid): array
    {
        $user = $this->users->findByUuid($uuid);

        if ($user === null || ($user['role_code'] ?? null) !== 'cashier') {
            throw new NotFoundException('That cashier does not exist.');
        }

        return $user;
    }
}
