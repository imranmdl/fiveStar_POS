<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Exceptions\HttpException;
use App\Core\Request;

/**
 * Whole-database backup and restore, as plain logical SQL — the same shape
 * as the migration files this project already ships, so a backup can be
 * read, diffed or hand-edited like any other .sql file here, not just
 * replayed blindly.
 *
 * Written in pure PHP/PDO rather than shelling out to `mysqldump`: this app
 * is deployed to shared hosting (see bin/setup.php) where a shell_exec of an
 * external binary may simply not be available, and a dump this tool cannot
 * also restore is not a backup a merchant can trust.
 */
final class BackupService
{
    private const FILENAME_PATTERN = '/^backup_\d{8}_\d{6}(_\d{1,3})?\.sql$/';
    private const ROW_BATCH = 200;
    private const MAX_UPLOAD_BYTES = 104_857_600; // 100 MB — generous for a shop this size's dump.

    public function __construct(
        private readonly Database $db,
        private readonly AuditService $audit,
    ) {
    }

    private function directory(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/backups';

        if (!is_dir($dir) && !mkdir($dir, 0o750, true) && !is_dir($dir)) {
            throw new HttpException('Backup directory could not be created.', 500);
        }

        return $dir;
    }

    /** @return array<string, mixed> */
    public function create(Request $request, string $label = 'manual'): array
    {
        // Two backups in the same second (a manual one, then the safety
        // backup a reset or restore takes) must never share a file name —
        // the second would silently overwrite the first.
        $stamp = 'backup_' . date('Ymd_His');
        $filename = $stamp . '.sql';
        for ($n = 2; is_file($this->directory() . '/' . $filename); $n++) {
            $filename = $stamp . '_' . $n . '.sql';
        }
        $path = $this->directory() . '/' . $filename;

        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new HttpException('Could not create the backup file.', 500);
        }

        try {
            $this->writeDump($handle);
        } finally {
            fclose($handle);
        }

        $size = (int) filesize($path);

        $this->audit->log(
            entityName: 'database_backups',
            entityId: null,
            action: 'create',
            newValues: ['filename' => $filename, 'bytes' => $size, 'label' => $label],
            request: $request
        );

        return $this->describe($filename);
    }

    private function writeDump($handle): void
    {
        $pdo = $this->db->pdo();

        fwrite($handle, "-- 5 Star database backup\n");
        fwrite($handle, '-- Generated ' . date('c') . "\n");
        fwrite($handle, "-- Restoring this file replaces data in tables/views it recreates.\n\n");
        fwrite($handle, "SET NAMES utf8mb4;\n");
        fwrite($handle, "SET FOREIGN_KEY_CHECKS = 0;\n\n");

        // Columns are aliased because MySQL 8 returns information_schema
        // columns in UPPER CASE (TABLE_NAME) while MariaDB keeps the case
        // written in the query.
        // information_schema, not SHOW TABLES: this schema has several VIEWs
        // (vw_variant_pricing etc.) alongside base tables, and SHOW CREATE
        // TABLE on a VIEW returns a completely different result shape
        // (`Create View`, not `Create Table`) — they need separate handling,
        // and views have no rows of their own to dump.
        $entities = $this->db->select(
            "SELECT table_name AS table_name, table_type AS table_type FROM information_schema.tables
              WHERE table_schema = DATABASE()
              ORDER BY (table_type = 'VIEW') ASC, table_name ASC"
        );

        foreach ($entities as $entity) {
            if ($entity['table_type'] === 'VIEW') {
                $this->writeView($handle, $pdo, (string) $entity['table_name']);
            } else {
                $this->writeTable($handle, $pdo, (string) $entity['table_name']);
            }
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS = 1;\n");
    }

    /** A view has structure but, being a derived query, no rows of its own to dump. */
    private function writeView($handle, \PDO $pdo, string $view): void
    {
        $quotedView = '`' . str_replace('`', '``', $view) . '`';

        fwrite($handle, "-- ----------------------------------------------------------------------------\n");
        fwrite($handle, "-- {$view} (view)\n");
        fwrite($handle, "-- ----------------------------------------------------------------------------\n");
        fwrite($handle, "DROP VIEW IF EXISTS {$quotedView};\n");

        $createRow = $pdo->query("SHOW CREATE VIEW {$quotedView}")->fetch();
        fwrite($handle, (string) $createRow['Create View'] . ";\n\n");
    }

    private function writeTable($handle, \PDO $pdo, string $table): void
    {
        $quotedTable = '`' . str_replace('`', '``', $table) . '`';

        fwrite($handle, "-- ----------------------------------------------------------------------------\n");
        fwrite($handle, "-- {$table}\n");
        fwrite($handle, "-- ----------------------------------------------------------------------------\n");
        fwrite($handle, "DROP TABLE IF EXISTS {$quotedTable};\n");

        $createRow = $pdo->query("SHOW CREATE TABLE {$quotedTable}")->fetch();
        fwrite($handle, (string) $createRow['Create Table'] . ";\n\n");

        // Generated columns (computed from others, e.g. carts.active_owner_user)
        // are left out: MySQL refuses any value for them on INSERT, so a dump
        // that includes them can never be restored. MySQL marks them
        // "VIRTUAL GENERATED"/"STORED GENERATED", MariaDB also "PERSISTENT
        // GENERATED"; "DEFAULT_GENERATED" is just a column default and stays.
        $stored = array_values(array_filter(
            $pdo->query("SHOW COLUMNS FROM {$quotedTable}")->fetchAll(),
            static fn (array $col): bool => preg_match('/\b(VIRTUAL|STORED|PERSISTENT) GENERATED\b/i', (string) ($col['Extra'] ?? '')) !== 1
        ));
        $columns = array_map(static fn (array $col): string => (string) $col['Field'], $stored);
        $quotedColumns = implode(', ', array_map(static fn (string $c): string => '`' . $c . '`', $columns));

        $countStatement = $pdo->query("SELECT COUNT(*) FROM {$quotedTable}");
        $total = (int) $countStatement->fetchColumn();

        if ($total === 0) {
            fwrite($handle, "\n");

            return;
        }

        for ($offset = 0; $offset < $total; $offset += self::ROW_BATCH) {
            $rows = $pdo->query(
                "SELECT {$quotedColumns} FROM {$quotedTable} LIMIT " . self::ROW_BATCH . " OFFSET {$offset}"
            )->fetchAll();

            if ($rows === []) {
                break;
            }

            $valueGroups = [];

            foreach ($rows as $row) {
                $values = array_map(
                    fn (mixed $value): string => $value === null ? 'NULL' : $pdo->quote((string) $value),
                    array_values($row)
                );
                $valueGroups[] = '(' . implode(', ', $values) . ')';
            }

            fwrite(
                $handle,
                "INSERT INTO {$quotedTable} ({$quotedColumns}) VALUES\n" . implode(",\n", $valueGroups) . ";\n"
            );
        }

        fwrite($handle, "\n");
    }

    /** @return array<int, array<string, mixed>> */
    public function list(): array
    {
        $dir = $this->directory();
        $files = glob($dir . '/backup_*.sql') ?: [];

        $items = array_map(fn (string $path): array => $this->describe(basename($path)), $files);

        usort($items, static fn (array $a, array $b): int => $b['created_date'] <=> $a['created_date']);

        return $items;
    }

    /** @return array<string, mixed> */
    private function describe(string $filename): array
    {
        $path = $this->assertValidFilename($filename);

        return [
            'filename' => $filename,
            'bytes' => (int) filesize($path),
            'created_date' => date('Y-m-d H:i:s', (int) filemtime($path)),
        ];
    }

    /** Never trusts a filename from a request without checking it against the pattern this class itself generates. */
    private function assertValidFilename(string $filename): string
    {
        if (preg_match(self::FILENAME_PATTERN, $filename) !== 1) {
            throw new HttpException('Unknown backup file.', 404);
        }

        $path = $this->directory() . '/' . $filename;

        if (!is_file($path)) {
            throw new HttpException('That backup no longer exists.', 404);
        }

        return $path;
    }

    public function pathFor(string $filename): string
    {
        return $this->assertValidFilename($filename);
    }

    public function delete(string $filename, Request $request): void
    {
        $path = $this->assertValidFilename($filename);
        unlink($path);

        $this->audit->log(
            entityName: 'database_backups',
            entityId: null,
            action: 'delete',
            oldValues: ['filename' => $filename],
            request: $request
        );
    }

    /**
     * Restores the database from an uploaded .sql file. A fresh safety
     * backup is taken FIRST and unconditionally — a restore that turns out
     * to be the wrong file, or fails halfway, must never be the only copy of
     * what was just overwritten.
     *
     * @param array<string, mixed> $file A single entry from $_FILES
     *
     * @return array{safety_backup:array<string, mixed>}
     */
    public function restore(array $file, Request $request): array
    {
        $this->assertUploadSucceeded($file);

        $size = (int) ($file['size'] ?? 0);

        if ($size <= 0 || $size > self::MAX_UPLOAD_BYTES) {
            throw new HttpException(
                sprintf('The file must be between 1 byte and %d MB.', (int) (self::MAX_UPLOAD_BYTES / 1_048_576)),
                422,
                ['file' => ['File size is outside the allowed range.']]
            );
        }

        $temporaryPath = (string) ($file['tmp_name'] ?? '');

        if (!is_uploaded_file($temporaryPath)) {
            throw new HttpException('Upload could not be verified.', 422);
        }

        $sql = file_get_contents($temporaryPath);

        if ($sql === false || trim($sql) === '') {
            throw new HttpException('The uploaded file is empty or unreadable.', 422);
        }

        return $this->restoreSql($sql, (string) ($file['name'] ?? ''), $size, $request);
    }

    /**
     * Restores one of the backups already saved on the server — the quick
     * way back after a test run or a data reset.
     *
     * @return array{safety_backup:array<string, mixed>}
     */
    public function restoreSaved(string $filename, Request $request): array
    {
        $path = $this->assertValidFilename($filename);
        $sql = file_get_contents($path);

        if ($sql === false || trim($sql) === '') {
            throw new HttpException('That backup file is empty or unreadable.', 422);
        }

        return $this->restoreSql($sql, $filename, strlen($sql), $request);
    }

    /** @return array{safety_backup:array<string, mixed>} */
    private function restoreSql(string $sql, string $sourceName, int $size, Request $request): array
    {
        $safetyBackup = $this->create($request, 'pre_restore_safety');

        try {
            $this->db->pdo()->exec($sql);
        } catch (\PDOException $exception) {
            throw new HttpException(
                'The restore failed partway through: ' . $exception->getMessage()
                    . '. A safety backup taken just before this attempt is available at '
                    . $safetyBackup['filename'] . '.',
                422
            );
        }

        $this->audit->log(
            entityName: 'database_backups',
            entityId: null,
            action: 'restore',
            newValues: ['source' => $sourceName, 'bytes' => $size],
            request: $request,
            notes: 'Safety backup taken first: ' . $safetyBackup['filename']
        );

        return ['safety_backup' => $safetyBackup];
    }

    /** @param array<string, mixed> $file */
    private function assertUploadSucceeded(array $file): void
    {
        $error = (int) ($file['error'] ?? \UPLOAD_ERR_NO_FILE);

        if ($error === \UPLOAD_ERR_NO_FILE) {
            throw new HttpException('No file was received.', 422, [
                'file' => ['Attach the file as a multipart field named "file".'],
            ]);
        }

        if ($error !== \UPLOAD_ERR_OK) {
            throw new HttpException('The upload failed before it reached the server.', 422, [
                'file' => ['Try again — the upload was interrupted.'],
            ]);
        }
    }
}
