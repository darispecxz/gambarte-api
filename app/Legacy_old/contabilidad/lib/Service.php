<?php
/**
 * Service.php — Lógica de reportes de contabilidad/arqueos.
 *
 * Migrada de C# (CashierService.cs):
 * - GetReconciliationAsync() → getReconciliation()
 * - GetMovementSummaryAsync() → getMovementSummary()
 * - GetAgencyClosingsAsync() → getAgencyClosings()
 * - GetDailySummaryAsync() → getDailySummary()
 */

namespace Contabilidad;

require_once __DIR__ . '/Db.php';

class Service
{
    // ═══════════════════════════════════════════════════════════════════
    // Section D — Full per-cashier reconciliation
    // ═══════════════════════════════════════════════════════════════════

    public static function getReconciliation($fecha, $idAgencia = null)
    {
        $rows = self::queryReconciliation($fecha, $idAgencia);
        $fallas = self::queryFallasCaja($fecha, $idAgencia);

        // Index fallas by caja for O(1) lookup
        $fallasMap = array();
        foreach ($fallas as $f) {
            $fallasMap[$f['id_caja']] = $f['diferencia'];
        }

        // Group by cashier (usuario): one user may have multiple cajas (one per currency)
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

        // Map to CashierBreakdown objects, skip cashiers with no active currencies
        $cashiers = array();
        foreach ($cashierGroups as $group) {
            $mapped = self::mapCashier($group['loginUsuario'], $group['idAgencia'], $group['agencia'], $group['rows'], $fallasMap);
            if (!empty($mapped['currencies'])) {
                $cashiers[] = $mapped;
            }
        }

        // Sort by agencia, then cajero
        usort($cashiers, function($a, $b) {
            $cmp = strcmp($a['agencia'], $b['agencia']);
            if ($cmp !== 0) return $cmp;
            return strcmp($a['cajero'], $b['cajero']);
        });

        // Count total discrepancies
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

    private static function mapCashier($cajero, $idAgencia, $agencia, $rows, $fallasMap)
    {
        $currencies = array();
        $fallasRegistradas = array();

        foreach ($rows as $row) {
            list($totalIngreso, $totalEgreso) = self::computeTotals($row);
            $theoreticalBalance = $totalIngreso - abs($totalEgreso);
            $cierreCaja = (float)$row['cierre_caja'];
            $diferencia = $cierreCaja - $theoreticalBalance;

            // Skip currencies with no activity at all
            if ($totalIngreso == 0 && $totalEgreso == 0 && $cierreCaja == 0) {
                continue;
            }

            // Check for registered falla
            if (isset($fallasMap[$row['id_caja']])) {
                $fallasRegistradas[$row['id_caja']] = $fallasMap[$row['id_caja']];
            }

            // Build TransactionEntry for each category
            $currencies[] = array(
                'idCaja'    => $row['id_caja'],
                'numCaja'   => $row['num_caja'],
                'moneda'    => $row['moneda'],
                'tipoCambioOficial' => (float)$row['tipo_cambio'],

                // Ingresos
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

                // Egresos
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

                // Computed
                'totalIngreso' => (float)$totalIngreso,
                'totalEgreso'  => (float)$totalEgreso,
                'totalSaldo'   => (float)$theoreticalBalance,
                'cierreCaja'   => $cierreCaja,
                'diferencia'   => (float)$diferencia
            );
        }

        // Sort currencies by moneda abbreviation
        usort($currencies, function($a, $b) {
            return strcmp($a['moneda'], $b['moneda']);
        });

        return array(
            'cajero'         => $cajero,
            'idAgencia'      => $idAgencia,
            'agencia'        => $agencia,
            'currencies'     => $currencies,
            'fallasRegistradas' => $fallasRegistradas
        );
    }

    /**
     * Core business rule: TheoreticalBalance = TotalIngreso - TotalEgreso.
     * Returns [totalIngreso, totalEgreso]
     */
    private static function computeTotals($row)
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

    private static function queryReconciliation($fecha, $idAgencia = null)
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

        return Db::q($sql, array(
            ':fecha' => $fecha,
            ':id_agencia' => $idAgencia
        ));
    }

    private static function queryFallasCaja($fecha, $idAgencia = null)
    {
        $sql = <<<'SQL'
            SELECT fc.id_caja, fc.fecha, fc.diferencia, fc.glosa, fc.estado
            FROM falla_caja fc
            INNER JOIN caja c ON c.id_caja = fc.id_caja
            WHERE fc.fecha = :fecha
              AND (:id_agencia IS NULL OR c.id_agencia = :id_agencia)
SQL;

        return Db::q($sql, array(
            ':fecha' => $fecha,
            ':id_agencia' => $idAgencia
        ));
    }

    // ═══════════════════════════════════════════════════════════════════
    // Section E1 — Consolidated movement summary per currency
    // ═══════════════════════════════════════════════════════════════════

    public static function getMovementSummary($fecha)
    {
        $rows = self::queryReconciliation($fecha);
        $fallas = self::queryFallasCaja($fecha);

        $fallasByCaja = array();
        foreach ($fallas as $f) {
            $fallasByCaja[$f['id_caja']] = $f['diferencia'];
        }

        // Group by moneda
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
                'moneda' => $moneda,
                'aperturaCaja' => (float)$aperturaCaja,
                'cambioMoneda' => (float)$cambioMoneda,
                'envioGiro' => (float)$envioGiro,
                'pagoGiro' => (float)$pagoGiro,
                'envioRemesa' => (float)$envioRemesa,
                'pagoRemesa' => (float)$pagoRemesa,
                'egresos' => (float)$egresos,
                'transferencias' => (float)$transferencias,
                'fallasCaja' => (float)$fallasCaja,
                'cierreCaja' => (float)$cierreCaja
            );
        }

        // Sort by moneda
        usort($currencies, function($a, $b) {
            return strcmp($a['moneda'], $b['moneda']);
        });

        return array(
            'fecha' => $fecha,
            'currencies' => $currencies
        );
    }

    // ═══════════════════════════════════════════════════════════════════
    // Section E2 — Agency closing balances matrix
    // ═══════════════════════════════════════════════════════════════════

    public static function getAgencyClosings($fecha)
    {
        $rows = self::queryAgencyClosings($fecha);

        // Group by (idAgencia, agencia)
        $agencyMap = array();
        foreach ($rows as $row) {
            $key = $row['id_agencia'] . '|' . $row['agencia'];
            if (!isset($agencyMap[$key])) {
                $agencyMap[$key] = array(
                    'idAgencia' => $row['id_agencia'],
                    'agencia' => $row['agencia'],
                    'currencies' => array()
                );
            }
            $agencyMap[$key]['currencies'][$row['moneda']] = (float)$row['cierre_caja'];
        }

        $agencies = array();
        foreach ($agencyMap as $agency) {
            $agencies[] = array(
                'idAgencia' => $agency['idAgencia'],
                'agencia' => $agency['agencia'],
                'currencyClosings' => $agency['currencies']
            );
        }

        // Sort by agencia name
        usort($agencies, function($a, $b) {
            return strcmp($a['agencia'], $b['agencia']);
        });

        // Calculate totals per currency
        $totals = array();
        foreach ($rows as $row) {
            $moneda = $row['moneda'];
            if (!isset($totals[$moneda])) {
                $totals[$moneda] = 0;
            }
            $totals[$moneda] += (float)$row['cierre_caja'];
        }

        return array(
            'fecha' => $fecha,
            'agencies' => $agencies,
            'totals' => $totals
        );
    }

    private static function queryAgencyClosings($fecha)
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

        return Db::q($sql, array(':fecha' => $fecha));
    }

    // ═══════════════════════════════════════════════════════════════════
    // Section C — Daily summary (caja + boveda totals per currency)
    // ═══════════════════════════════════════════════════════════════════

    public static function getDailySummary($fecha)
    {
        $cajaTotals = self::queryCurrencyTotals($fecha);
        $bovedaTotals = self::queryBovedaTotals($fecha);
        $contaBalances = self::queryContabilidadBalances($fecha);

        // Index for easy lookup
        $cajaMap = array();
        foreach ($cajaTotals as $row) {
            $cajaMap[$row['moneda']] = $row;
        }

        $bovedaMap = array();
        foreach ($bovedaTotals as $row) {
            $bovedaMap[$row['moneda']] = $row;
        }

        // Merge: all currencies from both sources
        $allMonedas = array_unique(array_merge(array_keys($cajaMap), array_keys($bovedaMap)));
        sort($allMonedas);

        $currencies = array();
        foreach ($allMonedas as $moneda) {
            $caja = isset($cajaMap[$moneda]) ? $cajaMap[$moneda] : null;
            $boveda = isset($bovedaMap[$moneda]) ? $bovedaMap[$moneda] : null;

            $totalCajas = $caja ? (float)$caja['total_cajas'] : 0;
            $totalBoveda = $boveda ? (float)$boveda['total_boveda'] - abs($totalCajas) : 0;
            $tcOficial = $caja ? (float)$caja['tipo_cambio'] : ($boveda ? (float)$boveda['tipo_cambio'] : 1);
            $totalCajasBoveda = $totalCajas + $totalBoveda;
            $monedasEnBob = $totalCajasBoveda * $tcOficial;

            $contabilidad = isset($contaBalances[$moneda]) ? $contaBalances[$moneda] : null;
            $diferencia = ($contabilidad !== null) ? $monedasEnBob - $contabilidad : null;

            $currencies[] = array(
                'moneda' => $moneda,
                'totalCajas' => $totalCajas,
                'totalBoveda' => $totalBoveda,
                'totalCajasBoveda' => $totalCajasBoveda,
                'tipoCambioOficial' => $tcOficial,
                'monedasEnBob' => $monedasEnBob,
                'contabilidad' => $contabilidad,
                'diferencia' => $diferencia
            );
        }

        $grandTotalBob = array_reduce($currencies, function($carry, $curr) {
            return $carry + (float)$curr['monedasEnBob'];
        }, 0);

        return array(
            'fecha' => $fecha,
            'currencies' => $currencies,
            'grandTotalBob' => (float)$grandTotalBob
        );
    }

    private static function queryContabilidadBalances($fecha)
    {
        $ejercicio = substr($fecha, 0, 4);

        $monedas = Db::q(
            "SELECT id_moneda, abreviatura, cuenta_contable
             FROM moneda
             WHERE estado = 'A' AND cuenta_contable LIKE '11101%'
             GROUP BY cuenta_contable
             ORDER BY cuenta_contable"
        );

        $balances = array();
        foreach ($monedas as $m) {
            $saldo = Db::val(
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

    private static function queryCurrencyTotals($fecha)
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

        return Db::q($sql, array(':fecha' => $fecha));
    }

    // ═══════════════════════════════════════════════════════════════════
    // Agencies list
    // ═══════════════════════════════════════════════════════════════════

    public static function getAgencies()
    {
        $sql = 'SELECT id_agencia, descripcion FROM agencia WHERE id_agencia > 1 ORDER BY descripcion';
        return Db::q($sql);
    }

    private static function queryBovedaTotals($fecha)
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

        return Db::q($sql, array(':fecha' => $fecha));
    }
}
