<?php

namespace CgrApi\Discrepancy;

class DiscrepancyDb
{
    private static $pdo = null;

    public static function conn()
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }
        $cfg = \Contabilidad\Db::config();
        $c   = $cfg['cgr'];
        $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4";
        self::$pdo = new \PDO($dsn, $c['user'], $c['pass'], array(
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES   => true,
        ));
        return self::$pdo;
    }

    public static function query($sql, $params = array())
    {
        $st = self::conn()->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    public static function exec($sql, $params = array())
    {
        $st = self::conn()->prepare($sql);
        $st->execute($params);
        return $st->rowCount();
    }

    public static function lastId()
    {
        return (int) self::conn()->lastInsertId();
    }

    public static function val($sql, $params = array())
    {
        $rows = self::query($sql, $params);
        if (empty($rows)) return null;
        $vals = array_values($rows[0]);
        return isset($vals[0]) ? $vals[0] : null;
    }
}
