<?php

namespace App\Modules\Shared\Database;

use Illuminate\Support\Facades\DB;

class RawConnection
{
    private static $pool = [];
    private static $transactionDepth = [];

    public static function pdo(string $connection = 'cgr'): \PDO
    {
        if (isset(self::$pool[$connection])) {
            return self::$pool[$connection];
        }

        $pdo = DB::connection($connection)->getPdo();
        self::$pool[$connection] = $pdo;

        return $pdo;
    }

    public static function select(string $sql, array $params = [], string $connection = 'cgr'): array
    {
        $st = self::pdo($connection)->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(\PDO::FETCH_ASSOC);
    }

    public static function selectOne(string $sql, array $params = [], string $connection = 'cgr'): ?array
    {
        $rows = self::select($sql, $params, $connection);
        return $rows[0] ?? null;
    }

    public static function scalar(string $sql, array $params = [], string $connection = 'cgr')
    {
        $row = self::selectOne($sql, $params, $connection);
        if ($row === null) return null;
        $vals = array_values($row);
        return $vals[0] ?? null;
    }

    public static function execute(string $sql, array $params = [], string $connection = 'cgr'): int
    {
        $st = self::pdo($connection)->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function lastInsertId(string $connection = 'cgr'): int
    {
        return (int) self::pdo($connection)->lastInsertId();
    }

    // --- Transaction support ---

    public static function beginTransaction(string $connection = 'cgr'): void
    {
        $depth = self::$transactionDepth[$connection] ?? 0;

        if ($depth === 0) {
            self::pdo($connection)->beginTransaction();
        } else {
            self::pdo($connection)->exec("SAVEPOINT sp_{$depth}");
        }

        self::$transactionDepth[$connection] = $depth + 1;
    }

    public static function commit(string $connection = 'cgr'): void
    {
        $depth = self::$transactionDepth[$connection] ?? 0;
        if ($depth <= 0) {
            throw new \RuntimeException("No active transaction to commit on [{$connection}]");
        }

        $depth--;
        self::$transactionDepth[$connection] = $depth;

        if ($depth === 0) {
            self::pdo($connection)->commit();
        } else {
            self::pdo($connection)->exec("RELEASE SAVEPOINT sp_{$depth}");
        }
    }

    public static function rollback(string $connection = 'cgr'): void
    {
        $depth = self::$transactionDepth[$connection] ?? 0;
        if ($depth <= 0) {
            throw new \RuntimeException("No active transaction to rollback on [{$connection}]");
        }

        $depth--;
        self::$transactionDepth[$connection] = $depth;

        if ($depth === 0) {
            self::pdo($connection)->rollBack();
        } else {
            self::pdo($connection)->exec("ROLLBACK TO SAVEPOINT sp_{$depth}");
        }
    }

    /**
     * Execute a callback within a transaction. Rolls back on exception.
     * Returns whatever the callback returns.
     */
    public static function transaction(callable $callback, string $connection = 'cgr')
    {
        self::beginTransaction($connection);

        try {
            $result = $callback();
            self::commit($connection);
            return $result;
        } catch (\Throwable $e) {
            self::rollback($connection);
            throw $e;
        }
    }

    public static function inTransaction(string $connection = 'cgr'): bool
    {
        return (self::$transactionDepth[$connection] ?? 0) > 0;
    }
}
