<?php
/**
 * Db.php — Conexiones PDO de SOLO LECTURA a las dos bases (cgr / conta).
 *
 * - Fuerza sesión de solo lectura y bloquea cualquier sentencia que no sea
 *   SELECT / SHOW / WITH, como salvaguarda extra.
 * - Independiente de db_pdo/ del proyecto principal.
 */

namespace Auditoria;

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

    /** @return \PDO conexión de solo lectura a 'cgr' o 'conta' */
    public static function conn($which)
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
            // Emulación activada: permite reutilizar el mismo placeholder
            // con nombre (:ini/:fin) varias veces en una consulta.
            \PDO::ATTR_EMULATE_PREPARES   => true,
        ));
        // Salvaguarda: sesión de solo lectura (MariaDB/MySQL 5.6+)
        try { $pdo->exec("SET SESSION TRANSACTION READ ONLY"); } catch (\Exception $e) { /* ignore */ }

        self::$pool[$which] = $pdo;
        return $pdo;
    }

    /**
     * Ejecuta un SELECT y devuelve todas las filas.
     * Rechaza cualquier sentencia que no sea de lectura.
     */
    public static function q($which, $sql, $params = array())
    {
        $head = strtolower(ltrim($sql));
        if (strpos($head, 'select') !== 0 && strpos($head, 'with') !== 0 && strpos($head, 'show') !== 0) {
            throw new \Exception('Solo se permiten consultas de lectura (SELECT/WITH/SHOW).');
        }
        $st = self::conn($which)->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }
}
