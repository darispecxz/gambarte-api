<?php

namespace Contabilidad;

require_once __DIR__ . '/Db.php';

class PosAdminService
{
    public static function getDashboard($fecha, $idAgencia = null)
    {
        $params = array(':fecha' => $fecha);
        $where = '';
        if ($idAgencia) {
            $where = 'AND c.id_agencia = :id_agencia';
            $params[':id_agencia'] = $idAgencia;
        }

        $sql = "
            SELECT
                c.id_caja,
                c.id_agencia,
                c.num_caja,
                c.id_moneda,
                c.cod_usuario,
                c.maximo,
                c.minimo,
                c.estado AS estado_caja,
                a.descripcion AS agencia,
                a.abreviatura AS agencia_abr,
                m.abreviatura AS moneda,
                m.descripcion AS moneda_desc,
                TRIM(CONCAT(IFNULL(t.nom_trab,''), ' ', IFNULL(t.ape_pat_trab,''), ' ', IFNULL(t.ape_mat_trab,''))) AS nombre_cajero,
                u.login_usu,
                (SELECT sc.saldo FROM saldo_caja sc
                 WHERE sc.id_caja = c.id_caja AND sc.conciliacion = 'A'
                 AND DATE(sc.fec_crea_saldo) = :fecha LIMIT 1) AS apertura,
                (SELECT sc.fec_crea_saldo FROM saldo_caja sc
                 WHERE sc.id_caja = c.id_caja AND sc.conciliacion = 'A'
                 AND DATE(sc.fec_crea_saldo) = :fecha LIMIT 1) AS hora_apertura,
                (SELECT sc.saldo FROM saldo_caja sc
                 WHERE sc.id_caja = c.id_caja AND sc.conciliacion = 'C'
                 AND DATE(sc.fec_crea_saldo) = :fecha LIMIT 1) AS cierre,
                (SELECT sc.fec_crea_saldo FROM saldo_caja sc
                 WHERE sc.id_caja = c.id_caja AND sc.conciliacion = 'C'
                 AND DATE(sc.fec_crea_saldo) = :fecha LIMIT 1) AS hora_cierre,
                COALESCE((SELECT SUM(sc.saldo) FROM saldo_caja sc
                 WHERE sc.id_caja = c.id_caja
                 AND DATE(sc.fec_crea_saldo) = :fecha), 0) AS saldo_actual,
                (SELECT COUNT(*) FROM movimiento_caja mc
                 WHERE mc.id_caja = c.id_caja
                 AND DATE(mc.fec_crea_movimiento_caja) = :fecha) AS num_movimientos
            FROM caja c
            JOIN agencia a ON a.id_agencia = c.id_agencia
            JOIN moneda m ON m.id_moneda = c.id_moneda
            JOIN usuario u ON u.cod_usuario = c.cod_usuario
            LEFT JOIN trabajador t ON t.cod_trab = u.cod_trab
            WHERE c.estado = 'A'
              AND c.id_tipo_unidad = 2
              AND (
                EXISTS (
                  SELECT 1 FROM saldo_caja sc2
                  WHERE sc2.id_caja = c.id_caja
                    AND sc2.conciliacion = 'C'
                    AND sc2.fec_crea_saldo >= DATE_SUB(:fecha, INTERVAL 30 DAY)
                )
                OR EXISTS (
                  SELECT 1 FROM saldo_caja sc3
                  WHERE sc3.id_caja = c.id_caja
                    AND sc3.conciliacion = 'A'
                    AND DATE(sc3.fec_crea_saldo) = :fecha
                )
              )
              $where
            ORDER BY a.descripcion, c.num_caja, c.id_moneda
        ";

        $rows = Db::q($sql, $params);
        return self::groupDashboard($rows);
    }

    private static function groupDashboard($rows)
    {
        $agencies = array();
        $agencyMap = array();

        foreach ($rows as $r) {
            $agId = (int)$r['id_agencia'];
            $cashierKey = $r['cod_usuario'] . '-' . $r['num_caja'];

            if (!isset($agencyMap[$agId])) {
                $agencyMap[$agId] = array(
                    'idAgencia' => $agId,
                    'nombre'    => $r['agencia'],
                    'abreviatura' => $r['agencia_abr'],
                    'cashiers'  => array(),
                    '_cashierMap' => array(),
                );
            }

            $ag = &$agencyMap[$agId];

            if (!isset($ag['_cashierMap'][$cashierKey])) {
                $hasApertura = $r['apertura'] !== null;
                $hasCierre   = $r['cierre'] !== null;
                $estado = 'sin_apertura';
                if ($hasApertura && !$hasCierre) $estado = 'activo';
                if ($hasApertura && $hasCierre)  $estado = 'cerrado';

                $ag['_cashierMap'][$cashierKey] = array(
                    'numCaja'      => (int)$r['num_caja'],
                    'codUsuario'   => $r['cod_usuario'],
                    'loginUsuario' => $r['login_usu'],
                    'nombreCajero' => $r['nombre_cajero'],
                    'estado'       => $estado,
                    'horaApertura' => $r['hora_apertura'],
                    'horaCierre'   => $r['hora_cierre'],
                    'numMovimientos' => 0,
                    'balances'     => array(),
                );
            }

            $cashier = &$ag['_cashierMap'][$cashierKey];
            $cashier['numMovimientos'] += (int)$r['num_movimientos'];

            $hasApertura = $r['apertura'] !== null;
            $hasCierre   = $r['cierre'] !== null;
            if ($hasApertura && !$hasCierre && $cashier['estado'] === 'sin_apertura') {
                $cashier['estado'] = 'activo';
            }

            $cashier['balances'][] = array(
                'idCaja'      => (int)$r['id_caja'],
                'idMoneda'    => (int)$r['id_moneda'],
                'moneda'      => $r['moneda'],
                'monedaDesc'  => $r['moneda_desc'],
                'apertura'    => $r['apertura'] !== null ? round((float)$r['apertura'], 2) : null,
                'cierre'      => $r['cierre'] !== null ? round((float)$r['cierre'], 2) : null,
                'saldoActual' => round((float)$r['saldo_actual'], 2),
                'maximo'      => round((float)$r['maximo'], 2),
                'minimo'      => round((float)$r['minimo'], 2),
            );
        }

        foreach ($agencyMap as &$ag) {
            $ag['cashiers'] = array_values($ag['_cashierMap']);
            unset($ag['_cashierMap']);
        }

        return array_values($agencyMap);
    }

    public static function getCashierMovements($numCaja, $idAgencia, $fecha)
    {
        $sql = "
            SELECT
                mc.id_movimiento_caja,
                mc.id_tipo_movimiento,
                CASE mc.id_tipo_movimiento
                    WHEN 1 THEN 'Ingreso'
                    WHEN 2 THEN 'Salida'
                    WHEN 3 THEN 'Pago'
                    ELSE 'Otro'
                END AS tipo_desc,
                mc.correlativo,
                mc.nombre,
                mc.recibido AS monto,
                mc.comprobante,
                mc.fec_crea_movimiento_caja AS fecha_hora,
                m.abreviatura AS moneda,
                c.id_caja
            FROM movimiento_caja mc
            JOIN caja c ON c.id_caja = mc.id_caja
            JOIN moneda m ON m.id_moneda = c.id_moneda
            WHERE c.num_caja = :num_caja
              AND c.id_agencia = :id_agencia
              AND c.id_tipo_unidad = 2
              AND DATE(mc.fec_crea_movimiento_caja) = :fecha
            ORDER BY mc.fec_crea_movimiento_caja
        ";

        $rows = Db::q($sql, array(
            ':num_caja'    => $numCaja,
            ':id_agencia'  => $idAgencia,
            ':fecha'       => $fecha,
        ));

        $result = array();
        foreach ($rows as $r) {
            $result[] = array(
                'id'           => (int)$r['id_movimiento_caja'],
                'tipo'         => (int)$r['id_tipo_movimiento'],
                'tipoDesc'     => $r['tipo_desc'],
                'correlativo'  => (int)$r['correlativo'],
                'nombre'       => $r['nombre'],
                'monto'        => round((float)$r['monto'], 2),
                'comprobante'  => $r['comprobante'],
                'fechaHora'    => $r['fecha_hora'],
                'moneda'       => $r['moneda'],
            );
        }
        return $result;
    }

    public static function getCashierArqueo($numCaja, $idAgencia, $fecha, $tipo)
    {
        $tipoArqueo = $tipo === 'cierre' ? 2 : 1;
        $sql = "
            SELECT
                co.valor,
                m.abreviatura AS moneda,
                c.id_moneda,
                atc.id_agrupacion,
                ag.descripcion AS agrupacion,
                atc.cantidad AS multiplicador,
                ac.cantidad,
                (co.valor * atc.cantidad * ac.cantidad) AS subtotal,
                ac.fec_crea_arqueo_caja AS fecha_hora
            FROM arqueo_caja ac
            JOIN caja c ON c.id_agencia = ac.id_agencia AND c.num_caja = ac.num_caja AND c.id_tipo_unidad = 2
            JOIN corte co ON co.id_corte = ac.id_corte
            JOIN moneda m ON m.id_moneda = c.id_moneda AND co.id_moneda = m.id_moneda
            JOIN agrupacion_tipo_corte atc ON atc.id_tipo_corte = ac.id_tipo_corte AND atc.id_agrupacion = ac.id_agrupacion
            JOIN agrupacion ag ON ag.id_agrupacion = ac.id_agrupacion
            WHERE ac.id_agencia = :id_agencia
              AND ac.num_caja = :num_caja
              AND DATE(ac.fecha) = :fecha
              AND ac.id_tipo_arqueo = :tipo_arqueo
              AND ac.cantidad > 0
            ORDER BY c.id_moneda, co.valor DESC, atc.id_agrupacion
        ";

        $rows = Db::q($sql, array(
            ':id_agencia'  => $idAgencia,
            ':num_caja'    => $numCaja,
            ':fecha'       => $fecha,
            ':tipo_arqueo' => $tipoArqueo,
        ));

        $currencies = array();
        $currMap = array();
        $fechaArqueo = null;

        foreach ($rows as $r) {
            $mon = $r['moneda'];
            if (!isset($currMap[$mon])) {
                $currMap[$mon] = array(
                    'moneda'  => $mon,
                    'items'   => array(),
                    'total'   => 0,
                );
            }
            $sub = round((float)$r['subtotal'], 2);
            $currMap[$mon]['items'][] = array(
                'valor'        => round((float)$r['valor'], 2),
                'agrupacion'   => $r['agrupacion'],
                'multiplicador'=> (int)$r['multiplicador'],
                'cantidad'     => (int)$r['cantidad'],
                'subtotal'     => $sub,
            );
            $currMap[$mon]['total'] += $sub;
            if (!$fechaArqueo && $r['fecha_hora']) $fechaArqueo = $r['fecha_hora'];
        }

        foreach ($currMap as &$c) {
            $c['total'] = round($c['total'], 2);
        }

        return array(
            'tipo'      => $tipo,
            'fechaHora' => $fechaArqueo,
            'currencies'=> array_values($currMap),
        );
    }

    public static function getSaldoHistory($idCaja, $fecha)
    {
        $sql = "
            SELECT
                sc.saldo,
                sc.fec_crea_saldo AS fecha_hora,
                sc.conciliacion,
                CASE sc.conciliacion
                    WHEN 'A'  THEN 'Apertura'
                    WHEN 'C'  THEN 'Cierre'
                    WHEN 'EE' THEN 'Entrada Efectivo'
                    WHEN 'SE' THEN 'Salida Efectivo'
                    WHEN 'PG' THEN 'Pago'
                    WHEN 'EO' THEN 'Entrada Operacion'
                    WHEN 'SO' THEN 'Salida Operacion'
                    WHEN 'EC' THEN 'Entrada Cambio'
                    WHEN 'SC' THEN 'Salida Cambio'
                    WHEN 'EG' THEN 'Entrada Giro'
                    WHEN 'SG' THEN 'Salida Giro'
                    WHEN 'ER' THEN 'Entrada Remesa'
                    WHEN 'SR' THEN 'Salida Remesa'
                    WHEN 'ET' THEN 'Entrada Transferencia'
                    WHEN 'ST' THEN 'Salida Transferencia'
                    WHEN 'DB' THEN 'Deposito Bancario'
                    WHEN 'RB' THEN 'Retiro Bancario'
                    ELSE sc.conciliacion
                END AS tipo_desc
            FROM saldo_caja sc
            WHERE sc.id_caja = :id_caja
              AND DATE(sc.fec_crea_saldo) = :fecha
            ORDER BY sc.fec_crea_saldo
        ";

        $rows = Db::q($sql, array(':id_caja' => $idCaja, ':fecha' => $fecha));
        $result = array();
        $acum = 0;
        foreach ($rows as $r) {
            $saldo = round((float)$r['saldo'], 2);
            $acum += $saldo;
            $result[] = array(
                'monto'        => $saldo,
                'acumulado'    => round($acum, 2),
                'fechaHora'    => $r['fecha_hora'],
                'conciliacion' => $r['conciliacion'],
                'tipoDesc'     => $r['tipo_desc'],
            );
        }
        return $result;
    }
}
