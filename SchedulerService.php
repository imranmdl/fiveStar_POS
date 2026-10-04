<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Logger;
use App\Core\Request;
use App\Helpers\Uuid;
use App\Repositories\CourierRepository;
use App\Repositories\SettingRepository;

/**
 * Runs due background work.
 *
 * Cron calls one entry point every minute; this class decides what is actually
 * due. That is deliberate — seven crontab lines drift, get commented out during
 * an incident and never restored, and offer no record of what ran.
 *
 * THE LOCK IS THE IMPORTANT PART. `locked_until` is claimed with a conditional
 * UPDATE, so on two application servers running the same crontab exactly one
 * wins. Without it both would expire the same unpaid orders, and each would
 * return the same wallet credit to the same customer — real money, twice.
 *
 * A task that throws is recorded and the others still run. One failing courier
 * API must not stop notifications going out.
 */
final class SchedulerService
{
    /** How long a task may hold its lock before another runner may take it. */
    private const LOCK_MINUTES = 30;

    public function __construct(
        private readonly PaymentService $payments,
        private readonly ShipmentService $shipments,
        private readonly NotificationService $notifications,
        private readonly WalletService $wallet,
        private readonly CourierRepository $couriers,
        private readonly SettingRepository $settings,
        private readonly Database $db,
        private readonly Logger $logger,
    ) {
    }

    /**
     * Runs everything due now.
     *
     * @return array<string, mixed>
     */
    public function run(Request $request, ?string $only = null): array
    {
        $runner = gethostname() . ':' . getmypid();
        $results = [];

        $sql = 'SELECT * FROM `scheduled_tasks` WHERE `is_enabled` = 1 AND `is_deleted` = 0';
        $bindings = [];

        if ($only !== null) {
            // Naming a task means "run this now". The due-time check is what
            // cron needs; an operator asking for one task during an incident
            // wants it to run, not to be told it is not scheduled for another
            // eleven minutes. The LOCK still applies — that is about safety
            // rather than timing, and two runners must still not collide.
            $sql .= ' AND `code` = :code';
            $bindings['code'] = $only;
        } else {
            $sql .= ' AND (`next_run_date` IS NULL OR `next_run_date` <= NOW())';
        }

        $sql .= ' ORDER BY `next_run_date` ASC';

        foreach ($this->db->select($sql, $bindings) as $task) {
            $results[] = $this->runTask($task, $runner, $request);
        }

        return [
            'runner' => $runner,
            'tasks_run' => count(array_filter($results, static fn (array $r): bool => $r['status'] !== 'skipped')),
            'tasks_skipped' => count(array_filter($results, static fn (array $r): bool => $r['status'] === 'skipped')),
            'results' => $results,
        ];
    }

    /**
     * @param array<string, mixed> $task
     *
     * @return array<string, mixed>
     */
    private function runTask(array $task, string $runner, Request $request): array
    {
        // Claim the lock. The WHERE clause is what makes this safe: only one
        // runner can move the row from unlocked to locked.
        $claimed = $this->db->execute(
            'UPDATE `scheduled_tasks`
                SET `locked_until` = DATE_ADD(NOW(), INTERVAL :minutes MINUTE),
                    `locked_by` = :runner,
                    `updated_date` = NOW(), `version` = `version` + 1
              WHERE `id` = :id
                AND (`locked_until` IS NULL OR `locked_until` < NOW())',
            ['minutes' => self::LOCK_MINUTES, 'runner' => $runner, 'id' => (int) $task['id']]
        );

        if ($claimed === 0) {
            return [
                'code' => $task['code'],
                'status' => 'skipped',
                'summary' => 'Already running elsewhere (locked by ' . ($task['locked_by'] ?? 'unknown') . ').',
            ];
        }

        $runId = (int) $this->db->insert(
            'INSERT INTO `scheduled_task_runs`
                 (`uuid`, `task_id`, `started_date`, `status`, `runner`,
                  `created_date`, `is_active`, `is_deleted`, `version`)
             VALUES (:uuid, :task_id, NOW(), \'running\', :runner, NOW(), 1, 0, 1)',
            ['uuid' => Uuid::v4(), 'task_id' => (int) $task['id'], 'runner' => $runner]
        );

        $started = microtime(true);
        $status = 'success';
        $summary = '';
        $error = null;

        try {
            $summary = $this->execute((string) $task['code'], $request);
        } catch (\Throwable $exception) {
            $status = 'failed';
            $error = $exception->getMessage();
            $summary = 'Failed: ' . substr($exception->getMessage(), 0, 400);

            $this->logger->error('Scheduled task failed', [
                'task' => $task['code'],
                'error' => $exception->getMessage(),
            ], 'scheduler');
        }

        $durationMs = (int) round((microtime(true) - $started) * 1000);

        $this->db->execute(
            'UPDATE `scheduled_task_runs`
                SET `finished_date` = NOW(), `duration_ms` = :duration, `status` = :status,
                    `summary` = :summary, `error` = :error,
                    `updated_date` = NOW(), `version` = `version` + 1
              WHERE `id` = :id',
            [
                'duration' => $durationMs,
                'status' => $status,
                'summary' => substr($summary, 0, 500),
                'error' => $error,
                'id' => $runId,
            ]
        );

        // The lock is always released, including after a failure — otherwise one
        // exception silently disables the task for the length of the lock.
        $this->db->execute(
            'UPDATE `scheduled_tasks`
                SET `last_run_date` = NOW(), `last_run_status` = :status,
                    `last_run_summary` = :summary, `last_duration_ms` = :duration,
                    `next_run_date` = DATE_ADD(NOW(), INTERVAL `interval_minutes` MINUTE),
                    `consecutive_failures` = CASE WHEN :is_failure = 1
                                                  THEN `consecutive_failures` + 1 ELSE 0 END,
                    `locked_until` = NULL, `locked_by` = NULL,
                    `updated_date` = NOW(), `version` = `version` + 1
              WHERE `id` = :id',
            [
                'status' => $status,
                'summary' => substr($summary, 0, 500),
                'duration' => $durationMs,
                'is_failure' => $status === 'failed' ? 1 : 0,
                'id' => (int) $task['id'],
            ]
        );

        return [
            'code' => $task['code'],
            'status' => $status,
            'summary' => $summary,
            'duration_ms' => $durationMs,
        ];
    }

    /** Dispatches a task code to the service that owns the work. */
    private function execute(string $code, Request $request): string
    {
        return match ($code) {
            'notifications.dispatch' => $this->summarise(
                $this->notifications->dispatchQueue(),
                fn (array $r): string => sprintf('%d sent, %d failed.', $r['sent'], $r['failed'])
            ),

            'orders.expire_unpaid' => $this->summarise(
                $this->payments->expireUnpaidOrders($request),
                fn (array $r): string => sprintf(
                    '%d order(s) released, %s wallet credit returned, %d coupon(s) released.',
                    $r['expired_count'],
                    $r['wallet_returned'],
                    $r['coupons_released']
                )
            ),

            'shipments.refresh_tracking' => $this->summarise(
                $this->shipments->refreshStaleShipments($request),
                fn (array $r): string => sprintf(
                    '%d shipment(s) refreshed, %d new scan(s), %d failure(s).',
                    $r['refreshed'],
                    $r['new_events'],
                    $r['failures']
                )
            ),

            'carts.abandoned' => $this->abandonedCartReminders(),

            'wallet.expire_credits' => $this->summarise(
                $this->wallet->expireCredits(),
                fn (array $r): string => sprintf('%d credit(s) expired.', $r['expired_count'] ?? 0)
            ),

            'promotions.expire' => $this->expirePromotions(),

            'couriers.rescore' => sprintf(
                '%d courier(s) rescored from delivery outcomes.',
                $this->couriers->recalculateReliability()
            ),

            default => 'Unknown task code; nothing to do.',
        };
    }

    /**
     * One reminder per abandoned cart, ever.
     *
     * The dedupe key is the cart uuid rather than the date, so a cart that sits
     * abandoned for a week produces one message rather than seven. A customer
     * nagged daily about the same cart unsubscribes, and then cannot be reached
     * about anything.
     */
    private function abandonedCartReminders(): string
    {
        $hours = max(1, $this->settings->intValue('abandoned_cart_hours', 6));

        $carts = $this->db->select(
            sprintf(
                "SELECT c.`uuid`, c.`user_id`, u.`full_name`,
                        (SELECT COUNT(*) FROM `cart_items` ci
                          WHERE ci.`cart_id` = c.`id` AND ci.`is_deleted` = 0
                            AND ci.`is_saved_for_later` = 0) AS `item_count`
                   FROM `carts` c
                   INNER JOIN `users` u ON u.`id` = c.`user_id`
                  WHERE c.`status` = 'active'
                    AND c.`user_id` IS NOT NULL
                    AND c.`is_deleted` = 0
                    AND c.`updated_date` < DATE_SUB(NOW(), INTERVAL %d HOUR)
                    AND c.`updated_date` > DATE_SUB(NOW(), INTERVAL 30 DAY)
                  HAVING `item_count` > 0
                  LIMIT 200",
                $hours
            )
        );

        $queued = 0;

        foreach ($carts as $cart) {
            $result = $this->notifications->queue(
                'cart.abandoned',
                'sms',
                ['item_count' => (int) $cart['item_count'], 'customer_name' => $cart['full_name']],
                [
                    'user_id' => (int) $cart['user_id'],
                    'reference_type' => 'carts',
                    'reference_id' => (string) $cart['uuid'],
                    'dedupe_key' => 'cart.abandoned:' . $cart['uuid'],
                ]
            );

            if ($result['queued']) {
                ++$queued;
            }
        }

        return sprintf('%d cart(s) idle, %d reminder(s) queued.', count($carts), $queued);
    }

    private function expirePromotions(): string
    {
        $coupons = $this->db->execute(
            "UPDATE `coupons`
                SET `status` = 'expired', `updated_date` = NOW(), `version` = `version` + 1
              WHERE `status` = 'active' AND `valid_to` IS NOT NULL AND `valid_to` < NOW()
                AND `is_deleted` = 0"
        );

        // offers dates its window with starts_date/ends_date; coupons use
        // valid_from/valid_to. Different vocabularies for the same idea, which
        // is worth knowing before writing a query against either.
        $offers = $this->db->execute(
            "UPDATE `offers`
                SET `status` = 'expired', `updated_date` = NOW(), `version` = `version` + 1
              WHERE `status` = 'active' AND `ends_date` IS NOT NULL AND `ends_date` < NOW()
                AND `is_deleted` = 0"
        );

        return sprintf('%d coupon(s) and %d offer(s) expired.', $coupons, $offers);
    }

    /**
     * @param array<string, mixed> $result
     * @param callable(array<string, mixed>): string $formatter
     */
    private function summarise(array $result, callable $formatter): string
    {
        return $formatter($result);
    }

    /** @return array<int, array<string, mixed>> */
    public function tasks(): array
    {
        return $this->db->select(
            'SELECT `uuid`, `code`, `name`, `description`, `interval_minutes`, `is_enabled`,
                    `last_run_date`, `last_run_status`, `last_run_summary`, `last_duration_ms`,
                    `next_run_date`, `consecutive_failures`, `locked_until`, `locked_by`
               FROM `scheduled_tasks`
              WHERE `is_deleted` = 0
              ORDER BY `code`'
        );
    }

    /** @return array<int, array<string, mixed>> */
    public function recentRuns(int $limit = 50): array
    {
        return $this->db->select(
            sprintf(
                'SELECT t.`code`, r.`started_date`, r.`finished_date`, r.`duration_ms`,
                        r.`status`, r.`summary`, r.`error`, r.`runner`
                   FROM `scheduled_task_runs` r
                   INNER JOIN `scheduled_tasks` t ON t.`id` = r.`task_id`
                  ORDER BY r.`started_date` DESC LIMIT %d',
                max(1, min($limit, 200))
            )
        );
    }
}
