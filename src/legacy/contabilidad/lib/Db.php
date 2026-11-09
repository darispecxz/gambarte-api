<?php
/**
 * Db.php — Conexión PDO de solo lectura a la base de datos CGR.
 *
 * Fuerza sesión de solo lectura y bloquea cualquier sentencia que no sea SELECT/SHOW/WITH.
 */

namespace Contabilidad;

class Db
{
    private static $config = null;
    private static $pool   = array();

    public static function config()
    {
        if (self::$config === null) {
            self::$config = require __DIR__ . '/../config.php';
            if (!empty(self::$config['timezone'])) {
                date_default_timezone_set(self::$config['timezone']);
            }
        }
        return self::$config;
    }

    /** @return \PDO conexión de solo lectura a 'cgr' */
    public static function conn($which = 'cgr')
    {
        if (isset(self::$pool[$which])) {
            return self::$pool[$which];
        }
        $cfg = self::config();
        if (!isset($cfg[$which])) {
            throw new \Exception("Base de datos desconocida: $which");
        }
        $c = $cfg[$which];
        $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4";
        $pdo = new \PDO($dsn, $c['user'], $c['pass'], array(
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT            => 30,
            \PDO::ATTR_EMULATE_PREPARES   => true,
        ));
        try { $pdo->exec("SET SESSION TRANSACTION READ ONLY"); } catch (\Exception $e) { /* ignore */ }

        self::$pool[$which] = $pdo;
        return $pdo;
    }

    /** Ejecuta un SELECT y devuelve todas las filas. */
    public static function q($sql, $params = array(), $which = 'cgr')
    {
        $head = strtolower(ltrim($sql));
        if (strpos($head, 'select') !== 0 && strpos($head, 'with') !== 0 && strpos($head, 'show') !== 0) {
            throw new \Exception('Solo se permiten consultas de lectura (SELECT/WITH/SHOW).');
        }
        $st = self::conn($which)->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    /** Ejecuta un SELECT y devuelve un solo valor escalar (o null). */
    public static function val($sql, $params = array(), $which = 'cgr')
    {
        $rows = self::q($sql, $params, $which);
        if (empty($rows)) return null;
        $vals = array_values($rows[0]);
        return isset($vals[0]) ? $vals[0] : null;
    }
}
