<?php

namespace App\Modules\Bancos\Services;

use App\Modules\Shared\Database\RawConnection;

class BankService
{
    private static $extensions = array('pdf', 'jpg', 'jpeg', 'png', 'gif');

    public function entities()
    {
        $sql = "SELECT ef.id_entidad_financiera, ef.descripcion, ef.nombre_corto,
                       ef.abreviatura, ef.id_tipo_entidad_financiera
                FROM entidad_financiera ef WHERE ef.estado = 'A' ORDER BY ef.descripcion";
        return RawConnection::select($sql);
    }

    public function accounts(?int $idAgencia = null)
    {
        $sql = "SELECT cb.id_agencia, cb.id_tipo_cuenta_bancaria, cb.id_entidad_financiera,
                       cb.num_cuenta_bancaria, cb.id_moneda, cb.codsubcuenta, cb.estado,
                       a.descripcion AS agencia, ef.abreviatura AS entidad, ef.descripcion AS entidad_desc,
                       m.abreviatura AS moneda, tcb.descripcion AS tipo_cuenta, tcb.abreviatura AS tipo_cuenta_abr
                FROM cuenta_bancaria cb
                INNER JOIN agencia a ON a.id_agencia = cb.id_agencia
                INNER JOIN entidad_financiera ef ON ef.id_entidad_financiera = cb.id_entidad_financiera
                INNER JOIN moneda m ON m.id_moneda = cb.id_moneda
                INNER JOIN tipo_cuenta_bancaria tcb ON tcb.id_tipo_cuenta_bancaria = cb.id_tipo_cuenta_bancaria
                WHERE cb.estado = 'A' AND (:id_agencia IS NULL OR cb.id_agencia = :id_agencia)
                ORDER BY a.descripcion, ef.descripcion, m.abreviatura";
        $rows = RawConnection::select($sql, array(':id_agencia' => $idAgencia));

        return array_map(function ($r) {
            return array(
                'idAgencia'           => (int)$r['id_agencia'],
                'agencia'             => $r['agencia'],
                'idEntidadFinanciera' => (int)$r['id_entidad_financiera'],
                'entidad'             => $r['entidad'],
                'entidadDesc'         => $r['entidad_desc'],
                'idTipoCuenta'        => (int)$r['id_tipo_cuenta_bancaria'],
                'tipoCuenta'          => $r['tipo_cuenta'],
                'tipoCuentaAbr'       => $r['tipo_cuenta_abr'],
                'numCuenta'           => $r['num_cuenta_bancaria'],
                'idMoneda'            => (int)$r['id_moneda'],
                'moneda'              => $r['moneda'],
                'codsubcuenta'        => $r['codsubcuenta'],
            );
        }, $rows);
    }

    public function movements(string $desde, ?string $hasta = null, ?int $idAgencia = null,
                              ?int $idMoneda = null, ?string $numCuenta = null, ?string $conciliacion = null)
    {
        $conditions = array();
        $params = array();

        if ($hasta && $hasta !== $desde) {
            $conditions[] = 'DATE(mcb.fec_crea_movimiento_cuenta_bancaria) BETWEEN :desde AND :hasta';
            $params[':desde'] = $desde;
            $params[':hasta'] = $hasta;
        } else {
            $conditions[] = 'DATE(mcb.fec_crea_movimiento_cuenta_bancaria) = :desde';
            $params[':desde'] = $desde;
        }
        if ($idAgencia !== null)  { $conditions[] = 'mcb.id_agencia = :id_agencia'; $params[':id_agencia'] = $idAgencia; }
        if ($idMoneda !== null)   { $conditions[] = 'mcb.id_moneda = :id_moneda';   $params[':id_moneda'] = $idMoneda; }
        if ($numCuenta !== null && $numCuenta !== '') { $conditions[] = 'mcb.num_cuenta_bancaria = :num_cuenta'; $params[':num_cuenta'] = $numCuenta; }
        if ($conciliacion !== null && $conciliacion !== '' && $conciliacion !== 'TODO') { $conditions[] = 'mcb.conciliacion = :conciliacion'; $params[':conciliacion'] = $conciliacion; }

        $where = implode(' AND ', $conditions);
        $sql = "SELECT mcb.id_agencia, mcb.id_tipo_cuenta_bancaria, mcb.id_entidad_financiera,
                       mcb.num_cuenta_bancaria, mcb.id_moneda, mcb.monto, mcb.conciliacion,
                       mcb.glosa, mcb.fec_crea_movimiento_cuenta_bancaria AS fecha_registro, mcb.cod_usuario,
                       a.descripcion AS agencia, ef.abreviatura AS entidad, m.abreviatura AS moneda, u.login_usu AS usuario
                FROM movimiento_cuenta_bancaria mcb
                INNER JOIN agencia a ON a.id_agencia = mcb.id_agencia
                INNER JOIN entidad_financiera ef ON ef.id_entidad_financiera = mcb.id_entidad_financiera
                INNER JOIN moneda m ON m.id_moneda = mcb.id_moneda
                INNER JOIN usuario u ON u.cod_usuario = mcb.cod_usuario
                WHERE {$where}
                ORDER BY mcb.fec_crea_movimiento_cuenta_bancaria, mcb.id_agencia, mcb.id_entidad_financiera, mcb.id_moneda, mcb.num_cuenta_bancaria";

        $rows = RawConnection::select($sql, $params);

        return array_map(function ($r) {
            $monto = (float)$r['monto'];
            $conc  = $r['conciliacion'];
            return array(
                'idAgencia'           => (int)$r['id_agencia'],
                'agencia'             => $r['agencia'],
                'idEntidadFinanciera' => (int)$r['id_entidad_financiera'],
                'entidad'             => $r['entidad'],
                'numCuenta'           => $r['num_cuenta_bancaria'],
                'idMoneda'            => (int)$r['id_moneda'],
                'moneda'              => $r['moneda'],
                'monto'               => $monto,
                'concepto'            => ($monto >= 0 && $conc !== 'C') ? 'INGRESO' : 'EGRESO',
                'conciliacion'        => $conc,
                'conciliacionDesc'    => self::conciliacionLabel($conc),
                'glosa'               => $r['glosa'],
                'fechaRegistro'       => $r['fecha_registro'],
                'usuario'             => $r['usuario'],
            );
        }, $rows);
    }

    public function conciliacionContable(string $fecha, ?int $idAgencia = null)
    {
        $ejercicio = substr($fecha, 0, 4);
        $cuentas = self::queryBankAccountsForReconciliation($idAgencia);
        $obelSaldos = self::queryAllObelBalances($fecha, $idAgencia);

        $codsubcuentas = array();
        foreach ($cuentas as $c) {
            if (!empty($c['codsubcuenta'])) $codsubcuentas[] = $c['codsubcuenta'];
        }
        $contableSaldos = !empty($codsubcuentas) ? self::queryAllContableBalances($codsubcuentas, $ejercicio, $fecha) : array();

        $items = array();
        $totalDiscrepancies = 0;
        $totalesPorMoneda = array();

        foreach ($cuentas as $c) {
            $codsubcuenta = $c['codsubcuenta'];
            if (empty($codsubcuenta)) continue;

            $obelKey = $c['id_agencia'] . '-' . $c['id_entidad_financiera'] . '-' . $c['num_cuenta_bancaria'] . '-' . $c['id_moneda'];
            $saldoOBEL = isset($obelSaldos[$obelKey]) ? $obelSaldos[$obelKey] : 0.0;
            $saldoContable = isset($contableSaldos[$codsubcuenta]) ? $contableSaldos[$codsubcuenta] : 0.0;
            $diferencia = round($saldoOBEL - $saldoContable, 2);

            if ($diferencia != 0) $totalDiscrepancies++;

            $m = $c['moneda'];
            if (!isset($totalesPorMoneda[$m])) {
                $totalesPorMoneda[$m] = array('moneda' => $m, 'totalOBEL' => 0, 'totalContable' => 0, 'totalDiferencia' => 0);
            }
            $totalesPorMoneda[$m]['totalOBEL'] += $saldoOBEL;
            $totalesPorMoneda[$m]['totalContable'] += $saldoContable;
            $totalesPorMoneda[$m]['totalDiferencia'] += $diferencia;

            $items[] = array(
                'idAgencia'           => (int)$c['id_agencia'],
                'idEntidadFinanciera' => (int)$c['id_entidad_financiera'],
                'agencia'             => $c['agencia'],
                'entidad'             => $c['entidad'],
                'entidadDesc'         => $c['entidad_desc'],
                'numCuenta'           => $c['num_cuenta_bancaria'],
                'idMoneda'            => (int)$c['id_moneda'],
                'moneda'              => $m,
                'codsubcuenta'        => $codsubcuenta,
                'saldoOBEL'           => $saldoOBEL,
                'saldoContable'       => $saldoContable,
                'diferencia'          => $diferencia,
                'conciliado'          => ($diferencia == 0),
            );
        }

        return array(
            'fecha'              => $fecha,
            'cuentas'            => $items,
            'totalesPorMoneda'   => array_values($totalesPorMoneda),
            'totalDiscrepancies' => $totalDiscrepancies,
            'totalCuentas'       => count($items),
        );
    }

    public function conciliacionDetalle(string $fecha, int $idAgencia, int $idEntidad, string $numCuenta, int $idMoneda)
    {
        $ejercicio = substr($fecha, 0, 4);
        $codRows = RawConnection::select(
            "SELECT codsubcuenta FROM cuenta_bancaria WHERE id_agencia = :ag AND id_entidad_financiera = :ef AND num_cuenta_bancaria = :num AND id_moneda = :mon LIMIT 1",
            array(':ag' => $idAgencia, ':ef' => $idEntidad, ':num' => $numCuenta, ':mon' => $idMoneda)
        );
        if (empty($codRows) || empty($codRows[0]['codsubcuenta'])) {
            return array('causas' => array(), 'movsSinContabilidad' => array(), 'asientosSinOBEL' => array());
        }
        $codsubcuenta = $codRows[0]['codsubcuenta'];
        $cuenta = array('id_agencia' => $idAgencia, 'id_entidad_financiera' => $idEntidad, 'num_cuenta_bancaria' => $numCuenta, 'id_moneda' => $idMoneda);

        return self::analyzeDiscrepancy($cuenta, $codsubcuenta, $ejercicio, $fecha);
    }

    public function dailySummary(string $fecha)
    {
        $sql = "SELECT m.id_agencia, m.id_entidad_financiera, m.num_cuenta_bancaria, m.id_moneda,
                       a.descripcion AS agencia, ef.abreviatura AS entidad, mo.abreviatura AS moneda,
                       SUM(CASE WHEN m.conciliacion = 'A'  THEN m.monto ELSE 0 END) AS apertura,
                       SUM(CASE WHEN m.conciliacion = 'DE' THEN m.monto ELSE 0 END) AS depositos,
                       SUM(CASE WHEN m.conciliacion = 'ST' THEN m.monto ELSE 0 END) AS traspasos_salida,
                       SUM(CASE WHEN m.conciliacion = 'RT' THEN m.monto ELSE 0 END) AS reversiones,
                       SUM(CASE WHEN m.conciliacion = 'CC' THEN m.monto ELSE 0 END) AS cobro_cheques,
                       SUM(CASE WHEN m.conciliacion = 'DR' THEN m.monto ELSE 0 END) AS debitos_remesa,
                       SUM(CASE WHEN m.conciliacion = 'EX' THEN m.monto ELSE 0 END) AS ingresos_externos,
                       SUM(CASE WHEN m.conciliacion NOT IN ('A','C') THEN m.monto ELSE 0 END) AS total_movimientos,
                       SUM(CASE WHEN m.conciliacion != 'C' THEN m.monto ELSE 0 END) AS total_sistema,
                       SUM(CASE WHEN m.conciliacion = 'C'  THEN m.monto ELSE 0 END) AS cierre
                FROM movimiento_cuenta_bancaria m
                INNER JOIN agencia a ON a.id_agencia = m.id_agencia
                INNER JOIN entidad_financiera ef ON ef.id_entidad_financiera = m.id_entidad_financiera
                INNER JOIN moneda mo ON mo.id_moneda = m.id_moneda
                WHERE DATE(m.fec_crea_movimiento_cuenta_bancaria) = :fecha
                GROUP BY m.id_agencia, m.id_entidad_financiera, m.num_cuenta_bancaria, m.id_moneda, a.descripcion, ef.abreviatura, mo.abreviatura
                ORDER BY a.descripcion, ef.abreviatura, mo.abreviatura";

        $rows = RawConnection::select($sql, array(':fecha' => $fecha));
        $items = array();
        foreach ($rows as $r) {
            $totalSistema = (float)$r['total_sistema'];
            $cierre = (float)$r['cierre'];
            $diferencia = ($cierre != 0) ? $cierre - $totalSistema : null;

            $items[] = array(
                'idAgencia'        => (int)$r['id_agencia'],
                'agencia'          => $r['agencia'],
                'entidad'          => $r['entidad'],
                'numCuenta'        => $r['num_cuenta_bancaria'],
                'moneda'           => $r['moneda'],
                'apertura'         => (float)$r['apertura'],
                'depositos'        => (float)$r['depositos'],
                'traspasosSalida'  => (float)$r['traspasos_salida'],
                'reversiones'      => (float)$r['reversiones'],
                'cobroCheques'     => (float)$r['cobro_cheques'],
                'debitosRemesa'    => (float)$r['debitos_remesa'],
                'ingresosExternos' => (float)$r['ingresos_externos'],
                'totalMovimientos' => (float)$r['total_movimientos'],
                'totalSistema'     => $totalSistema,
                'cierre'           => $cierre,
                'diferencia'       => $diferencia,
            );
        }
        return array('fecha' => $fecha, 'accounts' => $items);
    }

    public function transfers(string $desde, ?string $hasta = null, ?string $estado = null)
    {
        $conditions = array();
        $params = array();

        if ($hasta && $hasta !== $desde) {
            $conditions[] = 'DATE(t.fec_crea_traspaso_cuenta_bancaria) BETWEEN :desde AND :hasta';
            $params[':desde'] = $desde;
            $params[':hasta'] = $hasta;
        } else {
            $conditions[] = 'DATE(t.fec_crea_traspaso_cuenta_bancaria) = :desde';
            $params[':desde'] = $desde;
        }
        if ($estado !== null && $estado !== '') { $conditions[] = 't.estado = :estado'; $params[':estado'] = $estado; }

        $where = implode(' AND ', $conditions);
        $sql = "SELECT t.id_traspaso_cuenta_bancaria,
                       t.ent_fin_origen, t.num_cuenta_origen, t.agencia_origen,
                       t.ent_fin_destino, t.num_cuenta_destino, t.agencia_destino,
                       t.monto_total, t.estado,
                       t.fec_crea_traspaso_cuenta_bancaria AS fecha_creacion,
                       t.fec_mod_traspaso_cuenta_bancaria AS fecha_modificacion,
                       ao.descripcion AS agencia_origen_desc, ad.descripcion AS agencia_destino_desc,
                       efo.abreviatura AS entidad_origen, efd.abreviatura AS entidad_destino
                FROM traspaso_cuenta_bancaria t
                INNER JOIN agencia ao ON ao.id_agencia = t.agencia_origen
                INNER JOIN agencia ad ON ad.id_agencia = t.agencia_destino
                INNER JOIN entidad_financiera efo ON efo.id_entidad_financiera = t.ent_fin_origen
                INNER JOIN entidad_financiera efd ON efd.id_entidad_financiera = t.ent_fin_destino
                WHERE {$where}
                ORDER BY t.fec_crea_traspaso_cuenta_bancaria DESC";

        $rows = RawConnection::select($sql, $params);

        return array_map(function ($r) {
            $estadoMap = array('P' => 'Pendiente', 'A' => 'Aceptado', 'R' => 'Revertido');
            return array(
                'id'                => (int)$r['id_traspaso_cuenta_bancaria'],
                'origenAgencia'     => $r['agencia_origen_desc'],
                'origenEntidad'     => $r['entidad_origen'],
                'origenCuenta'      => $r['num_cuenta_origen'],
                'destinoAgencia'    => $r['agencia_destino_desc'],
                'destinoEntidad'    => $r['entidad_destino'],
                'destinoCuenta'     => $r['num_cuenta_destino'],
                'monto'             => (float)$r['monto_total'],
                'estado'            => $r['estado'],
                'estadoDesc'        => isset($estadoMap[$r['estado']]) ? $estadoMap[$r['estado']] : $r['estado'],
                'fechaCreacion'     => $r['fecha_creacion'],
                'fechaModificacion' => $r['fecha_modificacion'],
            );
        }, $rows);
    }

    public function movementDetail(int $idAgencia, int $idEntidad, string $numCuenta, int $idMoneda,
                                   string $fecha, string $conciliacion, float $monto)
    {
        $movSql = "SELECT mcb.id_agencia, mcb.id_tipo_cuenta_bancaria, mcb.id_entidad_financiera,
                          mcb.num_cuenta_bancaria, mcb.id_moneda, mcb.monto, mcb.conciliacion,
                          mcb.glosa, mcb.fec_crea_movimiento_cuenta_bancaria AS fecha_registro, mcb.cod_usuario,
                          a.descripcion AS agencia, ef.abreviatura AS entidad, ef.descripcion AS entidad_desc,
                          m.abreviatura AS moneda, m.descripcion AS moneda_desc,
                          tcb.descripcion AS tipo_cuenta, u.login_usu AS usuario
                   FROM movimiento_cuenta_bancaria mcb
                   INNER JOIN agencia a ON a.id_agencia = mcb.id_agencia
                   INNER JOIN entidad_financiera ef ON ef.id_entidad_financiera = mcb.id_entidad_financiera
                   INNER JOIN moneda m ON m.id_moneda = mcb.id_moneda
                   INNER JOIN tipo_cuenta_bancaria tcb ON tcb.id_tipo_cuenta_bancaria = mcb.id_tipo_cuenta_bancaria
                   INNER JOIN usuario u ON u.cod_usuario = mcb.cod_usuario
                   WHERE mcb.id_agencia = :ag AND mcb.id_entidad_financiera = :ef
                     AND mcb.num_cuenta_bancaria = :num AND mcb.id_moneda = :mon
                     AND mcb.fec_crea_movimiento_cuenta_bancaria = :fecha
                     AND mcb.conciliacion = :conc AND mcb.monto = :monto
                   LIMIT 1";
        $movRows = RawConnection::select($movSql, array(
            ':ag' => $idAgencia, ':ef' => $idEntidad, ':num' => $numCuenta, ':mon' => $idMoneda,
            ':fecha' => $fecha, ':conc' => $conciliacion, ':monto' => $monto,
        ));

        if (empty($movRows)) return null;

        $r = $movRows[0];
        $montoVal = (float)$r['monto'];
        $conc     = $r['conciliacion'];

        $movement = array(
            'idAgencia' => (int)$r['id_agencia'], 'agencia' => $r['agencia'],
            'idEntidadFinanciera' => (int)$r['id_entidad_financiera'],
            'entidad' => $r['entidad'], 'entidadDesc' => $r['entidad_desc'],
            'numCuenta' => $r['num_cuenta_bancaria'],
            'idMoneda' => (int)$r['id_moneda'], 'moneda' => $r['moneda'], 'monedaDesc' => $r['moneda_desc'],
            'tipoCuenta' => $r['tipo_cuenta'], 'monto' => $montoVal,
            'concepto' => ($montoVal >= 0 && $conc !== 'C') ? 'INGRESO' : 'EGRESO',
            'conciliacion' => $conc, 'conciliacionDesc' => self::conciliacionLabel($conc),
            'glosa' => $r['glosa'], 'fechaRegistro' => $r['fecha_registro'], 'usuario' => $r['usuario'],
        );

        $codRows = RawConnection::select(
            "SELECT codsubcuenta FROM cuenta_bancaria WHERE id_agencia = :ag AND id_entidad_financiera = :ef AND num_cuenta_bancaria = :num AND id_moneda = :mon LIMIT 1",
            array(':ag' => $idAgencia, ':ef' => $idEntidad, ':num' => $numCuenta, ':mon' => $idMoneda)
        );
        $codsubcuenta = !empty($codRows) ? $codRows[0]['codsubcuenta'] : null;

        $asiento = null;
        if ($codsubcuenta) {
            $fechaDate = substr($fecha, 0, 10);
            $ejercicio = substr($fechaDate, 0, 4);
            $absMonto  = abs($montoVal);

            $glosaRef = null;
            if ($r['glosa'] && preg_match('/#(\d+)/', $r['glosa'], $gm)) $glosaRef = $gm[1];

            $partSql = "SELECT a.idasiento, a.codejercicio, a.concepto, a.tipodocumento,
                               a.fecha, a.importe, a.numero, a.idconcepto,
                               p.codsubcuenta, p.debe, p.haber, p.documento, p.concepto AS detalle_partida
                        FROM co_partidas p JOIN co_asientos a ON a.idasiento = p.idasiento
                        WHERE p.codsubcuenta = :cod AND a.codejercicio = :ej AND a.fecha = :fecha
                          AND ((p.debe != 0 AND ABS(p.debe - :monto1) < 0.01) OR (p.haber != 0 AND ABS(p.haber - :monto2) < 0.01))";
            $params = array(':cod' => $codsubcuenta, ':ej' => $ejercicio, ':fecha' => $fechaDate, ':monto1' => $absMonto, ':monto2' => $absMonto);

            $partRows = array();
            if ($glosaRef) {
                $refParams = $params;
                $refParams[':ref'] = '%#' . $glosaRef . '%';
                $partRows = RawConnection::select($partSql . ' AND a.concepto LIKE :ref LIMIT 1', $refParams, 'conta');
            }
            if (empty($partRows)) {
                $partRows = RawConnection::select($partSql . ' LIMIT 1', $params, 'conta');
            }

            if (!empty($partRows)) {
                $pr = $partRows[0];
                $idasiento = (int)$pr['idasiento'];

                $allParts = RawConnection::select(
                    "SELECT p.codsubcuenta, p.debe, p.haber, p.documento, p.concepto AS detalle, p.coddivisa,
                            COALESCE(s.descripcion, p.codsubcuenta) AS subcuenta_desc
                     FROM co_partidas p LEFT JOIN co_subcuentas s ON s.codsubcuenta = p.codsubcuenta AND s.codejercicio = :ej
                     WHERE p.idasiento = :id ORDER BY p.debe DESC, p.codsubcuenta",
                    array(':id' => $idasiento, ':ej' => $ejercicio), 'conta'
                );

                $partidas = array();
                $totalDebe = 0;
                $totalHaber = 0;
                foreach ($allParts as $ap) {
                    $d = (float)$ap['debe'];
                    $h = (float)$ap['haber'];
                    $totalDebe  += $d;
                    $totalHaber += $h;
                    $partidas[] = array(
                        'codsubcuenta' => $ap['codsubcuenta'], 'subcuentaDesc' => $ap['subcuenta_desc'],
                        'detalle' => $ap['detalle'], 'documento' => $ap['documento'],
                        'debe' => $d, 'haber' => $h,
                    );
                }

                $tipoDoc = $pr['tipodocumento'];
                $tipoMap = array('SC'=>'Comprobante de Caja-Egreso','EC'=>'Comprobante de Caja-Ingreso','T'=>'Comprobante de Traspaso','E'=>'Comprobante de Egreso','I'=>'Comprobante de Ingreso');
                $parts = explode('-', $tipoDoc);
                $tipoLabel = isset($tipoMap[$parts[0]]) ? $tipoMap[$parts[0]] : 'Comprobante de Contabilidad';

                $asiento = array(
                    'idasiento' => $idasiento, 'codejercicio' => $pr['codejercicio'],
                    'concepto' => $pr['concepto'], 'tipodocumento' => $tipoDoc, 'tipoLabel' => $tipoLabel,
                    'fecha' => $pr['fecha'], 'importe' => (float)$pr['importe'],
                    'numero' => (int)$pr['numero'], 'idconcepto' => $pr['idconcepto'],
                    'partidas' => $partidas, 'totalDebe' => round($totalDebe, 2), 'totalHaber' => round($totalHaber, 2),
                );
            }
        }

        $traspaso = null;
        if (in_array($conc, array('ST', 'ET'))) {
            $trSql = "SELECT t.id_traspaso_cuenta_bancaria, t.monto_total, t.estado,
                             t.documento_origen, t.documento_destino,
                             t.fec_crea_traspaso_cuenta_bancaria AS fecha_creacion,
                             ao.descripcion AS agencia_origen, ad.descripcion AS agencia_destino,
                             efo.abreviatura AS entidad_origen, efd.abreviatura AS entidad_destino,
                             t.num_cuenta_origen, t.num_cuenta_destino
                      FROM traspaso_cuenta_bancaria t
                      INNER JOIN agencia ao ON ao.id_agencia = t.agencia_origen
                      INNER JOIN agencia ad ON ad.id_agencia = t.agencia_destino
                      INNER JOIN entidad_financiera efo ON efo.id_entidad_financiera = t.ent_fin_origen
                      INNER JOIN entidad_financiera efd ON efd.id_entidad_financiera = t.ent_fin_destino
                      WHERE ((t.agencia_origen = :ag AND t.ent_fin_origen = :ef AND t.num_cuenta_origen = :num)
                          OR (t.agencia_destino = :ag2 AND t.ent_fin_destino = :ef2 AND t.num_cuenta_destino = :num2))
                        AND ABS(t.monto_total - :monto) < 0.01
                        AND DATE(t.fec_crea_traspaso_cuenta_bancaria) = DATE(:fecha)
                      LIMIT 1";
            $trRows = RawConnection::select($trSql, array(
                ':ag' => $idAgencia, ':ef' => $idEntidad, ':num' => $numCuenta,
                ':ag2' => $idAgencia, ':ef2' => $idEntidad, ':num2' => $numCuenta,
                ':monto' => abs($montoVal), ':fecha' => $fecha,
            ));

            if (!empty($trRows)) {
                $tr = $trRows[0];
                $estadoMap = array('P' => 'Pendiente', 'A' => 'Aceptado', 'R' => 'Revertido');
                $traspaso = array(
                    'id' => (int)$tr['id_traspaso_cuenta_bancaria'], 'monto' => (float)$tr['monto_total'],
                    'estado' => $tr['estado'], 'estadoDesc' => isset($estadoMap[$tr['estado']]) ? $estadoMap[$tr['estado']] : $tr['estado'],
                    'documentoOrigen' => $tr['documento_origen'], 'documentoDestino' => $tr['documento_destino'],
                    'fechaCreacion' => $tr['fecha_creacion'],
                    'origenAgencia' => $tr['agencia_origen'], 'origenEntidad' => $tr['entidad_origen'], 'origenCuenta' => $tr['num_cuenta_origen'],
                    'destinoAgencia' => $tr['agencia_destino'], 'destinoEntidad' => $tr['entidad_destino'], 'destinoCuenta' => $tr['num_cuenta_destino'],
                );
            }
        }

        $comprobantes = self::findComprobantes($conc, $idAgencia, $idEntidad, $numCuenta, $idMoneda, $fecha, $montoVal, $r['glosa'], $traspaso);

        return array(
            'movimiento'   => $movement, 'codsubcuenta' => $codsubcuenta,
            'asiento'      => $asiento, 'traspaso' => $traspaso,
            'comprobanteUrl' => !empty($comprobantes) ? $comprobantes[0]['url'] : null,
            'comprobantes' => $comprobantes,
        );
    }

    public function saldos(?int $idAgencia = null)
    {
        $ejercicio = date('Y');
        $hoy = date('Y-m-d');

        $cuentas = self::queryBankAccountsForReconciliation($idAgencia);

        // Last closing (conciliacion='C') balance + date per account in current year
        $sql = "SELECT m.id_agencia, m.id_entidad_financiera, m.num_cuenta_bancaria, m.id_moneda,
                       m.monto, DATE(m.fec_crea_movimiento_cuenta_bancaria) AS fecha_cierre
                FROM movimiento_cuenta_bancaria m
                INNER JOIN (
                    SELECT id_agencia, id_entidad_financiera, num_cuenta_bancaria, id_moneda,
                           MAX(fec_crea_movimiento_cuenta_bancaria) AS max_fecha
                    FROM movimiento_cuenta_bancaria
                    WHERE conciliacion = 'C'
                      AND YEAR(fec_crea_movimiento_cuenta_bancaria) = :ej
                    GROUP BY id_agencia, id_entidad_financiera, num_cuenta_bancaria, id_moneda
                ) sub ON m.id_agencia = sub.id_agencia
                     AND m.id_entidad_financiera = sub.id_entidad_financiera
                     AND m.num_cuenta_bancaria = sub.num_cuenta_bancaria
                     AND m.id_moneda = sub.id_moneda
                     AND m.fec_crea_movimiento_cuenta_bancaria = sub.max_fecha
                WHERE m.conciliacion = 'C'";
        $rows = RawConnection::select($sql, [':ej' => $ejercicio]);

        $cierres = [];
        foreach ($rows as $r) {
            $key = $r['id_agencia'] . '-' . $r['id_entidad_financiera'] . '-' . $r['num_cuenta_bancaria'] . '-' . $r['id_moneda'];
            $cierres[$key] = ['saldo' => (float) $r['monto'], 'fechaCierre' => $r['fecha_cierre']];
        }

        // Contable balances
        $codsubcuentas = [];
        foreach ($cuentas as $c) {
            if (!empty($c['codsubcuenta'])) $codsubcuentas[] = $c['codsubcuenta'];
        }
        $contable = !empty($codsubcuentas)
            ? self::queryAllContableBalances($codsubcuentas, $ejercicio, $hoy)
            : [];

        // Operating accounts: find accounts whose last apertura has no cierre after it
        // (covers accounts opened today OR opened days ago and never closed)
        $sqlOp = "SELECT m.id_agencia, m.id_entidad_financiera, m.num_cuenta_bancaria, m.id_moneda,
                         SUM(CASE WHEN m.conciliacion != 'C' THEN m.monto ELSE 0 END) AS saldo_actual
                  FROM movimiento_cuenta_bancaria m
                  INNER JOIN (
                      SELECT id_agencia, id_entidad_financiera, num_cuenta_bancaria, id_moneda,
                             MAX(fec_crea_movimiento_cuenta_bancaria) AS ultima_apertura
                      FROM movimiento_cuenta_bancaria
                      WHERE conciliacion = 'A'
                        AND YEAR(fec_crea_movimiento_cuenta_bancaria) = :ej1
                      GROUP BY id_agencia, id_entidad_financiera, num_cuenta_bancaria, id_moneda
                  ) aper ON m.id_agencia = aper.id_agencia
                        AND m.id_entidad_financiera = aper.id_entidad_financiera
                        AND m.num_cuenta_bancaria = aper.num_cuenta_bancaria
                        AND m.id_moneda = aper.id_moneda
                        AND m.fec_crea_movimiento_cuenta_bancaria >= aper.ultima_apertura
                  WHERE YEAR(m.fec_crea_movimiento_cuenta_bancaria) = :ej2
                  GROUP BY m.id_agencia, m.id_entidad_financiera, m.num_cuenta_bancaria, m.id_moneda
                  HAVING MAX(CASE WHEN m.conciliacion = 'C' THEN 1 ELSE 0 END) = 0";
        $rowsOp = RawConnection::select($sqlOp, [':ej1' => $ejercicio, ':ej2' => $ejercicio]);

        $operando = [];
        foreach ($rowsOp as $r) {
            $key = $r['id_agencia'] . '-' . $r['id_entidad_financiera'] . '-' . $r['num_cuenta_bancaria'] . '-' . $r['id_moneda'];
            $operando[$key] = [
                'saldoActual' => (float) $r['saldo_actual'],
                'cerrado'     => false,
            ];
        }

        $items = [];
        $totales = [];
        foreach ($cuentas as $c) {
            $key = $c['id_agencia'] . '-' . $c['id_entidad_financiera'] . '-' . $c['num_cuenta_bancaria'] . '-' . $c['id_moneda'];
            $cierre = $cierres[$key] ?? null;
            $saldoOBEL = $cierre ? $cierre['saldo'] : 0;
            $fechaCierre = $cierre ? $cierre['fechaCierre'] : null;
            $saldoContable = $contable[$c['codsubcuenta'] ?? ''] ?? 0;
            $moneda = $c['moneda'];

            $opHoy = $operando[$key] ?? null;
            $estaOperando = $opHoy && !$opHoy['cerrado'];
            $saldoActual = $estaOperando ? round($opHoy['saldoActual'], 2) : null;

            if ($saldoOBEL == 0 && $saldoContable == 0 && $saldoActual === null) continue;

            $saldoParaTotal = $estaOperando ? $opHoy['saldoActual'] : $saldoOBEL;

            if (!isset($totales[$moneda])) {
                $totales[$moneda] = ['moneda' => $moneda, 'totalOBEL' => 0, 'totalContable' => 0, 'cuentas' => 0];
            }
            $totales[$moneda]['totalOBEL'] += $saldoParaTotal;
            $totales[$moneda]['totalContable'] += $saldoContable;
            $totales[$moneda]['cuentas']++;

            $items[] = [
                'idAgencia'           => (int) $c['id_agencia'],
                'agencia'             => $c['agencia'],
                'idEntidadFinanciera' => (int) $c['id_entidad_financiera'],
                'entidad'             => $c['entidad'],
                'entidadDesc'         => $c['entidad_desc'],
                'numCuenta'           => $c['num_cuenta_bancaria'],
                'idMoneda'            => (int) $c['id_moneda'],
                'moneda'              => $moneda,
                'codsubcuenta'        => $c['codsubcuenta'] ?? null,
                'saldoOBEL'           => round($saldoOBEL, 2),
                'saldoContable'       => round($saldoContable, 2),
                'saldoActual'         => $saldoActual,
                'operando'            => $estaOperando,
                'fechaCierre'         => $fechaCierre,
                'diasSinCierre'       => $fechaCierre ? (int) ((strtotime($hoy) - strtotime($fechaCierre)) / 86400) : null,
            ];
        }

        foreach ($totales as &$t) {
            $t['totalOBEL'] = round($t['totalOBEL'], 2);
            $t['totalContable'] = round($t['totalContable'], 2);
        }

        return [
            'ejercicio'       => $ejercicio,
            'fecha'           => $hoy,
            'cuentas'         => $items,
            'totalesPorMoneda' => array_values($totales),
            'totalCuentas'    => count($items),
        ];
    }

    // ── Private helpers ──

    private static function queryBankAccountsForReconciliation($idAgencia = null)
    {
        $sql = "SELECT cb.id_agencia, cb.id_tipo_cuenta_bancaria, cb.id_entidad_financiera,
                       cb.num_cuenta_bancaria, cb.id_moneda, cb.codsubcuenta,
                       a.descripcion AS agencia, ef.abreviatura AS entidad, ef.descripcion AS entidad_desc, m.abreviatura AS moneda
                FROM cuenta_bancaria cb
                INNER JOIN agencia a ON a.id_agencia = cb.id_agencia
                INNER JOIN entidad_financiera ef ON ef.id_entidad_financiera = cb.id_entidad_financiera
                INNER JOIN moneda m ON m.id_moneda = cb.id_moneda
                WHERE cb.estado = 'A' AND (:id_agencia IS NULL OR cb.id_agencia = :id_agencia)
                ORDER BY a.descripcion, ef.descripcion, m.abreviatura";
        return RawConnection::select($sql, array(':id_agencia' => $idAgencia));
    }

    private static function queryAllObelBalances($fecha, $idAgencia = null)
    {
        $sql = "SELECT m.id_agencia, m.id_entidad_financiera, m.num_cuenta_bancaria, m.id_moneda, m.monto
                FROM movimiento_cuenta_bancaria m
                INNER JOIN (
                    SELECT id_agencia, id_entidad_financiera, num_cuenta_bancaria, id_moneda,
                           MAX(fec_crea_movimiento_cuenta_bancaria) AS max_fecha
                    FROM movimiento_cuenta_bancaria WHERE conciliacion = 'C' AND DATE(fec_crea_movimiento_cuenta_bancaria) <= :fecha
                    GROUP BY id_agencia, id_entidad_financiera, num_cuenta_bancaria, id_moneda
                ) sub ON m.id_agencia = sub.id_agencia AND m.id_entidad_financiera = sub.id_entidad_financiera
                     AND m.num_cuenta_bancaria = sub.num_cuenta_bancaria AND m.id_moneda = sub.id_moneda
                     AND m.fec_crea_movimiento_cuenta_bancaria = sub.max_fecha
                WHERE m.conciliacion = 'C'";
        $rows = RawConnection::select($sql, array(':fecha' => $fecha));

        $result = array();
        foreach ($rows as $r) {
            $key = $r['id_agencia'] . '-' . $r['id_entidad_financiera'] . '-' . $r['num_cuenta_bancaria'] . '-' . $r['id_moneda'];
            $result[$key] = (float)$r['monto'];
        }
        return $result;
    }

    private static function queryAllContableBalances($codsubcuentas, $ejercicio, $fecha)
    {
        if (empty($codsubcuentas)) return array();
        $placeholders = array();
        $params = array(':ej' => $ejercicio, ':fecha' => $fecha);
        foreach (array_values(array_unique($codsubcuentas)) as $i => $cod) {
            $ph = ':c' . $i;
            $placeholders[] = $ph;
            $params[$ph] = $cod;
        }
        $inList = implode(',', $placeholders);
        $sql = "SELECT p.codsubcuenta, COALESCE(SUM(p.debe - p.haber), 0) AS saldo
                FROM co_partidas p JOIN co_asientos a ON a.idasiento = p.idasiento
                WHERE p.codsubcuenta IN ($inList) AND a.codejercicio = :ej AND a.fecha <= :fecha
                GROUP BY p.codsubcuenta";
        $rows = RawConnection::select($sql, $params, 'conta');

        $result = array();
        foreach ($rows as $r) $result[$r['codsubcuenta']] = (float)$r['saldo'];
        return $result;
    }

    private static function analyzeDiscrepancy($cuenta, $codsubcuenta, $ejercicio, $fecha)
    {
        $movsSinContabilidad = self::queryMovsSinContabilidad($cuenta, $codsubcuenta, $ejercicio, $fecha);
        $asientosSinOBEL = self::queryAsientosSinOBEL($cuenta, $codsubcuenta, $ejercicio, $fecha);

        $causas = array();
        $ingresosNoRegistrados = 0;
        $egresosNoRegistrados = 0;

        foreach ($movsSinContabilidad as $m) {
            if ($m['monto'] > 0) $ingresosNoRegistrados++;
            else $egresosNoRegistrados++;
        }
        if ($egresosNoRegistrados > 0) $causas[] = array('tipo' => 'egreso_no_registrado', 'label' => 'Egreso sin registro contable', 'count' => $egresosNoRegistrados);
        if ($ingresosNoRegistrados > 0) $causas[] = array('tipo' => 'ingreso_no_registrado', 'label' => 'Ingreso sin registro contable', 'count' => $ingresosNoRegistrados);
        if (count($asientosSinOBEL) > 0) $causas[] = array('tipo' => 'asiento_sin_movimiento', 'label' => 'Asiento contable sin movimiento OBEL', 'count' => count($asientosSinOBEL));

        return array('causas' => $causas, 'movsSinContabilidad' => $movsSinContabilidad, 'asientosSinOBEL' => $asientosSinOBEL);
    }

    private static function queryMovsSinContabilidad($cuenta, $codsubcuenta, $ejercicio, $fecha)
    {
        $movs = RawConnection::select(
            "SELECT mcb.monto, mcb.conciliacion, mcb.glosa, DATE(mcb.fec_crea_movimiento_cuenta_bancaria) AS fecha_mov, u.login_usu AS usuario
             FROM movimiento_cuenta_bancaria mcb INNER JOIN usuario u ON u.cod_usuario = mcb.cod_usuario
             WHERE mcb.id_agencia = :ag AND mcb.id_entidad_financiera = :ef AND mcb.num_cuenta_bancaria = :num AND mcb.id_moneda = :mon
               AND YEAR(mcb.fec_crea_movimiento_cuenta_bancaria) = :ej AND DATE(mcb.fec_crea_movimiento_cuenta_bancaria) <= :fecha
               AND mcb.conciliacion NOT IN ('A', 'C') ORDER BY mcb.fec_crea_movimiento_cuenta_bancaria",
            array(':ag' => $cuenta['id_agencia'], ':ef' => $cuenta['id_entidad_financiera'],
                  ':num' => $cuenta['num_cuenta_bancaria'], ':mon' => $cuenta['id_moneda'],
                  ':ej' => $ejercicio, ':fecha' => $fecha)
        );

        $partidas = RawConnection::select(
            "SELECT a.fecha, p.debe, p.haber FROM co_partidas p JOIN co_asientos a ON a.idasiento = p.idasiento
             WHERE p.codsubcuenta = :cod AND a.codejercicio = :ej AND a.fecha <= :fecha AND (p.debe != 0 OR p.haber != 0)",
            array(':cod' => $codsubcuenta, ':ej' => $ejercicio, ':fecha' => $fecha), 'conta'
        );

        $partidasIndex = array();
        foreach ($partidas as $p) {
            $key = $p['fecha'];
            if (!isset($partidasIndex[$key])) $partidasIndex[$key] = array();
            $partidasIndex[$key][] = $p;
        }

        $sinContabilidad = array();
        foreach ($movs as $m) {
            $fechaMov = $m['fecha_mov'];
            $montoMov = (float)$m['monto'];
            $found = false;
            if (isset($partidasIndex[$fechaMov])) {
                foreach ($partidasIndex[$fechaMov] as $idx => $p) {
                    $debe = (float)$p['debe'];
                    $haber = (float)$p['haber'];
                    if (($montoMov > 0 && abs($debe - $montoMov) < 0.01) || ($montoMov < 0 && abs($haber - abs($montoMov)) < 0.01)) {
                        $found = true;
                        unset($partidasIndex[$fechaMov][$idx]);
                        break;
                    }
                }
            }
            if (!$found) {
                $sinContabilidad[] = array(
                    'fecha' => $fechaMov, 'monto' => $montoMov,
                    'conciliacion' => $m['conciliacion'], 'conciliacionDesc' => self::conciliacionLabel($m['conciliacion']),
                    'glosa' => $m['glosa'], 'usuario' => $m['usuario'],
                    'tipo' => ($montoMov >= 0) ? 'INGRESO' : 'EGRESO',
                );
            }
        }
        return $sinContabilidad;
    }

    private static function queryAsientosSinOBEL($cuenta, $codsubcuenta, $ejercicio, $fecha)
    {
        $partidas = RawConnection::select(
            "SELECT a.fecha, a.concepto, a.tipodocumento, p.debe, p.haber, a.idasiento
             FROM co_partidas p JOIN co_asientos a ON a.idasiento = p.idasiento
             WHERE p.codsubcuenta = :cod AND a.codejercicio = :ej AND a.fecha <= :fecha AND (p.debe != 0 OR p.haber != 0) ORDER BY a.fecha",
            array(':cod' => $codsubcuenta, ':ej' => $ejercicio, ':fecha' => $fecha), 'conta'
        );

        $movs = RawConnection::select(
            "SELECT DATE(fec_crea_movimiento_cuenta_bancaria) AS fecha_mov, monto, conciliacion
             FROM movimiento_cuenta_bancaria
             WHERE id_agencia = :ag AND id_entidad_financiera = :ef AND num_cuenta_bancaria = :num AND id_moneda = :mon
               AND YEAR(fec_crea_movimiento_cuenta_bancaria) = :ej AND DATE(fec_crea_movimiento_cuenta_bancaria) <= :fecha
               AND conciliacion NOT IN ('A', 'C')",
            array(':ag' => $cuenta['id_agencia'], ':ef' => $cuenta['id_entidad_financiera'],
                  ':num' => $cuenta['num_cuenta_bancaria'], ':mon' => $cuenta['id_moneda'],
                  ':ej' => $ejercicio, ':fecha' => $fecha)
        );

        $movsIndex = array();
        foreach ($movs as $m) {
            $key = $m['fecha_mov'];
            if (!isset($movsIndex[$key])) $movsIndex[$key] = array();
            $movsIndex[$key][] = $m;
        }

        $sinOBEL = array();
        foreach ($partidas as $p) {
            $fechaPart = $p['fecha'];
            $debe = (float)$p['debe'];
            $haber = (float)$p['haber'];
            $found = false;
            if (isset($movsIndex[$fechaPart])) {
                foreach ($movsIndex[$fechaPart] as $idx => $m) {
                    $montoMov = (float)$m['monto'];
                    if (($debe > 0 && abs($montoMov - $debe) < 0.01) || ($haber > 0 && abs(abs($montoMov) - $haber) < 0.01)) {
                        $found = true;
                        unset($movsIndex[$fechaPart][$idx]);
                        break;
                    }
                }
            }
            if (!$found) {
                $montoRelevante = ($debe > 0) ? $debe : -$haber;
                $sinOBEL[] = array(
                    'fecha' => $fechaPart, 'concepto' => $p['concepto'], 'tipodocumento' => $p['tipodocumento'],
                    'debe' => $debe, 'haber' => $haber, 'monto' => $montoRelevante,
                    'tipo' => ($debe > 0) ? 'INGRESO' : 'EGRESO', 'idasiento' => (int)$p['idasiento'],
                );
            }
        }
        return $sinOBEL;
    }

    private static function findComprobantes($conciliacion, $idAgencia, $idEntidad, $numCuenta, $idMoneda, $fecha, $monto, $glosa, $traspaso)
    {
        $comprobantes = array();

        if ($conciliacion === 'DE') {
            $rows = RawConnection::select(
                "SELECT id_caja, id_agencia_destino, id_entidad_financiera, num_cuenta_bancaria, fec_crea_deposito_cuenta_caja, comprobante
                 FROM deposito_cuenta WHERE fec_mod_deposito_cuenta_caja = :fecha AND id_entidad_financiera = :ef AND num_cuenta_bancaria = :num AND estado = 1",
                array(':fecha' => $fecha, ':ef' => $idEntidad, ':num' => $numCuenta)
            );
            foreach ($rows as $r) {
                $base = 'depositos/' . $r['id_caja'] . '/' . $r['id_agencia_destino'] . '/' . $r['id_entidad_financiera'] . '/' . $r['num_cuenta_bancaria'] . '/' . $r['fec_crea_deposito_cuenta_caja'] . '/deposito';
                $comprobantes[] = array('tipo' => 'deposito', 'label' => 'Comprobante de Depósito', 'comprobante' => $r['comprobante'], 'url' => self::resolveUrl($base));
            }
        }

        if ($conciliacion === 'DR') {
            $codigoPago = null;
            if ($glosa && preg_match('/#(\d+)/', $glosa, $m)) $codigoPago = $m[1];

            if ($codigoPago) {
                $rows = RawConnection::select("SELECT codigo FROM pago WHERE codigo = :cod AND id_tipo_pago = 1", array(':cod' => $codigoPago));
            } else {
                $rows = RawConnection::select(
                    "SELECT p.codigo FROM pago p WHERE p.id_tipo_pago = 1 AND p.id_agencia = :ag AND ABS(p.monto - :monto) < 0.01 AND DATE(p.fec_crea_pago) = :fecha",
                    array(':ag' => $idAgencia, ':monto' => abs((float)$monto), ':fecha' => substr($fecha, 0, 10))
                );
            }
            foreach ($rows as $r) {
                $comprobantes[] = array('tipo' => 'boleta_remesa', 'label' => 'Boleta de Remesa', 'comprobante' => $r['codigo'], 'url' => self::resolveUrl('boletas/' . $r['codigo'] . '/deposito'));
            }
        }

        if ($conciliacion === 'CC' && $glosa && preg_match('/CHEQUE\s*#?(\d+)/i', $glosa, $m)) {
            $numCheque = ltrim($m[1], '0') ?: '0';
            $fechaDate = substr($fecha, 0, 10);
            $prefix = 'bancos/cheque/' . $fechaDate . '/' . $numCuenta . '_' . $numCheque;
            $comprobantes[] = array('tipo' => 'cheque_anverso', 'label' => 'Cheque #' . $numCheque . ' — Anverso', 'comprobante' => $numCheque, 'url' => self::resolveUrl($prefix . '_an'));
            $comprobantes[] = array('tipo' => 'cheque_reverso', 'label' => 'Cheque #' . $numCheque . ' — Reverso', 'comprobante' => $numCheque, 'url' => self::resolveUrl($prefix . '_re'));
        }

        if ($conciliacion === 'ST') {
            $docNum = null;
            if ($traspaso && !empty($traspaso['documentoOrigen'])) $docNum = $traspaso['documentoOrigen'];
            elseif ($glosa && preg_match('/TRANSACCION\s*#?(\S+)/i', $glosa, $m)) $docNum = $m[1];
            if ($docNum) {
                $comprobantes[] = array('tipo' => 'comprobante_traspaso', 'label' => 'Comprobante de Traspaso', 'comprobante' => $docNum, 'url' => self::resolveUrl('bancos/comprobante/' . substr($fecha, 0, 10) . '/' . $docNum));
            }
        }

        return $comprobantes;
    }

    private static function resolveUrl($basePath)
    {
        $root = env('CGR_PUBLIC_PATH', '/home/u752765750/domains/cgr.owl-tech.net/public_html');
        $base = env('CGR_PUBLIC_URL', 'https://cgr.owl-tech.net');

        foreach (self::$extensions as $ext) {
            $file = $root . '/' . $basePath . '.' . $ext;
            if (file_exists($file)) return $base . '/' . $basePath . '.' . $ext;
        }
        return $base . '/' . $basePath . '.pdf';
    }

    private static function conciliacionLabel($code)
    {
        $labels = array(
            'A' => 'Apertura', 'C' => 'Cierre', 'ST' => 'Salida Traspaso',
            'RT' => 'Reversión / Reintegro', 'DE' => 'Depósito Efectivo',
            'DR' => 'Débito Remesa', 'CC' => 'Cobro de Cheque',
            'EX' => 'Ingreso Externo', 'SC' => 'Saldo Cheque', 'IC' => 'Ingreso Cheque',
            'QC' => 'Cobro QR Cambio', 'TF' => 'Cobro Transferencia Cambio',
        );
        return isset($labels[$code]) ? $labels[$code] : $code;
    }
}
