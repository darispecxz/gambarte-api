<?php

namespace App\Modules\Contabilidad\Services;

use App\Modules\Shared\Database\RawConnection;

class AccountingService
{
    // ═══════════════════════════════════════════════════════════════════
    //  Reconciliation (per-cashier breakdown)
    // ═══════════════════════════════════════════════════════════════════

    public function cashierDetails(string $fecha, ?int $agencia = null)
    {
        $rows = $this->queryReconciliation($fecha, $agencia);
        $fallas = $this->queryFallasCaja($fecha, $agencia);

        $fallasMap = array();
        foreach ($fallas as $f) {
            $fallasMap[$f['id_caja']] = $f['diferencia'];
        }

        $cashierGroups = array();
        foreach ($rows as $row) {
            $key = $row['login_usuario'] . '|' . $row['id_agencia'] . '|' . $row['agencia'];
            if (!isset($cashierGroups[$key])) {
                $cashierGroups[$key] = array(
                    'loginUsuario' => $row['login_usuario'],
                    'idAgencia'    => $row['id_agencia'],
                    'agencia'      => $row['agencia'],
                    'rows'         => array()
                );
            }
            $cashierGroups[$key]['rows'][] = $row;
        }

        $cashiers = array();
        foreach ($cashierGroups as $group) {
            $mapped = $this->mapCashier($group['loginUsuario'], $group['idAgencia'], $group['agencia'], $group['rows'], $fallasMap);
            if (!empty($mapped['currencies'])) {
                $cashiers[] = $mapped;
            }
        }

        usort($cashiers, function ($a, $b) {
            $cmp = strcmp($a['agencia'], $b['agencia']);
            if ($cmp !== 0) return $cmp;
            return strcmp($a['cajero'], $b['cajero']);
        });

        $totalDiscrepancies = 0;
        foreach ($cashiers as $cashier) {
            foreach ($cashier['currencies'] as $currency) {
                if ($currency['diferencia'] != 0) {
                    $totalDiscrepancies++;
                }
            }
        }

        return array(
            'fecha' => $fecha,
            'cashiers' => $cashiers,
            'totalDiscrepancies' => $totalDiscrepancies
        );
    }

    private function mapCashier($cajero, $idAgencia, $agencia, $rows, $fallasMap)
    {
        $currencies = array();
        $fallasRegistradas = array();

        foreach ($rows as $row) {
            list($totalIngreso, $totalEgreso) = $this->computeTotals($row);
            $theoreticalBalance = $totalIngreso - abs($totalEgreso);
            $cierreCaja = (float)$row['cierre_caja'];
            $diferencia = $cierreCaja - $theoreticalBalance;

            if ($totalIngreso == 0 && $totalEgreso == 0 && $cierreCaja == 0) {
                continue;
            }

            if (isset($fallasMap[$row['id_caja']])) {
                $fallasRegistradas[$row['id_caja']] = $fallasMap[$row['id_caja']];
            }

            $currencies[] = array(
                'idCaja'    => $row['id_caja'],
                'numCaja'   => $row['num_caja'],
                'moneda'    => $row['moneda'],
                'tipoCambioOficial' => (float)$row['tipo_cambio'],

                'aperturaCaja'  => (float)$row['apertura_caja'],
                'cambioMonedaIngreso' => array(
                    'count'  => (int)$row['cambio_moneda_ing_cnt'],
                    'amount' => (float)$row['cambio_moneda_ing']
                ),
                'envioGiro' => array(
                    'count'  => (int)$row['envio_giro_cnt'],
                    'amount' => (float)$row['envio_giro']
                ),
                'envioRemesa' => array(
                    'count'  => (int)$row['envio_remesa_cnt'],
                    'amount' => (float)$row['envio_remesa']
                ),
                'traspasoDeCaja' => array(
                    'count'  => (int)$row['traspaso_de_caja_cnt'],
                    'amount' => (float)$row['traspaso_de_caja']
                ),
                'cobroCheque' => array(
                    'count'  => (int)$row['cobro_cheque_cnt'],
                    'amount' => (float)$row['cobro_cheque']
                ),
                'ingresoControlado' => array(
                    'count'  => (int)$row['ingreso_controlado_cnt'],
                    'amount' => (float)$row['ingreso_controlado']
                ),
                'transferenciaBovedaIngreso' => array(
                    'count'  => (int)$row['transferencia_boveda_ingreso_cnt'],
                    'amount' => (float)$row['entrada_transferencia_boveda']
                ),

                'cambioMonedaEgreso' => array(
                    'count'  => (int)$row['cambio_moneda_egr_cnt'],
                    'amount' => (float)$row['cambio_moneda_egr']
                ),
                'pagoGiro' => array(
                    'count'  => (int)$row['pago_giro_cnt'],
                    'amount' => (float)$row['pago_giro']
                ),
                'devolucionGiro' => (float)$row['devolucion_giro'],
                'pagoRemesa' => array(
                    'count'  => (int)$row['pago_remesa_cnt'],
                    'amount' => (float)$row['pago_remesa']
                ),
                'devolucionRemesa' => (float)$row['devolucion_remesa'],
                'traspasoACaja' => array(
                    'count'  => (int)$row['traspaso_a_caja_cnt'],
                    'amount' => (float)$row['traspaso_a_caja']
                ),
                'depositoBancario' => array(
                    'count'  => (int)$row['deposito_bancario_cnt'],
                    'amount' => (float)$row['deposito_bancario']
                ),
                'egresoControlado' => array(
                    'count'  => (int)$row['egreso_controlado_cnt'],
                    'amount' => (float)$row['egreso_controlado']
                ),
                'transferenciaBovedaEgreso' => array(
                    'count'  => (int)$row['transferencia_boveda_egreso_cnt'],
                    'amount' => (float)$row['salida_transferencia_boveda']
                ),

                'totalIngreso' => (float)$totalIngreso,
                'totalEgreso'  => (float)$totalEgreso,
                'totalSaldo'   => (float)$theoreticalBalance,
                'cierreCaja'   => $cierreCaja,
                'diferencia'   => (float)$diferencia
            );
        }

        usort($currencies, function ($a, $b) {
            return strcmp($a['moneda'], $b['moneda']);
        });

        return array(
            'cajero'            => $cajero,
            'idAgencia'         => $idAgencia,
            'agencia'           => $agencia,
            'currencies'        => $currencies,
            'fallasRegistradas' => $fallasRegistradas
        );
    }

    private function computeTotals($row)
    {
        $totalIngreso =
            (float)$row['apertura_caja'] +
            (float)$row['cambio_moneda_ing'] +
            (float)$row['envio_giro'] +
            (float)$row['envio_remesa'] +
            (float)$row['entrada_transferencia_boveda'] +
            (float)$row['traspaso_de_caja'] +
            (float)$row['cobro_cheque'] +
            (float)$row['ingreso_controlado'];

        $totalEgreso =
            (float)$row['cambio_moneda_egr'] +
            (float)$row['pago_giro'] +
            (float)$row['devolucion_giro'] +
            (float)$row['pago_remesa'] +
            (float)$row['devolucion_remesa'] +
            (float)$row['salida_transferencia_boveda'] +
            (float)$row['traspaso_a_caja'] +
            (float)$row['deposito_bancario'] +
            (float)$row['egreso_controlado'];

        return array($totalIngreso, $totalEgreso);
    }

    private function queryReconciliation(string $fecha, ?int $idAgencia = null)
    {
        $sql = <<<'SQL'
            WITH LatestRate AS (
                SELECT co1.id_moneda, co1.valor
                FROM cotizacion_oficial co1
                INNER JOIN (
                    SELECT id_moneda, MAX(fec_crea_cotizacion_oficial) AS max_fecha
                    FROM cotizacion_oficial
                    WHERE id_operacion = 1
                    GROUP BY id_moneda
                ) co2 ON co1.id_moneda = co2.id_moneda
                     AND co1.fec_crea_cotizacion_oficial = co2.max_fecha
                WHERE co1.id_operacion = 1
            )
            SELECT
                c.id_caja,
                c.num_caja,
                u.login_usu AS login_usuario,
                a.id_agencia,
                a.descripcion AS agencia,
                m.abreviatura AS moneda,
                COALESCE(lr.valor, 1) AS tipo_cambio,

                SUM(CASE WHEN sc.conciliacion = 'A' THEN sc.saldo ELSE 0 END) AS apertura_caja,
                SUM(CASE WHEN sc.conciliacion IN ('EC','AC') THEN sc.saldo ELSE 0 END) AS cambio_moneda_ing,
                COUNT(CASE WHEN sc.conciliacion IN ('EC','AC') THEN 1 END) AS cambio_moneda_ing_cnt,
                SUM(CASE WHEN sc.conciliacion IN ('EG','IG','OG','AG') THEN sc.saldo ELSE 0 END) AS envio_giro,
                COUNT(CASE WHEN sc.conciliacion IN ('EG','IG','OG','AG') THEN 1 END) AS envio_giro_cnt,
                SUM(CASE WHEN sc.conciliacion IN ('ER','IR','OR','AR') THEN sc.saldo ELSE 0 END) AS envio_remesa,
                COUNT(CASE WHEN sc.conciliacion IN ('ER','IR','OR','AR') THEN 1 END) AS envio_remesa_cnt,
                SUM(CASE WHEN sc.conciliacion = 'ET' THEN sc.saldo ELSE 0 END) AS entrada_transferencia_boveda,
                COUNT(CASE WHEN sc.conciliacion = 'ET' THEN 1 END) AS transferencia_boveda_ingreso_cnt,
                SUM(CASE WHEN sc.conciliacion = 'EO' THEN sc.saldo ELSE 0 END) AS traspaso_de_caja,
                COUNT(CASE WHEN sc.conciliacion = 'EO' THEN 1 END) AS traspaso_de_caja_cnt,
                SUM(CASE WHEN sc.conciliacion IN ('EE','RB') THEN sc.saldo ELSE 0 END) AS cobro_cheque,
                COUNT(CASE WHEN sc.conciliacion IN ('EE','RB') THEN 1 END) AS cobro_cheque_cnt,
                SUM(CASE WHEN sc.conciliacion = 'IA' THEN sc.saldo ELSE 0 END) AS ingreso_controlado,
                COUNT(CASE WHEN sc.conciliacion = 'IA' THEN 1 END) AS ingreso_controlado_cnt,

                SUM(CASE WHEN sc.conciliacion IN ('SC','VC') THEN sc.saldo ELSE 0 END) AS cambio_moneda_egr,
                COUNT(CASE WHEN sc.conciliacion IN ('SC','VC') THEN 1 END) AS cambio_moneda_egr_cnt,
                SUM(CASE WHEN sc.conciliacion = 'SG' THEN sc.saldo ELSE 0 END) AS pago_giro,
                COUNT(CASE WHEN sc.conciliacion = 'SG' THEN 1 END) AS pago_giro_cnt,
                SUM(CASE WHEN sc.conciliacion = 'DG' THEN sc.saldo ELSE 0 END) AS devolucion_giro,
                SUM(CASE WHEN sc.conciliacion IN ('SR','LR') THEN sc.saldo ELSE 0 END) AS pago_remesa,
                COUNT(CASE WHEN sc.conciliacion IN ('SR','LR') THEN 1 END) AS pago_remesa_cnt,
                SUM(CASE WHEN sc.conciliacion = 'VR' THEN sc.saldo ELSE 0 END) AS devolucion_remesa,
                SUM(CASE WHEN sc.conciliacion = 'ST' THEN sc.saldo ELSE 0 END) AS salida_transferencia_boveda,
                COUNT(CASE WHEN sc.conciliacion = 'ST' THEN 1 END) AS transferencia_boveda_egreso_cnt,
                SUM(CASE WHEN sc.conciliacion = 'SO' THEN sc.saldo ELSE 0 END) AS traspaso_a_caja,
                COUNT(CASE WHEN sc.conciliacion = 'SO' THEN 1 END) AS traspaso_a_caja_cnt,
                SUM(CASE WHEN sc.conciliacion IN ('SE','DR','DB') THEN sc.saldo ELSE 0 END) AS deposito_bancario,
                COUNT(CASE WHEN sc.conciliacion IN ('SE','DR','DB') THEN 1 END) AS deposito_bancario_cnt,
                SUM(CASE WHEN sc.conciliacion IN ('EA','PG') THEN sc.saldo ELSE 0 END) AS egreso_controlado,
                COUNT(CASE WHEN sc.conciliacion IN ('EA','PG') THEN 1 END) AS egreso_controlado_cnt,

                SUM(CASE WHEN sc.conciliacion = 'C' THEN sc.saldo ELSE 0 END) AS cierre_caja

            FROM saldo_caja sc
            INNER JOIN caja c ON c.id_caja = sc.id_caja
            INNER JOIN moneda m ON m.id_moneda = c.id_moneda
            INNER JOIN agencia a ON a.id_agencia = c.id_agencia
            INNER JOIN usuario u ON u.cod_usuario = c.cod_usuario
            LEFT JOIN LatestRate lr ON lr.id_moneda = c.id_moneda
            WHERE DATE(sc.fec_crea_saldo) = :fecha
              AND (:id_agencia IS NULL OR a.id_agencia = :id_agencia)
            GROUP BY c.id_caja, c.num_caja, u.login_usu,
                     a.id_agencia, a.descripcion, m.abreviatura, lr.valor
            ORDER BY a.descripcion, u.login_usu, m.abreviatura
SQL;

        return RawConnection::select($sql, array(
            ':fecha'      => $fecha,
            ':id_agencia' => $idAgencia
        ));
    }

    private function queryFallasCaja(string $fecha, ?int $idAgencia = null)
    {
        $sql = <<<'SQL'
            SELECT fc.id_caja, fc.fecha, fc.diferencia, fc.glosa, fc.estado
            FROM falla_caja fc
            INNER JOIN caja c ON c.id_caja = fc.id_caja
            WHERE fc.fecha = :fecha
              AND (:id_agencia IS NULL OR c.id_agencia = :id_agencia)
SQL;

        return RawConnection::select($sql, array(
            ':fecha'      => $fecha,
            ':id_agencia' => $idAgencia
        ));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Movement summary (consolidated per currency)
    // ═══════════════════════════════════════════════════════════════════

    public function movementSummary(string $fecha)
    {
        $rows = $this->queryReconciliation($fecha);
        $fallas = $this->queryFallasCaja($fecha);

        $fallasByCaja = array();
        foreach ($fallas as $f) {
            $fallasByCaja[$f['id_caja']] = $f['diferencia'];
        }

        $currencyGroups = array();
        foreach ($rows as $row) {
            $moneda = $row['moneda'];
            if (!isset($currencyGroups[$moneda])) {
                $currencyGroups[$moneda] = array();
            }
            $currencyGroups[$moneda][] = $row;
        }

        $currencies = array();
        foreach ($currencyGroups as $moneda => $items) {
            $aperturaCaja = 0;
            $cambioMoneda = 0;
            $envioGiro = 0;
            $pagoGiro = 0;
            $envioRemesa = 0;
            $pagoRemesa = 0;
            $egresos = 0;
            $transferencias = 0;
            $fallasCaja = 0;
            $cierreCaja = 0;

            foreach ($items as $row) {
                $aperturaCaja += (float)$row['apertura_caja'];
                $cambioMoneda += (float)$row['cambio_moneda_ing'] - (float)$row['cambio_moneda_egr'];
                $envioGiro += (float)$row['envio_giro'];
                $pagoGiro -= (float)$row['pago_giro'] + (float)$row['devolucion_giro'];
                $envioRemesa += (float)$row['envio_remesa'];
                $pagoRemesa -= (float)$row['pago_remesa'] + (float)$row['devolucion_remesa'];
                $egresos -= (float)$row['deposito_bancario'] + (float)$row['egreso_controlado'];
                $egresos += (float)$row['ingreso_controlado'];
                $transferencias += (float)$row['entrada_transferencia_boveda'] - (float)$row['salida_transferencia_boveda'];
                $transferencias += (float)$row['traspaso_de_caja'] - (float)$row['traspaso_a_caja'];
                $transferencias += (float)$row['cobro_cheque'];

                if (isset($fallasByCaja[$row['id_caja']])) {
                    $fallasCaja += $fallasByCaja[$row['id_caja']];
                }

                $cierreCaja += (float)$row['cierre_caja'];
            }

            $currencies[] = array(
                'moneda'         => $moneda,
                'aperturaCaja'   => (float)$aperturaCaja,
                'cambioMoneda'   => (float)$cambioMoneda,
                'envioGiro'      => (float)$envioGiro,
                'pagoGiro'       => (float)$pagoGiro,
                'envioRemesa'    => (float)$envioRemesa,
                'pagoRemesa'     => (float)$pagoRemesa,
                'egresos'        => (float)$egresos,
                'transferencias' => (float)$transferencias,
                'fallasCaja'     => (float)$fallasCaja,
                'cierreCaja'     => (float)$cierreCaja
            );
        }

        usort($currencies, function ($a, $b) {
            return strcmp($a['moneda'], $b['moneda']);
        });

        return array(
            'fecha'      => $fecha,
            'currencies' => $currencies
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Agency closings (boveda balances matrix)
    // ═══════════════════════════════════════════════════════════════════

    public function agencyClosings(string $fecha)
    {
        $rows = $this->queryAgencyClosings($fecha);

        $agencyMap = array();
        foreach ($rows as $row) {
            $key = $row['id_agencia'] . '|' . $row['agencia'];
            if (!isset($agencyMap[$key])) {
                $agencyMap[$key] = array(
                    'idAgencia'  => $row['id_agencia'],
                    'agencia'    => $row['agencia'],
                    'currencies' => array()
                );
            }
            $agencyMap[$key]['currencies'][$row['moneda']] = (float)$row['cierre_caja'];
        }

        $agencies = array();
        foreach ($agencyMap as $agency) {
            $agencies[] = array(
                'idAgencia'        => $agency['idAgencia'],
                'agencia'          => $agency['agencia'],
                'currencyClosings' => $agency['currencies']
            );
        }

        usort($agencies, function ($a, $b) {
            return strcmp($a['agencia'], $b['agencia']);
        });

        $totals = array();
        foreach ($rows as $row) {
            $moneda = $row['moneda'];
            if (!isset($totals[$moneda])) {
                $totals[$moneda] = 0;
            }
            $totals[$moneda] += (float)$row['cierre_caja'];
        }

        return array(
            'fecha'    => $fecha,
            'agencies' => $agencies,
            'totals'   => $totals
        );
    }

    private function queryAgencyClosings(string $fecha)
    {
        $sql = <<<'SQL'
            SELECT a.id_agencia, a.descripcion AS agencia, m.abreviatura AS moneda,
                   SUM(CASE WHEN sb.conciliacion <> 'C' THEN sb.saldo ELSE 0 END) AS cierre_caja
            FROM saldo_boveda sb
            INNER JOIN boveda b ON b.id_boveda = sb.id_boveda
            INNER JOIN moneda m ON m.id_moneda = b.id_moneda
            INNER JOIN agencia a ON a.id_agencia = b.id_agencia
            WHERE DATE(sb.fec_crea_saldo) = :fecha
            GROUP BY a.id_agencia, a.descripcion, m.abreviatura
            HAVING SUM(CASE WHEN sb.conciliacion = 'C' THEN sb.saldo ELSE 0 END) != 0
SQL;

        return RawConnection::select($sql, array(':fecha' => $fecha));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Daily summary (caja + boveda + contabilidad per currency)
    // ═══════════════════════════════════════════════════════════════════

    public function dailySummary(string $fecha)
    {
        $cajaTotals = $this->queryCurrencyTotals($fecha);
        $bovedaTotals = $this->queryBovedaTotals($fecha);
        $contaBalances = $this->queryContabilidadBalances($fecha);

        $cajaMap = array();
        foreach ($cajaTotals as $row) {
            $cajaMap[$row['moneda']] = $row;
        }

        $bovedaMap = array();
        foreach ($bovedaTotals as $row) {
            $bovedaMap[$row['moneda']] = $row;
        }

        $allMonedas = array_unique(array_merge(array_keys($cajaMap), array_keys($bovedaMap)));
        sort($allMonedas);

        $currencies = array();
        foreach ($allMonedas as $moneda) {
            $caja   = isset($cajaMap[$moneda]) ? $cajaMap[$moneda] : null;
            $boveda = isset($bovedaMap[$moneda]) ? $bovedaMap[$moneda] : null;

            $totalCajas  = $caja ? (float)$caja['total_cajas'] : 0;
            $totalBoveda = $boveda ? (float)$boveda['total_boveda'] - abs($totalCajas) : 0;
            $tcOficial   = $caja ? (float)$caja['tipo_cambio'] : ($boveda ? (float)$boveda['tipo_cambio'] : 1);
            $totalCajasBoveda = $totalCajas + $totalBoveda;
            $monedasEnBob = $totalCajasBoveda * $tcOficial;

            $contabilidad = isset($contaBalances[$moneda]) ? $contaBalances[$moneda] : null;
            $diferencia   = ($contabilidad !== null) ? $monedasEnBob - $contabilidad : null;

            $currencies[] = array(
                'moneda'            => $moneda,
                'totalCajas'        => $totalCajas,
                'totalBoveda'       => $totalBoveda,
                'totalCajasBoveda'  => $totalCajasBoveda,
                'tipoCambioOficial' => $tcOficial,
                'monedasEnBob'      => $monedasEnBob,
                'contabilidad'      => $contabilidad,
                'diferencia'        => $diferencia
            );
        }

        $grandTotalBob = array_reduce($currencies, function ($carry, $curr) {
            return $carry + (float)$curr['monedasEnBob'];
        }, 0);

        return array(
            'fecha'         => $fecha,
            'currencies'    => $currencies,
            'grandTotalBob' => (float)$grandTotalBob
        );
    }

    private function queryContabilidadBalances(string $fecha)
    {
        $ejercicio = substr($fecha, 0, 4);

        $monedas = RawConnection::select(
            "SELECT id_moneda, abreviatura, cuenta_contable
             FROM moneda
             WHERE estado = 'A' AND cuenta_contable LIKE '11101%'
             GROUP BY cuenta_contable
             ORDER BY cuenta_contable"
        );

        $balances = array();
        foreach ($monedas as $m) {
            $saldo = RawConnection::scalar(
                "SELECT COALESCE(SUM(p.debe - p.haber), 0) AS s
                 FROM co_partidas p
                 JOIN co_asientos a ON a.idasiento = p.idasiento
                 WHERE p.codsubcuenta = :cod
                   AND a.codejercicio = :ej
                   AND a.fecha <= :fin",
                array(':cod' => $m['cuenta_contable'], ':ej' => $ejercicio, ':fin' => $fecha),
                'conta'
            );
            $balances[$m['abreviatura']] = (float)$saldo;
        }

        return $balances;
    }

    private function queryCurrencyTotals(string $fecha)
    {
        $sql = <<<'SQL'
            WITH LatestRate AS (
                SELECT co1.id_moneda, co1.valor
                FROM cotizacion_oficial co1
                INNER JOIN (
                    SELECT id_moneda, MAX(fec_crea_cotizacion_oficial) AS max_fecha
                    FROM cotizacion_oficial
                    WHERE id_operacion = 1
                        AND DATE(fec_crea_cotizacion_oficial) <= :fecha
                    GROUP BY id_moneda
                ) co2 ON co1.id_moneda = co2.id_moneda
                     AND co1.fec_crea_cotizacion_oficial = co2.max_fecha
                WHERE co1.id_operacion = 1
            )
            SELECT m.abreviatura AS moneda,
                   SUM(CASE WHEN sc.conciliacion = 'C' THEN sc.saldo ELSE 0 END) AS total_cajas,
                   COALESCE(lr.valor, 1) AS tipo_cambio
            FROM saldo_caja sc
            INNER JOIN caja c ON c.id_caja = sc.id_caja
            INNER JOIN moneda m ON m.id_moneda = c.id_moneda
            LEFT JOIN LatestRate lr ON lr.id_moneda = c.id_moneda
            WHERE DATE(sc.fec_crea_saldo) = :fecha
            GROUP BY m.abreviatura, lr.valor
SQL;

        return RawConnection::select($sql, array(':fecha' => $fecha));
    }

    private function queryBovedaTotals(string $fecha)
    {
        $sql = <<<'SQL'
            WITH LatestRate AS (
                SELECT co1.id_moneda, co1.valor
                FROM cotizacion_oficial co1
                INNER JOIN (
                    SELECT id_moneda, MAX(fec_crea_cotizacion_oficial) AS max_fecha
                    FROM cotizacion_oficial
                    WHERE id_operacion = 1
                    GROUP BY id_moneda
                ) co2 ON co1.id_moneda = co2.id_moneda
                     AND co1.fec_crea_cotizacion_oficial = co2.max_fecha
                WHERE co1.id_operacion = 1
            )
            SELECT m.abreviatura AS moneda,
                   SUM(CASE WHEN sb.conciliacion = 'C' THEN sb.saldo ELSE 0 END) AS total_boveda,
                   COALESCE(lr.valor, 1) AS tipo_cambio
            FROM saldo_boveda sb
            INNER JOIN boveda b ON b.id_boveda = sb.id_boveda
            INNER JOIN moneda m ON m.id_moneda = b.id_moneda
            LEFT JOIN LatestRate lr ON lr.id_moneda = m.id_moneda
            WHERE DATE(sb.fec_crea_saldo) = :fecha
            GROUP BY m.abreviatura, lr.valor
SQL;

        return RawConnection::select($sql, array(':fecha' => $fecha));
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Agencies list
    // ═══════════════════════════════════════════════════════════════════

    public function agencies()
    {
        return RawConnection::select(
            'SELECT id_agencia, descripcion FROM agencia WHERE id_agencia > 1 ORDER BY descripcion'
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Accounting reports (FacturaScripts / conta DB)
    // ═══════════════════════════════════════════════════════════════════

    public function ejercicios()
    {
        return RawConnection::select(
            "SELECT codejercicio, nombre, fechainicio, fechafin, estado
             FROM ejercicios ORDER BY codejercicio DESC",
            array(), 'conta'
        );
    }

    public function subcuentas(string $ejercicio)
    {
        return RawConnection::select(
            "SELECT codsubcuenta, descripcion
             FROM co_subcuentas
             WHERE codejercicio = :ej
             ORDER BY codsubcuenta",
            array(':ej' => $ejercicio), 'conta'
        );
    }

    public function balance(string $ejercicio, string $desde, string $hasta)
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

        $rows = RawConnection::select($sql, array(
            ':ej'    => $ejercicio,
            ':ej2'   => $ejercicio,
            ':desde' => $desde,
            ':hasta' => $hasta,
        ), 'conta');

        $totalDebe  = 0;
        $totalHaber = 0;
        $cuentas    = array();

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
            'fechaDesde' => $desde,
            'fechaHasta' => $hasta,
            'cuentas'    => array_values($cuentas),
            'totalDebe'  => $totalDebe,
            'totalHaber' => $totalHaber,
            'totalSaldo' => $totalDebe - $totalHaber,
            'cuadrado'   => abs($totalDebe - $totalHaber) < 0.01,
        );
    }

    public function libroMayor(string $codsubcuenta, string $ejercicio, string $desde, string $hasta, int $page = 1, int $pageSize = 500)
    {
        $info = RawConnection::select(
            "SELECT codsubcuenta, descripcion
             FROM co_subcuentas
             WHERE codsubcuenta = :cod AND codejercicio = :ej",
            array(':cod' => $codsubcuenta, ':ej' => $ejercicio), 'conta'
        );

        $empty = array(
            'codsubcuenta' => $codsubcuenta,
            'descripcion'  => empty($info) ? '' : $info[0]['descripcion'],
            'ejercicio'    => $ejercicio,
            'fechaDesde'   => $desde,
            'fechaHasta'   => $hasta,
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

        $baseParams = array(':cod' => $codsubcuenta, ':ej' => $ejercicio, ':desde' => $desde, ':hasta' => $hasta);
        $baseWhere  = "p.codsubcuenta = :cod AND a.codejercicio = :ej AND a.fecha >= :desde AND a.fecha <= :hasta";

        $saldoInicial = (float)RawConnection::scalar(
            "SELECT COALESCE(SUM(p.debe - p.haber), 0)
             FROM co_partidas p
             JOIN co_asientos a ON a.idasiento = p.idasiento
             WHERE p.codsubcuenta = :cod
               AND a.codejercicio = :ej
               AND a.fecha < :desde",
            array(':cod' => $codsubcuenta, ':ej' => $ejercicio, ':desde' => $desde),
            'conta'
        );

        $totalRows = (int)RawConnection::scalar(
            "SELECT COUNT(*) FROM co_partidas p JOIN co_asientos a ON a.idasiento = p.idasiento WHERE $baseWhere",
            $baseParams, 'conta'
        );

        $totals = RawConnection::select(
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
            $saldoPrevPages += (float)RawConnection::scalar(
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

        $movimientos = RawConnection::select(
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
            'fechaDesde'   => $desde,
            'fechaHasta'   => $hasta,
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

    public function asientoDetalle(int $idAsiento)
    {
        $asiento = RawConnection::select(
            "SELECT idasiento, numero, fecha, concepto, tipodocumento, importe
             FROM co_asientos WHERE idasiento = :id",
            array(':id' => $idAsiento), 'conta'
        );

        if (empty($asiento)) {
            return null;
        }

        $partidas = RawConnection::select(
            "SELECT p.codsubcuenta, s.descripcion, p.concepto, p.documento,
                    p.debe, p.haber
             FROM co_partidas p
             LEFT JOIN co_subcuentas s ON s.codsubcuenta = p.codsubcuenta
                 AND s.codejercicio = (SELECT codejercicio FROM co_asientos WHERE idasiento = :id2)
             WHERE p.idasiento = :id
             ORDER BY p.codsubcuenta",
            array(':id' => $idAsiento, ':id2' => $idAsiento), 'conta'
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

    // ═══════════════════════════════════════════════════════════════════
    //  Comprobantes Contables
    // ═══════════════════════════════════════════════════════════════════

    public function comprobantes(string $desde, string $hasta, int $page = 1, int $pageSize = 500, $tipo = null, $codigo = null, $glosa = null)
    {
        $where  = "ca.fecha BETWEEN :desde AND :hasta";
        $params = array(':desde' => $desde, ':hasta' => $hasta);

        if ($tipo !== null && $tipo !== '') {
            $where .= " AND ca.tipodocumento = :tipo";
            $params[':tipo'] = $tipo;
        }
        if ($codigo !== null && $codigo !== '') {
            $where .= " AND CONCAT(ca.tipodocumento, '-', ca.numero) LIKE :codigo";
            $params[':codigo'] = '%' . $codigo . '%';
        }
        if ($glosa !== null && $glosa !== '') {
            $where .= " AND ca.concepto LIKE :glosa";
            $params[':glosa'] = '%' . $glosa . '%';
        }

        $totalRows = (int) RawConnection::scalar(
            "SELECT COUNT(*) FROM co_asientos ca WHERE $where",
            $params, 'conta'
        );

        $totals = RawConnection::select(
            "SELECT COALESCE(SUM(ca.importe), 0) AS total_importe
             FROM co_asientos ca WHERE $where",
            $params, 'conta'
        );
        $totalImporte = (float) $totals[0]['total_importe'];

        $tiposResult = RawConnection::select(
            "SELECT DISTINCT ca.tipodocumento FROM co_asientos ca WHERE ca.fecha BETWEEN :desde AND :hasta ORDER BY ca.tipodocumento",
            array(':desde' => $desde, ':hasta' => $hasta), 'conta'
        );
        $tipos = array();
        foreach ($tiposResult as $t) {
            $tipos[] = $t['tipodocumento'];
        }

        $totalPages = max(1, (int) ceil($totalRows / $pageSize));
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $pageSize;

        $rows = RawConnection::select(
            "SELECT CONCAT(ca.tipodocumento, '-', ca.numero) AS comprobante,
                    ca.idasiento, ca.fecha, ca.concepto AS glosa,
                    ca.importe AS total_importe, ca.tipodocumento, ca.numero
             FROM co_asientos ca
             WHERE $where
             ORDER BY ca.fecha, ca.numero
             LIMIT $pageSize OFFSET $offset",
            $params, 'conta'
        );

        $mapped = array();
        foreach ($rows as $r) {
            $mapped[] = array(
                'comprobante'    => $r['comprobante'],
                'idasiento'      => (int) $r['idasiento'],
                'fecha'          => $r['fecha'],
                'glosa'          => $r['glosa'],
                'total_importe'  => (float) $r['total_importe'],
                'tipodocumento'  => $r['tipodocumento'],
                'numero'         => (int) $r['numero'],
            );
        }

        return array(
            'fechaDesde'    => $desde,
            'fechaHasta'    => $hasta,
            'comprobantes'  => $mapped,
            'totalImporte'  => $totalImporte,
            'totalRows'     => $totalRows,
            'tipos'         => $tipos,
            'page'          => $page,
            'pageSize'      => $pageSize,
            'totalPages'    => $totalPages,
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    //  Export (delegates to AccountingExporter)
    // ═══════════════════════════════════════════════════════════════════

    public function export(string $report, string $format, string $fecha, ?int $agencia = null): array
    {
        switch ($report) {
            case 'daily':
                $data = $this->dailySummary($fecha);
                $bin  = $format === 'xlsx'
                    ? AccountingExporter::dailySummaryXlsx($data)
                    : AccountingExporter::dailySummaryPdf($data);
                $name = 'Resumen_Diario_' . $fecha;
                break;
            case 'cashier':
                $data = $this->cashierDetails($fecha, $agencia);
                $bin  = $format === 'xlsx'
                    ? AccountingExporter::cashierDetailsXlsx($data)
                    : AccountingExporter::cashierDetailsPdf($data);
                $name = 'Arqueos_Cajero_' . $fecha;
                break;
            case 'movement':
                $data = $this->movementSummary($fecha);
                $bin  = $format === 'xlsx'
                    ? AccountingExporter::movementSummaryXlsx($data)
                    : AccountingExporter::movementSummaryPdf($data);
                $name = 'Resumen_Movimientos_' . $fecha;
                break;
            case 'agency':
                $data = $this->agencyClosings($fecha);
                $bin  = $format === 'xlsx'
                    ? AccountingExporter::agencyClosingsXlsx($data)
                    : AccountingExporter::agencyClosingsPdf($data);
                $name = 'Cierres_Agencia_' . $fecha;
                break;
            default:
                throw new \InvalidArgumentException("Reporte inválido: {$report}");
        }

        return ['binary' => $bin, 'filename' => "{$name}.{$format}"];
    }

    public function exportComprobantes(string $format, string $desde, string $hasta, $tipo = null, $codigo = null, $glosa = null): array
    {
        $data = $this->comprobantes($desde, $hasta, 1, 999999, $tipo, $codigo, $glosa);
        $bin  = $format === 'xlsx'
            ? AccountingExporter::comprobantesXlsx($data)
            : AccountingExporter::comprobantesPdf($data);
        $name = 'Comprobantes_Contables_' . $desde . '_' . $hasta;

        return ['binary' => $bin, 'filename' => "{$name}.{$format}"];
    }
}
