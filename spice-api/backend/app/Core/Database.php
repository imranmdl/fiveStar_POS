<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Thin PDO wrapper. Every query goes through a prepared statement; string
 * interpolation of user input into SQL is impossible through this API.
 */
final class Database
{
    private ?\PDO $pdo = null;
    private int $transactionDepth = 0;

    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
    }

    public function pdo(): \PDO
    {
        if ($this->pdo instanceof \PDO) {
            return $this->pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $this->config['host'],
            (int) $this->config['port'],
            $this->config['database'],
            $this->config['charset'],
        );

        $this->pdo = new \PDO($dsn, $this->config['username'], $this->config['password'], [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
            \PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);

        $this->pdo->exec("SET time_zone = '" . $this->config['time_zone'] . "'");
        $this->pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");

        return $this->pdo;
    }

    /**
     * @param array<string, mixed>|array<int, mixed> $bindings
     */
    public function statement(string $sql, array $bindings = []): \PDOStatement
    {
        $statement = $this->pdo()->prepare($sql);

        foreach ($bindings as $key => $value) {
            $parameter = is_int($key) ? $key + 1 : ':' . ltrim((string) $key, ':');

            $type = match (true) {
                is_int($value) => \PDO::PARAM_INT,
                is_bool($value) => \PDO::PARAM_INT,
                $value === null => \PDO::PARAM_NULL,
                default => \PDO::PARAM_STR,
            };

            $statement->bindValue($parameter, is_bool($value) ? (int) $value : $value, $type);
        }

        $statement->execute();

        return $statement;
    }

    /** @return array<int, array<string, mixed>> */
    public function select(string $sql, array $bindings = []): array
    {
        return $this->statement($sql, $bindings)->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function selectOne(string $sql, array $bindings = []): ?array
    {
        $row = $this->statement($sql, $bindings)->fetch();

        return $row === false ? null : $row;
    }

    public function scalar(string $sql, array $bindings = []): mixed
    {
        $value = $this->statement($sql, $bindings)->fetchColumn();

        return $value === false ? null : $value;
    }

    public function execute(string $sql, array $bindings = []): int
    {
        return $this->statement($sql, $bindings)->rowCount();
    }

    public function insert(string $sql, array $bindings = []): int
    {
        $this->statement($sql, $bindings);

        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * Nested calls use savepoints, so a service can start a transaction
     * without knowing whether a caller already opened one.
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();

        try {
            $result = $callback($this);
            $this->commit();

            return $result;
        } catch (\Throwable $exception) {
            $this->rollBack();

            throw $exception;
        }
    }

    public function beginTransaction(): void
    {
        if ($this->transactionDepth === 0) {
            $this->pdo()->beginTransaction();
        } else {
            $this->pdo()->exec('SAVEPOINT trans' . $this->transactionDepth);
        }

        ++$this->transactionDepth;
    }

    public function commit(): void
    {
        --$this->transactionDepth;

        if ($this->transactionDepth === 0) {
            $this->pdo()->commit();
        } else {
            $this->pdo()->exec('RELEASE SAVEPOINT trans' . $this->transactionDepth);
        }
    }

    public function rollBack(): void
    {
        --$this->transactionDepth;

        if ($this->transactionDepth <= 0) {
            $this->transactionDepth = 0;

            if ($this->pdo()->inTransaction()) {
                $this->pdo()->rollBack();
            }

            return;
        }

        $this->pdo()->exec('ROLLBACK TO SAVEPOINT trans' . $this->transactionDepth);
    }
}
