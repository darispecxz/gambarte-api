<?php

namespace App\Modules\POS\Services;

use App\Modules\Shared\Database\RawConnection;

class PosAdminService
{
    public function dashboard(string $fecha, ?int $agencia = null)
    {
        $params = array(':fecha' => $fecha);
        $where = '';
        if ($agencia) {
            $where = 'AND c.id_agencia = :id_agencia';
            $params[':id_agencia'] = $agencia;
        }

        $sql = "
            SELECT
                c.id_caja, c.id_agencia, c.num_caja, c.id_moneda,
                c.cod_usuario, c.maximo, c.minimo, c.estado AS estado_caja,
                a.descripcion AS agencia, a.abreviatura AS agencia_abr,
                m.abreviatura AS moneda, m.descripcion AS moneda_desc,
                TRIM(CONCAT(IFNULL(t.nom_trab,''), ' ', IFNULL(t.ape_pat_trab,''), ' ', IFNULL(t.ape_mat_trab,''))) AS nombre_cajero,
                u.login_usu,
                (SELECT sc.saldo FROM saldo_caja sc WHERE sc.id_caja = c.id_caja AND sc.conciliacion = 'A' AND DATE(sc.fec_crea_saldo) = :fecha LIMIT 1) AS apertura,
                (SELECT sc.fec_crea_saldo FROM saldo_caja sc WHERE sc.id_caja = c.id_caja AND sc.conciliacion = 'A' AND DATE(sc.fec_crea_saldo) = :fecha LIMIT 1) AS hora_apertura,
                (SELECT sc.saldo FROM saldo_caja sc WHERE sc.id_caja = c.id_caja AND sc.conciliacion = 'C' AND DATE(sc.fec_crea_saldo) = :fecha LIMIT 1) AS cierre,
                (SELECT sc.fec_crea_saldo FROM saldo_caja sc WHERE sc.id_caja = c.id_caja AND sc.conciliacion = 'C' AND DATE(sc.fec_crea_saldo) = :fecha LIMIT 1) AS hora_cierre,
                -- Apertura + movimientos del día. No vale `SUM(saldo)` con
                -- `conciliacion <> 'C'`: incluiría la fila de apertura 'A' dentro
                -- del total y duplicaría el saldo. Ver `CajeroBalanceService`.
                COALESCE((SELECT SUM(sc.saldo) FROM saldo_caja sc WHERE sc.id_caja = c.id_caja AND DATE(sc.fec_crea_saldo) = :fecha AND sc.conciliacion = 'A'), 0)
              + COALESCE((SELECT SUM(sc.saldo) FROM saldo_caja sc WHERE sc.id_caja = c.id_caja AND DATE(sc.fec_crea_saldo) = :fecha AND sc.conciliacion NOT IN ('A','C')), 0) AS saldo_actual,
                0 AS num_movimientos
            FROM caja c
            JOIN agencia a ON a.id_agencia = c.id_agencia
            JOIN moneda m ON m.id_moneda = c.id_moneda
            JOIN usuario u ON u.cod_usuario = c.cod_usuario
            LEFT JOIN trabajador t ON t.cod_trab = u.cod_trab
            WHERE c.estado = 'A'
              AND c.id_tipo_unidad = 2
              AND (
                EXISTS (SELECT 1 FROM saldo_caja sc2 WHERE sc2.id_caja = c.id_caja AND sc2.conciliacion = 'C' AND sc2.fec_crea_saldo >= DATE_SUB(:fecha, INTERVAL 30 DAY))
                OR EXISTS (SELECT 1 FROM saldo_caja sc3 WHERE sc3.id_caja = c.id_caja AND sc3.conciliacion = 'A' AND DATE(sc3.fec_crea_saldo) = :fecha)
              )
              $where
            ORDER BY a.descripcion, c.num_caja, c.id_moneda
        ";

        $rows = RawConnection::select($sql, $params);

        $opSql = "
            SELECT cod_usuario, SUM(total) AS total
            FROM (
                SELECT cod_usuario, COUNT(*) AS total FROM cambio WHERE DATE(fec_crea_cambio) = :f1 GROUP BY cod_usuario
                UNION ALL
                SELECT cod_usuario, COUNT(*) FROM giro WHERE DATE(fec_crea_giro) = :f2 GROUP BY cod_usuario
                UNION ALL
                SELECT cod_usuario, COUNT(*) FROM remesa WHERE DATE(fec_crea_remesa) = :f3 GROUP BY cod_usuario
                UNION ALL
                SELECT cod_usuario, COUNT(*) FROM pago WHERE DATE(fec_crea_pago) = :f4 GROUP BY cod_usuario
            ) t
            GROUP BY cod_usuario
        ";
        $opRows = RawConnection::select($opSql, [':f1' => $fecha, ':f2' => $fecha, ':f3' => $fecha, ':f4' => $fecha]);
        $opMap = [];
        foreach ($opRows as $r) {
            $opMap[$r['cod_usuario']] = (int)$r['total'];
        }

        return self::groupDashboard($rows, $opMap);
    }

    private static function groupDashboard($rows, $opMap = [])
    {
        $agencyMap = array();

        foreach ($rows as $r) {
            $agId = (int)$r['id_agencia'];
            $cashierKey = $r['cod_usuario'] . '-' . $r['num_caja'];

            if (!isset($agencyMap[$agId])) {
                $agencyMap[$agId] = array(
                    'idAgencia'   => $agId,
                    'nombre'      => $r['agencia'],
                    'abreviatura' => $r['agencia_abr'],
                    'cashiers'    => array(),
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
                    'numCaja'        => (int)$r['num_caja'],
                    'codUsuario'     => $r['cod_usuario'],
                    'loginUsuario'   => $r['login_usu'],
                    'nombreCajero'   => $r['nombre_cajero'],
                    'estado'         => $estado,
                    'horaApertura'   => $r['hora_apertura'],
                    'horaCierre'     => $r['hora_cierre'],
                    'numMovimientos' => $opMap[$r['cod_usuario']] ?? 0,
                    'balances'       => array(),
                );
            }

            $cashier = &$ag['_cashierMap'][$cashierKey];

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

    public function movements(int $numCaja, int $agencia, string $fecha)
    {
        $codUsuario = RawConnection::selectOne(
            "SELECT c.cod_usuario FROM caja c WHERE c.num_caja = :num_caja AND c.id_agencia = :ag AND c.id_tipo_unidad = 2 LIMIT 1",
            [':num_caja' => $numCaja, ':ag' => $agencia]
        );
        if (!$codUsuario) return [];

        $cod = $codUsuario['cod_usuario'];

        $sql = "
            SELECT id, tipo, tipo_desc, codigo, nombre, monto, moneda, fecha_hora FROM (
                SELECT c.id_cambio AS id, 1 AS tipo, 'Cambio' AS tipo_desc,
                    CAST(c.id_cambio AS CHAR) AS codigo,
                    TRIM(CONCAT_WS(' ', uf.nombres, uf.ape_pat, uf.ape_mat)) AS nombre,
                    c.total_operacion_bob AS monto,
                    m.abreviatura AS moneda,
                    c.fec_crea_cambio AS fecha_hora
                FROM cambio c
                JOIN usuario_financiero uf ON uf.id_tipo_doc_identidad = c.id_tipo_doc_identidad
                    AND uf.num_doc_identidad = c.num_doc_identidad AND uf.id_expedicion = c.id_expedicion
                JOIN moneda m ON m.id_moneda = CASE WHEN c.id_operacion = 1 THEN c.mon_rec ELSE c.mon_ent END
                WHERE c.cod_usuario = :c1 AND DATE(c.fec_crea_cambio) = :f1

                UNION ALL

                SELECT g.id_giro, 1, 'Giro',
                    CAST(g.codigo AS CHAR),
                    TRIM(CONCAT_WS(' ', uf.nombres, uf.ape_pat, uf.ape_mat)),
                    g.total_bob,
                    m.abreviatura,
                    g.fec_crea_giro
                FROM giro g
                JOIN usuario_financiero uf ON uf.id_tipo_doc_identidad = g.id_tipo_doc_identidad
                    AND uf.num_doc_identidad = g.num_doc_identidad AND uf.id_expedicion = g.id_expedicion
                JOIN moneda m ON m.id_moneda = g.mon_rec
                WHERE g.cod_usuario = :c2 AND DATE(g.fec_crea_giro) = :f2

                UNION ALL

                SELECT r.id_remesa, 1, 'Remesa',
                    CAST(r.codigo AS CHAR),
                    TRIM(CONCAT_WS(' ', uf.nombres, uf.ape_pat, uf.ape_mat)),
                    r.total_bob,
                    m.abreviatura,
                    r.fec_crea_remesa
                FROM remesa r
                JOIN usuario_financiero uf ON uf.id_tipo_doc_identidad = r.id_tipo_doc_identidad
                    AND uf.num_doc_identidad = r.num_doc_identidad AND uf.id_expedicion = r.id_expedicion
                JOIN moneda m ON m.id_moneda = r.mon_rec
                WHERE r.cod_usuario = :c3 AND DATE(r.fec_crea_remesa) = :f3

                UNION ALL

                SELECT p.id_pago, 2, 'Pago',
                    CAST(p.codigo AS CHAR),
                    TRIM(CONCAT_WS(' ', uf.nombres, uf.ape_pat, uf.ape_mat)),
                    p.total_bob,
                    'BOB',
                    p.fec_crea_pago
                FROM pago p
                JOIN usuario_financiero uf ON uf.id_tipo_doc_identidad = p.id_tipo_doc_identidad
                    AND uf.num_doc_identidad = p.num_doc_identidad AND uf.id_expedicion = p.id_expedicion
                WHERE p.cod_usuario = :c4 AND DATE(p.fec_crea_pago) = :f4 AND p.id_tipo_pago = 1
            ) t
            ORDER BY fecha_hora
        ";

        $rows = RawConnection::select($sql, [
            ':c1' => $cod, ':f1' => $fecha,
            ':c2' => $cod, ':f2' => $fecha,
            ':c3' => $cod, ':f3' => $fecha,
            ':c4' => $cod, ':f4' => $fecha,
        ]);

        $result = [];
        $i = 1;
        foreach ($rows as $r) {
            $result[] = [
                'id'          => (int)$r['id'],
                'tipo'        => (int)$r['tipo'],
                'tipoDesc'    => $r['tipo_desc'],
                'correlativo' => $i++,
                'nombre'      => $r['nombre'],
                'monto'       => round((float)$r['monto'], 2),
                'comprobante' => $r['codigo'],
                'fechaHora'   => $r['fecha_hora'],
                'moneda'      => $r['moneda'],
            ];
        }
        return $result;
    }

    public function arqueo(int $numCaja, int $agencia, string $fecha, string $tipo)
    {
        $tipoArqueo = $tipo === 'cierre' ? 2 : 1;
        $sql = "
            SELECT
                co.valor, m.abreviatura AS moneda, c.id_moneda,
                atc.id_agrupacion, ag.descripcion AS agrupacion,
                atc.cantidad AS multiplicador, ac.cantidad,
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

        $rows = RawConnection::select($sql, array(
            ':id_agencia'  => $agencia,
            ':num_caja'    => $numCaja,
            ':fecha'       => $fecha,
            ':tipo_arqueo' => $tipoArqueo,
        ));

        $currMap = array();
        $fechaArqueo = null;

        foreach ($rows as $r) {
            $mon = $r['moneda'];
            if (!isset($currMap[$mon])) {
                $currMap[$mon] = array('moneda' => $mon, 'items' => array(), 'total' => 0);
            }
            $sub = round((float)$r['subtotal'], 2);
            $currMap[$mon]['items'][] = array(
                'valor'         => round((float)$r['valor'], 2),
                'agrupacion'    => $r['agrupacion'],
                'multiplicador' => (int)$r['multiplicador'],
                'cantidad'      => (int)$r['cantidad'],
                'subtotal'      => $sub,
            );
            $currMap[$mon]['total'] += $sub;
            if (!$fechaArqueo && $r['fecha_hora']) $fechaArqueo = $r['fecha_hora'];
        }

        foreach ($currMap as &$c) {
            $c['total'] = round($c['total'], 2);
        }

        return array(
            'tipo'       => $tipo,
            'fechaHora'  => $fechaArqueo,
            'currencies' => array_values($currMap),
        );
    }

    public function saldoHistory(int $idCaja, string $fecha)
    {
        $sql = "
            SELECT
                sc.saldo, sc.fec_crea_saldo AS fecha_hora, sc.conciliacion,
                CASE sc.conciliacion
                    WHEN 'A'  THEN 'Apertura de Caja'
                    WHEN 'C'  THEN 'Cierre de Caja'
                    WHEN 'EC' THEN 'Entrada por Cambio de ME'
                    WHEN 'SC' THEN 'Salida por Cambio de ME'
                    WHEN 'VC' THEN 'Vuelto por Cambio de ME'
                    WHEN 'AC' THEN 'Anulación Cambio'
                    WHEN 'EG' THEN 'Entrada por Envío de Giro'
                    WHEN 'IG' THEN 'Entrada por Cobro de ITF en Giro'
                    WHEN 'OG' THEN 'Entrada por Comisión de Giro'
                    WHEN 'SG' THEN 'Salida por Pago de Giro'
                    WHEN 'DG' THEN 'Devolución Giro'
                    WHEN 'AG' THEN 'Anulación Giro'
                    WHEN 'ER' THEN 'Entrada por Envío de Remesa'
                    WHEN 'IR' THEN 'Entrada por Cobro de ITF en Remesa'
                    WHEN 'OR' THEN 'Entrada por Comisión de Remesa'
                    WHEN 'SR' THEN 'Salida por Pago de Remesa'
                    WHEN 'VR' THEN 'Devolución Remesa'
                    WHEN 'AR' THEN 'Anulación Remesa'
                    WHEN 'LR' THEN 'Liquida Remesa'
                    WHEN 'DR' THEN 'Depósito Remesa'
                    WHEN 'EO' THEN 'Entrada por Traspaso de Caja'
                    WHEN 'SO' THEN 'Salida por Traspaso a Caja'
                    WHEN 'ET' THEN 'Entrada por Transferencia de Bóveda'
                    WHEN 'ST' THEN 'Salida por Transferencia a Bóveda'
                    WHEN 'EE' THEN 'Entrada por Intereses Bancarios'
                    WHEN 'SE' THEN 'Salida por Cobro de ITF en Cuenta Bancaria'
                    WHEN 'PG' THEN 'Pago Gastos'
                    WHEN 'RB' THEN 'Reversión de Depósito Bancario a Cuenta GMB'
                    WHEN 'DB' THEN 'Salida por Depósito Bancario a Cuenta GMB'
                    WHEN 'EA' THEN 'Egreso Autorizado'
                    WHEN 'IA' THEN 'Ingreso Autorizado'
                    ELSE sc.conciliacion
                END AS tipo_desc
            FROM saldo_caja sc
            WHERE sc.id_caja = :id_caja AND DATE(sc.fec_crea_saldo) = :fecha
            ORDER BY sc.fec_crea_saldo
        ";

        $rows = RawConnection::select($sql, array(':id_caja' => $idCaja, ':fecha' => $fecha));
        $result = array();
        $acum = 0;
        foreach ($rows as $r) {
            $saldo = round((float)$r['saldo'], 2);
            if ($r['conciliacion'] !== 'C') {
                $acum += $saldo;
            }
            $result[] = array(
                'monto'        => $saldo,
                'acumulado'    => $r['conciliacion'] === 'C' ? round((float)$r['saldo'], 2) : round($acum, 2),
                'fechaHora'    => $r['fecha_hora'],
                'conciliacion' => $r['conciliacion'],
                'tipoDesc'     => $r['tipo_desc'],
            );
        }
        return $result;
    }

    public function fallasCaja(string $desde, string $hasta, ?int $agencia = null, float $holgura = 1)
    {
        $params = [':desde' => $desde, ':hasta' => $hasta, ':holgura' => $holgura];
        $agWhere = '';
        if ($agencia) {
            $agWhere = 'AND c.id_agencia = :id_agencia';
            $params[':id_agencia'] = $agencia;
        }

        $sql = "
            SELECT
                v.id_caja, v.fecha,
                ROUND(SUM(v.saldo), 2) AS diferencia,
                c.num_caja,
                ag.descripcion AS agencia, ag.abreviatura AS agencia_abr,
                m.abreviatura AS moneda,
                TRIM(CONCAT_WS(' ', t.nom_trab, t.ape_pat_trab, t.ape_mat_trab)) AS cajero,
                u.login_usu,
                fc.estado AS estado_falla, fc.glosa
            FROM v_reporte_operacion_diaria v
            JOIN caja c ON c.id_caja = v.id_caja
            JOIN agencia ag ON ag.id_agencia = c.id_agencia
            JOIN moneda m ON m.id_moneda = c.id_moneda
            JOIN usuario u ON u.cod_usuario = c.cod_usuario
            LEFT JOIN trabajador t ON t.cod_trab = u.cod_trab
            LEFT JOIN falla_caja fc ON fc.id_caja = v.id_caja AND fc.fecha = v.fecha
            WHERE v.fecha BETWEEN :desde AND :hasta
              AND v.fecha IN (
                  SELECT DISTINCT v2.fecha FROM v_reporte_operacion_diaria v2
                  WHERE v2.conciliacion COLLATE utf8mb4_general_ci = 'C' AND v2.id_caja = v.id_caja
              )
              $agWhere
            GROUP BY v.id_caja, v.fecha
            HAVING ABS(SUM(v.saldo)) > :holgura
            ORDER BY v.fecha DESC, ag.descripcion, c.num_caja
        ";

        $rows = RawConnection::select($sql, $params);

        $result = [];
        foreach ($rows as $r) {
            $dif = (float)$r['diferencia'];
            $result[] = [
                'idCaja'       => (int)$r['id_caja'],
                'numCaja'      => (int)$r['num_caja'],
                'fecha'        => $r['fecha'],
                'agencia'      => $r['agencia'],
                'agenciaAbr'   => $r['agencia_abr'],
                'cajero'       => $r['cajero'],
                'login'        => $r['login_usu'],
                'moneda'       => $r['moneda'],
                'diferencia'   => round($dif, 2),
                'tipo'         => $dif > 0 ? 'faltante' : 'sobrante',
                'estadoFalla'  => $r['estado_falla'],
                'glosa'        => $r['glosa'],
            ];
        }
        return $result;
    }

    public function ratificarFalla(int $idCaja, string $fecha, float $diferencia): array
    {
        $exists = RawConnection::selectOne(
            "SELECT 1 FROM falla_caja WHERE id_caja = :id_caja AND fecha = :fecha",
            [':id_caja' => $idCaja, ':fecha' => $fecha]
        );
        if ($exists) {
            return ['ok' => false, 'message' => 'Esta falla ya fue ratificada anteriormente.'];
        }

        $coef = -1;
        $dif = $diferencia * $coef;

        RawConnection::execute(
            "INSERT INTO falla_caja (id_caja, fecha, diferencia, glosa, fec_crea_falla_caja, fec_mod_falla_caja, estado)
             VALUES (:id_caja, :fecha, :dif, :glosa, NOW(), NULL, 'P')",
            [
                ':id_caja' => $idCaja,
                ':fecha'   => $fecha,
                ':dif'     => $dif,
                ':glosa'   => '',
            ]
        );

        return ['ok' => true, 'message' => 'Falla ratificada correctamente.'];
    }

    public function anularCierre(int $idCaja, string $fecha): array
    {
        if ($fecha !== date('Y-m-d')) {
            return ['ok' => false, 'message' => 'Solo se puede anular el cierre del día en curso.'];
        }

        $caja = RawConnection::selectOne(
            "SELECT id_agencia, num_caja FROM caja WHERE id_caja = :id_caja",
            [':id_caja' => $idCaja]
        );
        if (!$caja) {
            return ['ok' => false, 'message' => 'Caja no encontrada.'];
        }

        $idAgencia = $caja['id_agencia'];
        $numCaja   = $caja['num_caja'];

        $cierre = RawConnection::selectOne(
            "SELECT id_saldo_caja FROM saldo_caja
             WHERE id_caja = :id_caja AND DATE(fec_crea_saldo) = :fecha AND conciliacion = 'C'",
            [':id_caja' => $idCaja, ':fecha' => $fecha]
        );
        if (!$cierre) {
            return ['ok' => false, 'message' => 'No se encontró un cierre de caja para anular en esta fecha.'];
        }

        $falla = RawConnection::selectOne(
            "SELECT estado FROM falla_caja WHERE id_caja = :id_caja AND fecha = :fecha",
            [':id_caja' => $idCaja, ':fecha' => $fecha]
        );
        if ($falla && $falla['estado'] === 'C') {
            return ['ok' => false, 'message' => 'No se puede anular un cierre cuya falla ya fue cerrada.'];
        }

        $idCajasSubquery = "SELECT DISTINCT id_caja FROM caja WHERE id_agencia = :ag AND num_caja = :nc";

        RawConnection::execute(
            "DELETE FROM saldo_caja
             WHERE id_caja IN($idCajasSubquery)
               AND DATE(fec_crea_saldo) = :fecha AND conciliacion = 'C'",
            [':ag' => $idAgencia, ':nc' => $numCaja, ':fecha' => $fecha]
        );

        RawConnection::execute(
            "DELETE FROM arqueo_caja
             WHERE id_agencia = :ag AND num_caja = :nc
               AND fecha = :fecha AND id_tipo_arqueo = 2",
            [':ag' => $idAgencia, ':nc' => $numCaja, ':fecha' => $fecha]
        );

        $cajas = RawConnection::select($idCajasSubquery, [':ag' => $idAgencia, ':nc' => $numCaja]);
        foreach ($cajas as $c) {
            RawConnection::execute(
                "DELETE FROM saldo_boveda
                 WHERE DATE(fec_crea_saldo) = :fecha
                   AND conciliacion = :conc",
                [':fecha' => $fecha, ':conc' => 'ECC-' . $c['id_caja']]
            );
        }

        return ['ok' => true, 'message' => 'Cierre de caja anulado correctamente. La caja queda en estado activo.'];
    }
}
