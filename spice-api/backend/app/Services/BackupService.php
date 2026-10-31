<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Config;
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
 *
 * Two kinds of backup:
 *  - database (backup_….sql): all data, as SQL;
 *  - full (backup_….zip): the same database.sql plus every uploaded file
 *    (product photos, banners, logo, payment QR) under uploads/, and a
 *    manifest.json. Restoring a full backup on another copy of the shop —
 *    a test server, a laptop — gives an exact working copy for testing.
 */
final class BackupService
{
    private const FILENAME_PATTERN = '/^backup_\d{8}_\d{6}(_\d{1,3})?\.(sql|zip)$/';
    private const ROW_BATCH = 200;
    private const MAX_UPLOAD_BYTES = 104_857_600; // 100 MB — generous for a shop this size's dump.
    private const MAX_FULL_UPLOAD_BYTES = 2_147_483_648; // 2 GB — a full backup carries every photo.

    /** Files a full backup may put back into uploads/ — never anything executable. */
    private const RESTORABLE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'avif', 'pdf', 'csv', 'xlsx', 'txt'];

    public function __construct(
        private readonly Database $db,
        private readonly AuditService $audit,
        private readonly Config $config,
    ) {
    }

    /** Where uploaded files live (public/uploads, the /data volume on Railway). */
    private function uploadsRoot(): string
    {
        $root = rtrim((string) $this->config->get('uploads.root_path', ''), '/');
        $real = $root !== '' ? realpath($root) : false;

        if ($real === false || !is_dir($real)) {
            throw new HttpException('The uploads folder could not be found on the server.', 500);
        }

        return $real;
    }

    private function directory(): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/backups';

        if (!is_dir($dir) && !mkdir($dir, 0o750, true) && !is_dir($dir)) {
            throw new HttpException('Backup directory could not be created.', 500);
        }

        return $dir;
    }

    /** A file name no other backup has — two in the same second must not overwrite each other. */
    private function freshName(string $extension): string
    {
        $stamp = 'backup_' . date('Ymd_His');
        $filename = $stamp . '.' . $extension;

        for ($n = 2; is_file($this->directory() . '/' . $filename); $n++) {
            $filename = $stamp . '_' . $n . '.' . $extension;
        }

        return $filename;
    }

    /**
     * Full backup: database plus every uploaded file, in one .zip.
     *
     * @return array<string, mixed>
     */
    public function createFull(Request $request): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new HttpException('This server cannot create .zip files (PHP zip extension missing).', 500);
        }

        @set_time_limit(0);
        ignore_user_abort(true);

        $filename = $this->freshName('zip');
        $path = $this->directory() . '/' . $filename;
        $sqlPath = tempnam(sys_get_temp_dir(), 'bk_sql_');
        $handle = fopen((string) $sqlPath, 'wb');

        if ($handle === false) {
            throw new HttpException('Could not create the backup file.', 500);
        }

        try {
            $this->writeDump($handle);
        } finally {
            fclose($handle);
        }

        $zip = new \ZipArchive();

        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            @unlink((string) $sqlPath);
            throw new HttpException('Could not create the backup file.', 500);
        }

        $zip->addFile((string) $sqlPath, 'database.sql');

        $root = $this->uploadsRoot();
        $files = 0;
        $bytes = 0;
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS)
        );

        foreach ($iterator as $file) {
            /** @var \SplFileInfo $file */
            if (!$file->isFile() || str_starts_with($file->getFilename(), '.')) {
                continue;
            }

            $relative = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');
            $entry = 'uploads/' . $relative;
            $zip->addFile($file->getPathname(), $entry);
            // Photos are already compressed; storing them is much faster.
            $zip->setCompressionName($entry, \ZipArchive::CM_STORE);
            ++$files;
            $bytes += (int) $file->getSize();
        }

        $zip->addFromString('manifest.json', (string) json_encode([
            'kind' => '5star-full-backup',
            'version' => 1,
            'created' => date('c'),
            'database' => 'database.sql',
            'upload_files' => $files,
            'upload_bytes' => $bytes,
            'app_url' => (string) $this->config->get('app.url', ''),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $ok = $zip->close();
        @unlink((string) $sqlPath);

        if ($ok !== true) {
            @unlink($path);
            throw new HttpException('The full backup could not be written (is the disk full?).', 500);
        }

        $this->audit->log(
            entityName: 'database_backups',
            entityId: null,
            action: 'create',
            newValues: ['filename' => $filename, 'bytes' => (int) filesize($path), 'label' => 'full', 'upload_files' => $files],
            request: $request
        );

        return $this->describe($filename);
    }

    /** @return array<string, mixed> */
    public function create(Request $request, string $label = 'manual'): array
    {
        // Two backups in the same second (a manual one, then the safety
        // backup a reset or restore takes) must never share a file name —
        // the second would silently overwrite the first.
        $filename = $this->freshName('sql');
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
        $files = array_merge(glob($dir . '/backup_*.sql') ?: [], glob($dir . '/backup_*.zip') ?: []);

        $items = array_map(fn (string $path): array => $this->describe(basename($path)), $files);

        usort($items, static fn (array $a, array $b): int => $b['created_date'] <=> $a['created_date']);

        return $items;
    }

    /** @return array<string, mixed> */
    private function describe(string $filename): array
    {
        $path = $this->assertValidFilename($filename);
        $full = str_ends_with($filename, '.zip');
        $uploadFiles = null;

        if ($full && class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::RDONLY) === true) {
                $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
                $uploadFiles = is_array($manifest) ? (int) ($manifest['upload_files'] ?? 0) : null;
                $zip->close();
            }
        }

        return [
            'filename' => $filename,
            // "full" = database + images (.zip); "database" = data only (.sql).
            'type' => $full ? 'full' : 'database',
            'upload_files' => $uploadFiles,
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

    /** Download links stay valid this long — enough to start a big download. */
    private const LINK_TTL_SECONDS = 1800;

    /**
     * A short-lived signed link for downloading a backup straight to disk
     * with the browser's own download manager (progress, resume), instead
     * of holding hundreds of MB in page memory first.
     *
     * @return array{url:string, expires_at:string}
     */
    public function downloadLink(string $filename, Request $request): array
    {
        $this->assertValidFilename($filename);
        $expires = time() + self::LINK_TTL_SECONDS;
        $user = (int) $request->authUserId();
        $signature = $this->sign($filename, $expires, $user);

        $this->audit->log(
            entityName: 'database_backups',
            entityId: null,
            action: 'download_link',
            newValues: ['filename' => $filename, 'expires' => date('c', $expires)],
            request: $request
        );

        return [
            'url' => '/api/v1/backups/download?' . http_build_query([
                'file' => $filename,
                'exp' => $expires,
                'uid' => $user,
                'sig' => $signature,
            ]),
            'expires_at' => date('c', $expires),
        ];
    }

    /** Checks a signed link and returns the backup's path. */
    public function pathForSignedLink(string $filename, int $expires, int $userId, string $signature): string
    {
        if ($expires < time()) {
            throw new HttpException('This download link has expired. Press Download again in Admin → Backups.', 403);
        }

        if (!hash_equals($this->sign($filename, $expires, $userId), $signature)) {
            throw new HttpException('This download link is not valid.', 403);
        }

        $isAdmin = (int) $this->db->scalar(
            "SELECT COUNT(*) FROM `users` u INNER JOIN `roles` r ON r.`id` = u.`role_id`
              WHERE u.`id` = :id AND u.`is_deleted` = 0 AND u.`is_active` = 1
                AND r.`code` IN ('administrator', 'super_admin')",
            ['id' => $userId]
        ) > 0;

        if (!$isAdmin) {
            throw new HttpException('This download link is not valid.', 403);
        }

        return $this->assertValidFilename($filename);
    }

    private function sign(string $filename, int $expires, int $userId): string
    {
        $secret = (string) $this->config->get('auth.jwt.secret', '');

        if ($secret === '') {
            throw new HttpException('Downloads are not configured on this server (JWT_SECRET missing).', 500);
        }

        return hash_hmac('sha256', 'backup-download|' . $filename . '|' . $expires . '|' . $userId, $secret);
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
        $isZip = str_ends_with(strtolower((string) ($file['name'] ?? '')), '.zip');
        $limit = $isZip ? self::MAX_FULL_UPLOAD_BYTES : self::MAX_UPLOAD_BYTES;

        if ($size <= 0 || $size > $limit) {
            throw new HttpException(
                sprintf('The file must be between 1 byte and %d MB.', (int) ($limit / 1_048_576)),
                422,
                ['file' => ['File size is outside the allowed range.']]
            );
        }

        $temporaryPath = (string) ($file['tmp_name'] ?? '');

        if (!is_uploaded_file($temporaryPath)) {
            throw new HttpException('Upload could not be verified.', 422);
        }

        if ($isZip) {
            return $this->restoreFull($temporaryPath, (string) ($file['name'] ?? ''), $request);
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

        if (str_ends_with($filename, '.zip')) {
            return $this->restoreFull($path, $filename, $request);
        }

        $sql = file_get_contents($path);

        if ($sql === false || trim($sql) === '') {
            throw new HttpException('That backup file is empty or unreadable.', 422);
        }

        return $this->restoreSql($sql, $filename, strlen($sql), $request);
    }

    /**
     * Full restore: the database from database.sql, then every file under
     * uploads/ put back in place (added or overwritten; files the backup
     * does not have are left alone). Only image/document file types are
     * written, only inside the uploads folder.
     *
     * @return array<string, mixed>
     */
    private function restoreFull(string $zipPath, string $sourceName, Request $request): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new HttpException('This server cannot read .zip files (PHP zip extension missing).', 500);
        }

        @set_time_limit(0);
        ignore_user_abort(true);

        $zip = new \ZipArchive();

        if ($zip->open($zipPath, \ZipArchive::RDONLY) !== true) {
            throw new HttpException('That file is not a readable .zip backup.', 422);
        }

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $sql = $zip->getFromName('database.sql');

        if (!is_array($manifest) || ($manifest['kind'] ?? null) !== '5star-full-backup' || $sql === false || trim($sql) === '') {
            $zip->close();
            throw new HttpException('That .zip is not a full backup made by this shop (no manifest.json / database.sql).', 422);
        }

        $result = $this->restoreSql($sql, $sourceName, strlen($sql), $request);
        unset($sql);

        $root = $this->uploadsRoot();
        $restored = 0;
        $skipped = 0;

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);

            if (!str_starts_with($name, 'uploads/') || str_ends_with($name, '/')) {
                continue;
            }

            $relative = substr($name, strlen('uploads/'));
            $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));

            if ($relative === '' || str_contains($relative, '..') || str_contains($relative, '\\')
                || str_starts_with($relative, '/') || preg_match('~^[A-Za-z0-9._/-]+$~', $relative) !== 1
                || !in_array($extension, self::RESTORABLE_EXTENSIONS, true)) {
                ++$skipped;
                continue;
            }

            $target = $root . '/' . $relative;
            $dir = dirname($target);

            if (!is_dir($dir) && !mkdir($dir, 0o775, true) && !is_dir($dir)) {
                ++$skipped;
                continue;
            }

            $in = $zip->getStream($name);
            $out = $in === false ? false : fopen($target, 'wb');

            if ($in === false || $out === false) {
                ++$skipped;
                continue;
            }

            stream_copy_to_stream($in, $out);
            fclose($in);
            fclose($out);
            ++$restored;
        }

        $zip->close();

        $this->audit->log(
            entityName: 'database_backups',
            entityId: null,
            action: 'restore_files',
            newValues: ['source' => $sourceName, 'files_restored' => $restored, 'files_skipped' => $skipped],
            request: $request,
        );

        return $result + ['files_restored' => $restored, 'files_skipped' => $skipped];
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
