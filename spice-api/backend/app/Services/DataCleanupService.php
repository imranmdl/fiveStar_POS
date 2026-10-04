<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;

/**
 * Hard-deletes rows that are ALREADY soft-deleted (is_deleted = 1) and have
 * sat that way past a retention window. This never touches a live row — a
 * row only becomes eligible once whatever feature owns it already chose to
 * soft-delete it; this is the second, separate step of actually reclaiming
 * that space, on a deliberate schedule rather than immediately.
 *
 * Eligible tables are discovered from the schema itself (every table
 * carrying both `is_deleted` and `deleted_date`, the standard audit-column
 * pair this project's migrations all use — see any CREATE TABLE in
 * spice-api/database/migrations) rather than hand-maintained here, so a new
 * table automatically participates without this class needing an edit.
 */
final class DataCleanupService
{
    // 0 is deliberately allowed: an admin who wants to sweep up everything
    // already soft-deleted, including something removed earlier today, needs
    // that option — retention is a choice the admin makes per run, not a
    // floor this service enforces.
    private const MIN_RETENTION_DAYS = 0;

    /**
     * Excluded even though they carry the audit columns:
     *  - wallet_transactions: DB triggers reject any DELETE outright (see
     *    004_promotions_wallet.sql) — an append-only ledger, by design.
     *  - audit_logs: the record of what happened to everything else. Purging
     *    it here would let this very cleanup erase its own trail.
     */
    private const EXCLUDED_TABLES = ['wallet_transactions', 'audit_logs'];

    /**
     * Ceiling on rows returned per table in preview() — not a "sample" any
     * more, the actual full list, so an admin can look at every row and
     * choose which ones to keep before anything is deleted. Only a safety
     * cap against something pathological (a table with tens of thousands of
     * eligible rows), not a normal limit for this small a business.
     */
    private const PREVIEW_MAX_ROWS_PER_TABLE = 2000;

    /**
     * Column checked, in this order, for something human-readable to show
     * next to a row's id in the preview — the first one that actually
     * exists on a given table wins. There is no single "name" column common
     * to all 100+ eligible tables, so this is a best-effort label, not a
     * schema this class otherwise needs to know about.
     */
    private const LABEL_COLUMN_PRIORITY = [
        'name', 'full_name', 'title', 'sale_number', 'order_number',
        'shipment_number', 'variant_name', 'sku', 'code', 'subject',
    ];

    public function __construct(
        private readonly Database $db,
        private readonly AuditService $audit,
    ) {
    }

    /**
     * Every eligible table's columns, fetched once so labelColumnFor()
     * below doesn't run a fresh information_schema query per table.
     *
     * @return array<string, array<int, string>>
     */
    private function columnsByTable(): array
    {
        $rows = $this->db->select(
            "SELECT table_name, column_name
               FROM information_schema.columns
              WHERE table_schema = DATABASE()"
        );

        $byTable = [];

        foreach ($rows as $row) {
            $byTable[(string) $row['table_name']][] = (string) $row['column_name'];
        }

        return $byTable;
    }

    private function labelColumnFor(array $columns): ?string
    {
        foreach (self::LABEL_COLUMN_PRIORITY as $candidate) {
            if (in_array($candidate, $columns, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /** @return array<int, string> */
    private function eligibleTables(): array
    {
        $rows = $this->db->select(
            "SELECT DISTINCT table_name
               FROM information_schema.columns
              WHERE table_schema = DATABASE()
                AND column_name IN ('is_deleted', 'deleted_date')
              GROUP BY table_name
             HAVING COUNT(DISTINCT column_name) = 2
              ORDER BY table_name"
        );

        return array_values(array_filter(
            array_map(static fn (array $row): string => (string) $row['table_name'], $rows),
            static fn (string $table): bool => !in_array($table, self::EXCLUDED_TABLES, true)
        ));
    }

    private function assertRetention(int $retentionDays): void
    {
        if ($retentionDays < self::MIN_RETENTION_DAYS) {
            throw new HttpException(
                sprintf('Retention must be at least %d days.', self::MIN_RETENTION_DAYS),
                422,
                ['retention_days' => [sprintf('Enter %d or more.', self::MIN_RETENTION_DAYS)]]
            );
        }
    }

    /**
     * Counts, AND every one of the rows themselves — nothing is deleted.
     * What run() would do, named first.
     *
     * Showing only a per-table count ("products: 5") answers "how many" but
     * not "which ones" — an admin about to permanently delete data needs to
     * see the actual rows and choose which to keep, not just trust a
     * number. `rows` holds every eligible row up to
     * PREVIEW_MAX_ROWS_PER_TABLE (a safety ceiling, not a normal limit —
     * see that constant); `count` is the real total either way, so a
     * truncated list is still honestly labelled.
     *
     * @return array{retention_days:int, total:int, tables:array<int, array{table:string, count:int, label_column:?string, rows:array<int, array{id:int, label:?string, deleted_date:string}>}>}
     */
    public function preview(int $retentionDays): array
    {
        $this->assertRetention($retentionDays);

        $columnsByTable = $this->columnsByTable();
        $tables = [];
        $total = 0;

        foreach ($this->eligibleTables() as $table) {
            $quoted = '`' . $table . '`';
            $count = (int) $this->db->scalar(
                "SELECT COUNT(*) FROM {$quoted}
                  WHERE `is_deleted` = 1 AND `deleted_date` IS NOT NULL
                    AND `deleted_date` < DATE_SUB(NOW(), INTERVAL :days DAY)",
                ['days' => $retentionDays]
            );

            if ($count === 0) {
                continue;
            }

            $labelColumn = $this->labelColumnFor($columnsByTable[$table] ?? []);
            $labelSelect = $labelColumn !== null ? "`{$labelColumn}` AS `label`" : 'NULL AS `label`';

            $rows = $this->db->select(
                "SELECT `id`, {$labelSelect}, `deleted_date` FROM {$quoted}
                  WHERE `is_deleted` = 1 AND `deleted_date` IS NOT NULL
                    AND `deleted_date` < DATE_SUB(NOW(), INTERVAL :days DAY)
                  ORDER BY `deleted_date` ASC
                  LIMIT " . self::PREVIEW_MAX_ROWS_PER_TABLE,
                ['days' => $retentionDays]
            );

            $tables[] = [
                'table' => $table,
                'count' => $count,
                'label_column' => $labelColumn,
                'rows' => array_map(static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'label' => $row['label'],
                    'deleted_date' => $row['deleted_date'],
                ], $rows),
            ];
            $total += $count;
        }

        return ['retention_days' => $retentionDays, 'total' => $total, 'tables' => $tables];
    }

    /** Rows fetched (and so eligible for deletion) per table, per run — only reached when no explicit selection is given. */
    private const MAX_ROWS_PER_TABLE = 5000;

    /**
     * Actually deletes — one row at a time within each table, not a single
     * bulk DELETE per table.
     *
     * WHY ROW BY ROW: InnoDB runs one DELETE statement as a single unit — if
     * even one of the matched rows is still referenced by a real, permanent
     * record elsewhere (a soft-deleted product a genuine historical order
     * line item still points to, say), the ENTIRE statement is rejected and
     * rolled back, not just that one row. A table with 25 eligible rows
     * where only 3 are actually still referenced would previously fail to
     * clean up any of the 25 — the 22 genuinely safe ones included — and
     * report one opaque error for the whole table. Deleting by primary key
     * individually means the 3 blocked rows are skipped and reported by
     * name, while the other 22 are still removed.
     *
     * A table that fails entirely (e.g. its own id column is unexpectedly
     * named something other than `id`) is still reported, not fatal to the
     * rest — one stubborn table should not stop the others.
     *
     * WHICH ROWS: `$selections`, when given, is exactly which rows to
     * delete — `[table => [id, id, ...]]`, built by the admin unchecking
     * whatever they want to keep in the preview screen. Every id is still
     * re-verified against the SAME is_deleted/deleted_date/retention
     * condition preview() used before it is ever deleted — a client is
     * never trusted to have only sent ids that were genuinely eligible, so
     * a row that (for whatever reason) no longer qualifies is silently
     * skipped rather than deleted anyway. `$selections === null` keeps the
     * original "everything past retention, no picking" behaviour, for a
     * quick blanket clean-up when nobody wants to review row by row.
     *
     * @param array<string, array<int, int>>|null $selections
     *
     * @return array{retention_days:int, total_deleted:int, tables:array<int, array{table:string, deleted:int, skipped:int, error:?string}>}
     */
    public function run(int $retentionDays, Request $request, ?array $selections = null): array
    {
        $this->assertRetention($retentionDays);

        $eligible = $this->eligibleTables();
        $tablesToProcess = $selections !== null
            ? array_values(array_intersect(array_keys($selections), $eligible))
            : $eligible;

        $results = [];
        $totalDeleted = 0;

        foreach ($tablesToProcess as $table) {
            $quoted = '`' . $table . '`';

            try {
                if ($selections !== null) {
                    $requestedIds = array_values(array_unique(array_map(
                        'intval',
                        array_filter($selections[$table] ?? [], 'is_numeric')
                    )));

                    if ($requestedIds === []) {
                        continue;
                    }

                    // Re-checked against the real condition, not trusted from
                    // the client — a stale or tampered id list only ever
                    // narrows what gets deleted, never widens it.
                    $placeholders = implode(',', array_fill(0, count($requestedIds), '?'));
                    $ids = $this->db->select(
                        "SELECT `id` FROM {$quoted}
                          WHERE `id` IN ({$placeholders})
                            AND `is_deleted` = 1 AND `deleted_date` IS NOT NULL
                            AND `deleted_date` < DATE_SUB(NOW(), INTERVAL ? DAY)",
                        [...$requestedIds, $retentionDays]
                    );
                } else {
                    $ids = $this->db->select(
                        "SELECT `id` FROM {$quoted}
                          WHERE `is_deleted` = 1 AND `deleted_date` IS NOT NULL
                            AND `deleted_date` < DATE_SUB(NOW(), INTERVAL :days DAY)
                          LIMIT " . self::MAX_ROWS_PER_TABLE,
                        ['days' => $retentionDays]
                    );
                }
            } catch (\PDOException $exception) {
                // Genuinely can't even read candidates for this table (no
                // `id` column, most likely) — skip it, not fatal to the rest.
                $results[] = ['table' => $table, 'deleted' => 0, 'skipped' => 0, 'error' => $exception->getMessage()];

                continue;
            }

            if ($ids === []) {
                continue;
            }

            $deleted = 0;
            $skipped = 0;
            $lastError = null;

            foreach ($ids as $row) {
                try {
                    $this->db->execute("DELETE FROM {$quoted} WHERE `id` = :id", ['id' => $row['id']]);
                    ++$deleted;
                } catch (\PDOException $exception) {
                    // Still referenced elsewhere — left alone rather than
                    // risk breaking whatever real record still points to it.
                    ++$skipped;
                    $lastError = $exception->getMessage();
                }
            }

            $totalDeleted += $deleted;
            $results[] = [
                'table' => $table,
                'deleted' => $deleted,
                'skipped' => $skipped,
                'error' => $skipped > 0 ? sprintf('%d row(s) still referenced elsewhere: %s', $skipped, $lastError) : null,
            ];
        }

        $this->audit->log(
            entityName: 'data_cleanup',
            entityId: null,
            action: 'hard_delete',
            newValues: [
                'retention_days' => $retentionDays,
                'total_deleted' => $totalDeleted,
                'tables' => $results,
                'mode' => $selections !== null ? 'selected_rows' : 'all_eligible',
            ],
            request: $request
        );

        return ['retention_days' => $retentionDays, 'total_deleted' => $totalDeleted, 'tables' => $results];
    }
}
