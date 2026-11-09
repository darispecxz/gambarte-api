<?php
namespace Contabilidad;

require_once __DIR__ . '/Db.php';

class AccountingReportsService
{
    public static function getEjercicios()
    {
        return Db::q(
            "SELECT codejercicio, nombre, fechainicio, fechafin, estado
             FROM ejercicios ORDER BY codejercicio DESC",
            array(), 'conta'
        );
    }

    public static function getSubcuentas($ejercicio)
    {
        return Db::q(
            "SELECT codsubcuenta, descripcion
             FROM co_subcuentas
             WHERE codejercicio = :ej
             ORDER BY codsubcuenta",
            array(':ej' => $ejercicio), 'conta'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Balance de Sumas y Saldos
    // ═══════════════════════════════════════════════════════════════════

    public static function getBalanceSumasYSaldos($ejercicio, $fechaDesde, $fechaHasta)
    {
        $sql = <<<'SQL'
            SELECT
                s.codsubcuenta,
                s.descripcion AS subcuenta_desc,
                c.codcuenta,
                c.descripcion AS cuenta_desc,
                COALESCE(SUM(p.debe), 0) AS debe,
                COALESCE(SUM(p.haber), 0) AS haber
            FROM co_subcuentas s
            LEFT JOIN co_partidas p ON p.codsubcuenta = s.codsubcuenta
                AND p.idasiento IN (
                    SELECT a.idasiento FROM co_asientos a
                    WHERE a.codejercicio = :ej
                      AND a.fecha >= :desde
                      AND a.fecha <= :hasta
                )
            LEFT JOIN co_cuentas c ON c.codcuenta = s.codcuenta
                AND c.codejercicio = s.codejercicio
            WHERE s.codejercicio = :ej2
            GROUP BY s.codsubcuenta, s.descripcion, c.codcuenta, c.descripcion
            HAVING COALESCE(SUM(p.debe), 0) != 0 OR COALESCE(SUM(p.haber), 0) != 0
            ORDER BY s.codsubcuenta
SQL;

        $rows = Db::q($sql, array(
            ':ej'    => $ejercicio,
            ':ej2'   => $ejercicio,
            ':desde' => $fechaDesde,
            ':hasta' => $fechaHasta,
        ), 'conta');

        $totalDebe = 0;
        $totalHaber = 0;
        $cuentas = array();

        foreach ($rows as $r) {
            $codcuenta = $r['codcuenta'];
            $debe  = (float)$r['debe'];
            $haber = (float)$r['haber'];
            $saldo = $debe - $haber;

            if (!isset($cuentas[$codcuenta])) {
                $cuentas[$codcuenta] = array(
                    'codcuenta'   => $codcuenta,
                    'descripcion' => $r['cuenta_desc'],
                    'debe'        => 0,
                    'haber'       => 0,
                    'saldo'       => 0,
                    'subcuentas'  => array(),
                );
            }

            $cuentas[$codcuenta]['debe']  += $debe;
            $cuentas[$codcuenta]['haber'] += $haber;
            $cuentas[$codcuenta]['saldo'] += $saldo;
            $cuentas[$codcuenta]['subcuentas'][] = array(
                'codsubcuenta' => $r['codsubcuenta'],
                'descripcion'  => $r['subcuenta_desc'],
                'debe'         => $debe,
                'haber'        => $haber,
                'saldo'        => $saldo,
            );

            $totalDebe  += $debe;
            $totalHaber += $haber;
        }

        ksort($cuentas);

        return array(
            'ejercicio'  => $ejercicio,
            'fechaDesde' => $fechaDesde,
            'fechaHasta' => $fechaHasta,
            'cuentas'    => array_values($cuentas),
            'totalDebe'  => $totalDebe,
            'totalHaber' => $totalHaber,
            'totalSaldo' => $totalDebe - $totalHaber,
            'cuadrado'   => abs($totalDebe - $totalHaber) < 0.01,
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Libro Mayor por Subcuenta
    // ═══════════════════════════════════════════════════════════════════

    public static function getLibroMayor($codsubcuenta, $ejercicio, $fechaDesde, $fechaHasta, $page = 1, $pageSize = 500)
    {
        $info = Db::q(
            "SELECT codsubcuenta, descripcion
             FROM co_subcuentas
             WHERE codsubcuenta = :cod AND codejercicio = :ej",
            array(':cod' => $codsubcuenta, ':ej' => $ejercicio), 'conta'
        );

        $empty = array(
            'codsubcuenta' => $codsubcuenta,
            'descripcion'  => empty($info) ? '' : $info[0]['descripcion'],
            'ejercicio'    => $ejercicio,
            'fechaDesde'   => $fechaDesde,
            'fechaHasta'   => $fechaHasta,
            'saldoInicial' => 0,
            'movimientos'  => array(),
            'totalDebe'    => 0,
            'totalHaber'   => 0,
            'saldoFinal'   => 0,
            'page'         => $page,
            'pageSize'     => $pageSize,
            'totalRows'    => 0,
            'totalPages'   => 0,
        );

        if (empty($info)) {
            return $empty;
        }

        $baseParams = array(':cod' => $codsubcuenta, ':ej' => $ejercicio, ':desde' => $fechaDesde, ':hasta' => $fechaHasta);
        $baseWhere  = "p.codsubcuenta = :cod AND a.codejercicio = :ej AND a.fecha >= :desde AND a.fecha <= :hasta";

        $saldoInicial = (float)Db::val(
            "SELECT COALESCE(SUM(p.debe - p.haber), 0)
             FROM co_partidas p
             JOIN co_asientos a ON a.idasiento = p.idasiento
             WHERE p.codsubcuenta = :cod
               AND a.codejercicio = :ej
               AND a.fecha < :desde",
            array(':cod' => $codsubcuenta, ':ej' => $ejercicio, ':desde' => $fechaDesde),
            'conta'
        );

        $totalRows = (int)Db::val(
            "SELECT COUNT(*) FROM co_partidas p JOIN co_asientos a ON a.idasiento = p.idasiento WHERE $baseWhere",
            $baseParams, 'conta'
        );

        $totals = Db::q(
            "SELECT COALESCE(SUM(p.debe),0) AS td, COALESCE(SUM(p.haber),0) AS th
             FROM co_partidas p JOIN co_asientos a ON a.idasiento = p.idasiento WHERE $baseWhere",
            $baseParams, 'conta'
        );
        $totalDebe  = (float)$totals[0]['td'];
        $totalHaber = (float)$totals[0]['th'];
        $saldoFinal = $saldoInicial + $totalDebe - $totalHaber;

        $totalPages = max(1, (int)ceil($totalRows / $pageSize));
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $pageSize;

        $saldoPrevPages = $saldoInicial;
        if ($offset > 0) {
            $saldoPrevPages += (float)Db::val(
                "SELECT COALESCE(SUM(sub.debe - sub.haber), 0)
                 FROM (
                     SELECT p.debe, p.haber
                     FROM co_partidas p
                     JOIN co_asientos a ON a.idasiento = p.idasiento
                     WHERE $baseWhere
                     ORDER BY a.fecha, a.numero, a.idasiento
                     LIMIT $offset
                 ) sub",
                $baseParams, 'conta'
            );
        }

        $movimientos = Db::q(
            "SELECT a.idasiento, a.numero, a.fecha, a.tipodocumento, a.concepto AS asiento_concepto,
                    p.debe, p.haber, p.concepto AS partida_concepto, p.documento
             FROM co_partidas p
             JOIN co_asientos a ON a.idasiento = p.idasiento
             WHERE $baseWhere
             ORDER BY a.fecha, a.numero, a.idasiento
             LIMIT $pageSize OFFSET $offset",
            $baseParams, 'conta'
        );

        $saldoProgresivo = $saldoPrevPages;
        $mapped = array();

        foreach ($movimientos as $m) {
            $debe  = (float)$m['debe'];
            $haber = (float)$m['haber'];
            $saldoProgresivo += ($debe - $haber);

            $mapped[] = array(
                'idasiento'     => (int)$m['idasiento'],
                'numero'        => (int)$m['numero'],
                'fecha'         => $m['fecha'],
                'tipodocumento' => $m['tipodocumento'],
                'concepto'      => $m['partida_concepto'] ? $m['partida_concepto'] : $m['asiento_concepto'],
                'documento'     => $m['documento'],
                'debe'          => $debe,
                'haber'         => $haber,
                'saldo'         => $saldoProgresivo,
            );
        }

        return array(
            'codsubcuenta' => $codsubcuenta,
            'descripcion'  => $info[0]['descripcion'],
            'ejercicio'    => $ejercicio,
            'fechaDesde'   => $fechaDesde,
            'fechaHasta'   => $fechaHasta,
            'saldoInicial' => $saldoInicial,
            'movimientos'  => $mapped,
            'totalDebe'    => $totalDebe,
            'totalHaber'   => $totalHaber,
            'saldoFinal'   => $saldoFinal,
            'page'         => $page,
            'pageSize'     => $pageSize,
            'totalRows'    => $totalRows,
            'totalPages'   => $totalPages,
        );
    }

    public static function getAsientoDetalle($idasiento)
    {
        $asiento = Db::q(
            "SELECT idasiento, numero, fecha, concepto, tipodocumento, importe
             FROM co_asientos WHERE idasiento = :id",
            array(':id' => $idasiento), 'conta'
        );

        if (empty($asiento)) {
            return null;
        }

        $partidas = Db::q(
            "SELECT p.codsubcuenta, s.descripcion, p.concepto, p.documento,
                    p.debe, p.haber
             FROM co_partidas p
             LEFT JOIN co_subcuentas s ON s.codsubcuenta = p.codsubcuenta
                 AND s.codejercicio = (SELECT codejercicio FROM co_asientos WHERE idasiento = :id2)
             WHERE p.idasiento = :id
             ORDER BY p.codsubcuenta",
            array(':id' => $idasiento, ':id2' => $idasiento), 'conta'
        );

        $a = $asiento[0];
        return array(
            'idasiento'     => (int)$a['idasiento'],
            'numero'        => (int)$a['numero'],
            'fecha'         => $a['fecha'],
            'concepto'      => $a['concepto'],
            'tipodocumento' => $a['tipodocumento'],
            'importe'       => (float)$a['importe'],
            'partidas'      => $partidas,
        );
    }
}
