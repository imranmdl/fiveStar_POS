<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use App\Core\Exceptions\HttpException;
use App\Core\Request;

/**
 * Permanently wipes chosen kinds of data, so a test store can be put back to
 * a clean state and the flows tried again (Admin → Backups → Reset data).
 *
 * Built to be hard to misuse on a live shop:
 *  - It only runs where the server sets ALLOW_DATA_RESET=true. A production
 *    store that never sets it cannot be wiped from the admin console at all.
 *  - A full backup is taken first, every time, before a single row goes —
 *    the same safety backup a restore takes — so a reset can be undone with
 *    "Restore" on the Backups page.
 *  - The caller must type the confirmation phrase.
 *
 * What is never touched: staff and administrator accounts, roles and
 * permissions, settings (including the storefront theme), warehouses,
 * couriers, delivery zones, notification templates, scheduled tasks and the
 * migration history — the things the app needs to run at all.
 *
 * Dependencies come from the database's own foreign keys, read at run time:
 * if a chosen group's rows are referenced by another group (orders point at
 * products, for example), that group is wiped too and preview() says why.
 * Nothing is left pointing at a row that no longer exists.
 */
final class DataResetService
{
    public const CONFIRM_PHRASE = 'RESET';

    /**
     * Groups the admin chooses from. `users` is handled separately: only
     * customer accounts are removed, never staff.
     *
     * @var array<string, array{label:string, hint:string, tables:array<int,string>, sequences:array<int,string>}>
     */
    private const GROUPS = [
        'orders' => [
            'label' => 'Orders, payments & deliveries',
            'hint' => 'Online orders, payments, refunds, shipments, manifests, carts, wishlists, bulk-order enquiries, reviews and support tickets. Order and invoice numbers start again from 1.',
            'tables' => [
                'orders', 'order_items', 'order_status_history', 'order_tax_lines', 'order_assignments',
                'packing_slips', 'payments', 'payment_events', 'refunds',
                'shipments', 'shipment_events', 'courier_selections', 'manifests', 'pickup_requests',
                'carts', 'cart_items', 'wishlist_items', 'coupon_redemptions',
                'commission_entries', 'commission_settlements',
                'bulk_order_enquiries', 'bulk_order_quotes', 'bulk_order_quote_items',
                'product_reviews', 'review_media', 'review_reports', 'review_votes', 'store_reviews',
                'support_tickets', 'support_ticket_messages',
            ],
            'sequences' => [
                'order', 'invoice', 'shipment', 'manifest', 'packing_slip',
                'bulk_enquiry', 'bulk_quote', 'settlement', 'ticket',
            ],
        ],
        'pos' => [
            'label' => 'Shop counter (POS) sales',
            'hint' => 'Counter sales, their payments and refunds, and the bill messages sent for them. POS bill numbers start again from 1.',
            'tables' => ['pos_sales', 'pos_sale_items', 'pos_sale_payments', 'pos_refunds', 'pos_refund_items', 'communication_log'],
            'sequences' => ['pos_sale', 'pos_refund'],
        ],
        'customers' => [
            'label' => 'Customer accounts',
            'hint' => 'Every customer login with their addresses, wallet, loyalty points, referrals and leads. Staff and administrator accounts are kept.',
            'tables' => [
                'user_addresses', 'wallet_accounts', 'wallet_transactions', 'wallet_credit_expiries',
                'loyalty_accounts', 'loyalty_ledger', 'loyalty_point_expiries', 'referrals', 'leads',
            ],
            'sequences' => [],
        ],
        'inventory' => [
            'label' => 'Stock & purchasing',
            'hint' => 'Stock levels and movements, purchase orders and returns, vendors and their payments, and import batches.',
            'tables' => [
                'inventory_stock', 'inventory_batches', 'inventory_movements',
                'purchase_orders', 'purchase_order_items', 'purchase_returns', 'purchase_return_items',
                'vendor_payments', 'vendors', 'import_batches', 'import_batch_items',
            ],
            'sequences' => ['purchase_order', 'purchase_return', 'vendor'],
        ],
        'catalogue' => [
            'label' => 'Products, categories & promotions',
            'hint' => 'Products and their packs, photos, categories, campaign pages, offers, coupons, pricing rules and banners.',
            'tables' => [
                'products', 'product_variants', 'product_media', 'product_attributes', 'product_nutrition',
                'product_variant_options', 'categories', 'category_option_types',
                'collections', 'collection_items', 'offers', 'offer_targets', 'coupons', 'coupon_targets',
                'pricing_rules', 'price_change_log', 'banners',
            ],
            'sequences' => [],
        ],
        'content' => [
            'label' => 'Pages, blog & FAQ',
            'hint' => 'Content pages (shipping, returns, privacy…), blog posts and FAQ entries.',
            'tables' => ['cms_pages', 'blog_posts', 'faq_entries'],
            'sequences' => [],
        ],
        'logs' => [
            'label' => 'Logs & history',
            'hint' => 'Activity and audit logs, login attempts, OTP requests, the notification queue and scheduled-task history.',
            'tables' => [
                'activity_logs', 'audit_logs', 'admin_privilege_activity', 'approval_requests',
                'login_attempts', 'otp_requests', 'rate_limits', 'notification_queue', 'scheduled_task_runs',
            ],
            'sequences' => [],
        ],
    ];

    /** Removing customer accounts means removing what they did, too. */
    private const CUSTOMER_REQUIRES = ['orders', 'pos'];

    public function __construct(
        private readonly Database $db,
        private readonly BackupService $backups,
        private readonly AuditService $audit,
    ) {
    }

    public function isEnabled(): bool
    {
        return Env::bool('ALLOW_DATA_RESET', false);
    }

    /**
     * What each group holds right now, which groups a selection pulls in and
     * why — nothing is changed.
     *
     * @param array<int, string> $selected
     *
     * @return array<string, mixed>
     */
    public function preview(array $selected = []): array
    {
        $existing = $this->existingTables();
        $groups = [];

        foreach (self::GROUPS as $key => $group) {
            $tables = array_values(array_filter($group['tables'], static fn (string $t): bool => isset($existing[$t])));
            $rows = 0;

            foreach ($tables as $table) {
                $rows += $this->count($table);
            }

            if ($key === 'customers') {
                $rows += $this->customerCount();
            }

            $groups[] = [
                'key' => $key,
                'label' => $group['label'],
                'hint' => $group['hint'],
                'rows' => $rows,
                'customers' => $key === 'customers' ? $this->customerCount() : null,
            ];
        }

        $plan = $this->plan($this->validGroups($selected), $existing);

        return [
            'enabled' => $this->isEnabled(),
            'confirm_phrase' => self::CONFIRM_PHRASE,
            'groups' => $groups,
            'selected' => $plan['groups'],
            'added' => $plan['added'],
        ];
    }

    /**
     * @param array<int, string> $selected
     *
     * @return array<string, mixed>
     */
    public function run(array $selected, string $confirmation, Request $request): array
    {
        if (!$this->isEnabled()) {
            throw new HttpException(
                'Resetting data is switched off on this server. Set ALLOW_DATA_RESET=true in the server environment (only on a test store) and redeploy.',
                403
            );
        }

        if (trim($confirmation) !== self::CONFIRM_PHRASE) {
            throw new HttpException(
                'Type ' . self::CONFIRM_PHRASE . ' to confirm.',
                422,
                ['confirm' => ['Type ' . self::CONFIRM_PHRASE . ' exactly to confirm.']]
            );
        }

        $groups = $this->validGroups($selected);

        if ($groups === []) {
            throw new HttpException('Choose at least one kind of data to delete.', 422, [
                'groups' => ['Choose at least one kind of data to delete.'],
            ]);
        }

        $existing = $this->existingTables();
        $plan = $this->plan($groups, $existing);

        // Always first, never optional: this is what makes a reset undoable.
        $safetyBackup = $this->backups->create($request, 'pre_reset_safety');

        $pdo = $this->db->pdo();
        $deleted = [];
        $customersDeleted = 0;

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($plan['tables'] as $table) {
                $deleted[$table] = $this->count($table);
                // DELETE rather than TRUNCATE: TRUNCATE commits implicitly and
                // needs the DROP privilege, which some hosts withhold.
                $pdo->exec('DELETE FROM `' . $table . '`');
                try {
                    // Fresh ids for a fresh test run; harmless if the host refuses ALTER.
                    $pdo->exec('ALTER TABLE `' . $table . '` AUTO_INCREMENT = 1');
                } catch (\PDOException) {
                    // Ids simply carry on from where they were.
                }
            }

            if (in_array('customers', $plan['groups'], true)) {
                $customersDeleted = $this->deleteCustomers($existing, $plan['tables']);
            }

            foreach ($plan['sequences'] as $sequence) {
                $this->db->execute(
                    'UPDATE numbering_sequences SET last_number = 0 WHERE sequence_key LIKE :key',
                    ['key' => $sequence . ':%']
                );
            }

            $this->detachKeptRows($plan['tables'], $existing);
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }

        $total = array_sum($deleted) + $customersDeleted;

        // Written after the wipe so it survives even when the logs were wiped.
        $this->audit->log(
            entityName: 'data_reset',
            entityId: null,
            action: 'reset',
            newValues: ['groups' => $plan['groups'], 'rows_deleted' => $total, 'customers_deleted' => $customersDeleted],
            request: $request,
            notes: 'Safety backup taken first: ' . $safetyBackup['filename']
        );

        return [
            'groups' => $plan['groups'],
            'added' => $plan['added'],
            'total_deleted' => $total,
            'customers_deleted' => $customersDeleted,
            'tables' => array_map(
                static fn (string $table, int $rows): array => ['table' => $table, 'deleted' => $rows],
                array_keys($deleted),
                array_values($deleted)
            ),
            'safety_backup' => $safetyBackup,
        ];
    }

    // -----------------------------------------------------------------------

    /**
     * Expands the chosen groups along the foreign keys until nothing kept
     * points at anything wiped.
     *
     * @param array<int, string> $groups
     * @param array<string, true> $existing
     *
     * @return array{groups:array<int,string>, added:array<int,array{group:string,label:string,because:string}>, tables:array<int,string>, sequences:array<int,string>}
     */
    private function plan(array $groups, array $existing): array
    {
        $tableGroup = [];
        foreach (self::GROUPS as $key => $group) {
            foreach ($group['tables'] as $table) {
                $tableGroup[$table] = $key;
            }
        }

        $references = $this->references();
        $chosen = array_fill_keys($groups, true);
        $added = [];

        $addGroup = function (string $group, string $because) use (&$chosen, &$added): bool {
            if (isset($chosen[$group])) {
                return false;
            }
            $chosen[$group] = true;
            $added[] = ['group' => $group, 'label' => self::GROUPS[$group]['label'], 'because' => $because];

            return true;
        };

        do {
            $changed = false;

            if (isset($chosen['customers'])) {
                foreach (self::CUSTOMER_REQUIRES as $required) {
                    $changed = $addGroup($required, 'their records belong to the customer accounts being removed') || $changed;
                }
            }

            foreach (array_keys($chosen) as $group) {
                foreach (self::GROUPS[$group]['tables'] as $parent) {
                    foreach ($references[$parent] ?? [] as $child) {
                        $childGroup = $tableGroup[$child] ?? null;

                        if ($childGroup !== null && !isset($chosen[$childGroup])) {
                            $changed = $addGroup(
                                $childGroup,
                                sprintf('%s refer to %s', $this->humanise($child), $this->humanise($parent))
                            ) || $changed;
                        }
                    }
                }
            }
        } while ($changed);

        $ordered = array_values(array_filter(array_keys(self::GROUPS), static fn (string $g): bool => isset($chosen[$g])));
        $tables = [];
        $sequences = [];

        foreach ($ordered as $group) {
            foreach (self::GROUPS[$group]['tables'] as $table) {
                if (isset($existing[$table])) {
                    $tables[] = $table;
                }
            }
            array_push($sequences, ...self::GROUPS[$group]['sequences']);
        }

        return ['groups' => $ordered, 'added' => $added, 'tables' => $tables, 'sequences' => $sequences];
    }

    /**
     * Removes customer logins (never staff) and anything still tied to them
     * in tables that are otherwise kept.
     *
     * @param array<string, true> $existing
     * @param array<int, string> $wiped
     */
    private function deleteCustomers(array $existing, array $wiped): int
    {
        $ids = array_map(
            'intval',
            array_column(
                $this->db->select(
                    "SELECT u.id AS id FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'customer'"
                ),
                'id'
            )
        );

        if ($ids === []) {
            return 0;
        }

        $wipedSet = array_fill_keys($wiped, true);

        foreach (array_chunk($ids, 500) as $chunk) {
            $in = implode(',', $chunk);

            foreach ($this->foreignKeysTo('users') as [$child, $column, $nullable]) {
                if ($child === 'users' || isset($wipedSet[$child]) || !isset($existing[$child])) {
                    continue;
                }

                if ($nullable) {
                    $this->db->execute("UPDATE `{$child}` SET `{$column}` = NULL WHERE `{$column}` IN ({$in})");
                } else {
                    $this->db->execute("DELETE FROM `{$child}` WHERE `{$column}` IN ({$in})");
                }
            }

            $this->db->execute("UPDATE users SET referred_by_user_id = NULL WHERE referred_by_user_id IN ({$in})");
            $this->db->execute("DELETE FROM users WHERE id IN ({$in})");
        }

        return count($ids);
    }

    /**
     * A kept table may hold an optional link to a wiped one (a staff
     * profile's last warehouse, say). Clear such links so nothing points at
     * a row that is gone.
     *
     * @param array<int, string> $wiped
     * @param array<string, true> $existing
     */
    private function detachKeptRows(array $wiped, array $existing): void
    {
        $wipedSet = array_fill_keys($wiped, true);

        foreach ($wiped as $parent) {
            foreach ($this->foreignKeysTo($parent) as [$child, $column, $nullable]) {
                if (isset($wipedSet[$child]) || !isset($existing[$child]) || !$nullable) {
                    continue;
                }
                $this->db->execute("UPDATE `{$child}` SET `{$column}` = NULL WHERE `{$column}` IS NOT NULL");
            }
        }
    }

    /** @return array<string, array<int, string>> parent table => child tables that reference it */
    private function references(): array
    {
        // Aliased: MySQL 8 returns information_schema columns in upper case.
        $rows = $this->db->select(
            'SELECT DISTINCT table_name AS child, referenced_table_name AS parent
               FROM information_schema.key_column_usage
              WHERE table_schema = DATABASE() AND referenced_table_name IS NOT NULL'
        );

        $map = [];
        foreach ($rows as $row) {
            if ($row['child'] !== $row['parent']) {
                $map[(string) $row['parent']][] = (string) $row['child'];
            }
        }

        return $map;
    }

    /** @return array<int, array{0:string,1:string,2:bool}> [child table, column, nullable] */
    private function foreignKeysTo(string $parent): array
    {
        $rows = $this->db->select(
            'SELECT k.table_name AS child, k.column_name AS col, c.is_nullable AS nullable
               FROM information_schema.key_column_usage k
               JOIN information_schema.columns c
                 ON c.table_schema = k.table_schema AND c.table_name = k.table_name AND c.column_name = k.column_name
              WHERE k.table_schema = DATABASE() AND k.referenced_table_name = :parent',
            ['parent' => $parent]
        );

        return array_map(
            static fn (array $r): array => [(string) $r['child'], (string) $r['col'], strtoupper((string) $r['nullable']) === 'YES'],
            $rows
        );
    }

    /** @return array<string, true> */
    private function existingTables(): array
    {
        $rows = $this->db->select(
            "SELECT table_name AS t FROM information_schema.tables
              WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'"
        );

        return array_fill_keys(array_map(static fn (array $r): string => (string) $r['t'], $rows), true);
    }

    /**
     * @param array<int, mixed> $selected
     *
     * @return array<int, string>
     */
    private function validGroups(array $selected): array
    {
        return array_values(array_filter(
            array_keys(self::GROUPS),
            static fn (string $g): bool => in_array($g, $selected, true)
        ));
    }

    private function count(string $table): int
    {
        return (int) $this->db->scalar('SELECT COUNT(*) FROM `' . $table . '`');
    }

    private function customerCount(): int
    {
        return (int) $this->db->scalar(
            "SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id WHERE r.code = 'customer'"
        );
    }

    private function humanise(string $table): string
    {
        return str_replace('_', ' ', $table);
    }
}
