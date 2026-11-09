<?php

namespace CgrApi\Discrepancy;

use Contabilidad\Db;

class ReconciliationEngine
{
    public static function run()
    {
        $created = 0;
        $checks  = array(
            self::checkOrphanRemesas(),
            self::checkOrphanCambios(),
            self::checkAccountingAmountMismatch(),
            self::checkDuplicateMovements(),
            self::checkDebitCreditBalance(),
            self::checkRateDifference(),
            self::checkVoidedWithoutReversal(),
        );

        foreach ($checks as $batch) {
            foreach ($batch as $data) {
                $id = DiscrepancyService::create($data);
                if ($id !== null) $created++;
            }
        }

        return $created;
    }

    private static function checkOrphanRemesas()
    {
        $result = array();

        $rows = Db::q("
            SELECT p.codigo, p.monto, p.fec_crea_pago, a.descripcion as agencia
            FROM pago p
            LEFT JOIN agencia a ON a.id_agencia = p.id_agencia
            WHERE p.id_tipo_pago = 1
              AND p.fec_crea_pago >= DATE_SUB(NOW(), INTERVAL 7 DAY)
              AND NOT EXISTS (
                  SELECT 1 FROM co_partidas cp
                  JOIN co_asientos ca ON ca.idasiento = cp.idasiento
                  WHERE ca.fecha = DATE(p.fec_crea_pago)
                    AND ca.concepto LIKE CONCAT('%#', p.codigo, '%')
              )
        ");

        foreach ($rows as $r) {
            $result[] = array(
                'type'           => 'ACCOUNTING_MISSING',
                'compared_field' => 'accounting_entry_count',
                'description'    => "Remesa #{$r['codigo']} ({$r['agencia']}) sin asiento contable después de 7 días.",
                'operation_id'   => (int) $r['codigo'],
                'operation_type' => 'remesa',
                'value_cgr_bo'   => (float) $r['monto'],
                'value_conta'    => 0.0,
                'unit'           => 'BOB',
                'detail_technical' => array('check' => 'orphan_remesas', 'fecha' => $r['fec_crea_pago']),
            );
        }

        return $result;
    }

    private static function checkOrphanCambios()
    {
        $result = array();

        $rows = Db::q("
            SELECT c.id_cambio, c.monto, c.mon_rec, c.mon_ent, c.tipo_cambio,
                   c.fec_crea_cambio, a.descripcion as agencia
            FROM cambio c
            LEFT JOIN agencia a ON a.id_agencia = c.id_agencia
            WHERE c.fec_crea_cambio >= DATE_SUB(NOW(), INTERVAL 7 DAY)
              AND c.estado != 'ANULADO'
        ");

        foreach ($rows as $r) {
            $hasEntry = Db::val("
                SELECT 1 FROM co_asientos
                WHERE fecha = DATE(:f) AND concepto LIKE :ref LIMIT 1
            ", array(
                ':f'   => $r['fec_crea_cambio'],
                ':ref' => '%cambio%' . $r['id_cambio'] . '%',
            ), 'conta');

            if (!$hasEntry) {
                $result[] = array(
                    'type'           => 'ORPHAN_OPERATION',
                    'compared_field' => 'accounting_entry_count',
                    'description'    => "Cambio #{$r['id_cambio']} ({$r['agencia']}, {$r['mon_rec']}→{$r['mon_ent']}) sin asiento contable.",
                    'operation_id'   => (int) $r['id_cambio'],
                    'operation_type' => 'cambio',
                    'value_cgr_bo'   => (float) $r['monto'],
                    'value_conta'    => 0.0,
                    'unit'           => $r['mon_rec'],
                    'detail_technical' => array(
                        'check'       => 'orphan_cambios',
                        'tipo_cambio' => (float) $r['tipo_cambio'],
                        'mon_rec'     => $r['mon_rec'],
                        'mon_ent'     => $r['mon_ent'],
                    ),
                );
            }
        }

        return $result;
    }

    private static function checkAccountingAmountMismatch()
    {
        $result = array();

        $rows = Db::q("
            SELECT m.id_agencia, m.monto, m.conciliacion, m.glosa,
                   m.fec_crea_movimiento_cuenta_bancaria as fecha,
                   m.num_cuenta_bancaria, a.descripcion as agencia
            FROM movimiento_cuenta_bancaria m
            LEFT JOIN agencia a ON a.id_agencia = m.id_agencia
            WHERE m.fec_crea_movimiento_cuenta_bancaria >= DATE_SUB(NOW(), INTERVAL 7 DAY)
              AND m.conciliacion NOT IN ('A','C')
        ");

        foreach ($rows as $r) {
            if (!$r['glosa'] || !preg_match('/#(\d+)/', $r['glosa'], $gm)) continue;
            $ref = $gm[1];

            $contaRow = Db::q("
                SELECT p.debe, p.haber
                FROM co_partidas p
                JOIN co_asientos ca ON ca.idasiento = p.idasiento
                JOIN cuenta_bancaria cb ON cb.codsubcuenta = p.codsubcuenta
                    AND cb.num_cuenta_bancaria = :cuenta
                WHERE ca.fecha = DATE(:f)
                  AND ca.concepto LIKE :ref
                LIMIT 1
            ", array(
                ':cuenta' => $r['num_cuenta_bancaria'],
                ':f'      => $r['fecha'],
                ':ref'    => '%#' . $ref . '%',
            ), 'conta');

            if (empty($contaRow)) continue;

            $contaMonto = max((float)$contaRow[0]['debe'], (float)$contaRow[0]['haber']);
            $obelMonto  = abs((float) $r['monto']);

            if (abs($contaMonto - $obelMonto) > 0.01) {
                $result[] = array(
                    'type'           => 'ACCOUNTING_AMOUNT',
                    'compared_field' => 'amount',
                    'description'    => "Movimiento {$r['conciliacion']} #{$ref} ({$r['agencia']}): OBEL={$obelMonto}, CONTA={$contaMonto}.",
                    'operation_id'   => (int) $ref,
                    'operation_type' => 'movimiento_' . strtolower($r['conciliacion']),
                    'value_cgr_bo'   => $obelMonto,
                    'value_conta'    => $contaMonto,
                    'unit'           => 'BOB',
                    'detail_technical' => array(
                        'check'  => 'accounting_amount_mismatch',
                        'cuenta' => $r['num_cuenta_bancaria'],
                        'glosa'  => $r['glosa'],
                    ),
                );
            }
        }

        return $result;
    }

    private static function checkDuplicateMovements()
    {
        $result = array();

        $rows = Db::q("
            SELECT MIN(fec_crea_movimiento_cuenta_bancaria) as primera,
                   COUNT(*) as total, monto, conciliacion,
                   id_agencia, num_cuenta_bancaria, id_moneda, glosa,
                   DATE(fec_crea_movimiento_cuenta_bancaria) as fecha
            FROM movimiento_cuenta_bancaria
            WHERE fec_crea_movimiento_cuenta_bancaria >= DATE_SUB(NOW(), INTERVAL 3 DAY)
              AND conciliacion NOT IN ('A','C')
            GROUP BY id_agencia, id_entidad_financiera, num_cuenta_bancaria, id_moneda,
                     monto, conciliacion, glosa, DATE(fec_crea_movimiento_cuenta_bancaria)
            HAVING COUNT(*) > 1
        ");

        foreach ($rows as $r) {
            $result[] = array(
                'type'           => 'DUPLICATE_SAME_SYSTEM',
                'compared_field' => 'duplicate_count',
                'description'    => "{$r['total']} movimientos {$r['conciliacion']} de {$r['monto']} en cuenta {$r['num_cuenta_bancaria']} el {$r['fecha']}.",
                'operation_id'   => null,
                'operation_type' => 'movimiento_' . strtolower($r['conciliacion']),
                'value_cgr_bo'   => (float) $r['total'],
                'unit'           => 'count',
                'detail_technical' => array(
                    'check'  => 'duplicate_movements',
                    'cuenta' => $r['num_cuenta_bancaria'],
                    'count'  => (int) $r['total'],
                ),
            );
        }

        return $result;
    }

    private static function checkDebitCreditBalance()
    {
        $result = array();

        $rows = Db::q("
            SELECT a.idasiento, a.concepto, a.fecha,
                   SUM(p.debe) as total_debe, SUM(p.haber) as total_haber
            FROM co_partidas p
            JOIN co_asientos a ON a.idasiento = p.idasiento
            WHERE a.fecha >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            GROUP BY a.idasiento
            HAVING ABS(SUM(p.debe) - SUM(p.haber)) > 0.01
        ", array(), 'conta');

        foreach ($rows as $r) {
            $result[] = array(
                'type'           => 'DEBIT_CREDIT_MISMATCH',
                'compared_field' => 'debit_credit_balance',
                'description'    => "Asiento #{$r['idasiento']} desbalanceado: debe={$r['total_debe']}, haber={$r['total_haber']}.",
                'operation_id'   => (int) $r['idasiento'],
                'operation_type' => 'asiento',
                'value_cgr_bo'   => (float) $r['total_debe'],
                'value_conta'    => (float) $r['total_haber'],
                'unit'           => 'BOB',
                'detail_technical' => array(
                    'check'    => 'debit_credit_balance',
                    'concepto' => $r['concepto'],
                ),
            );
        }

        return $result;
    }

    private static function checkRateDifference()
    {
        $result = array();

        $rows = Db::q("
            SELECT c.id_cambio, c.tipo_cambio, c.monto,
                   c.total_operacion, c.tipo_cambio_bob, c.total_operacion_bob,
                   c.mon_rec, c.mon_ent, c.id_operacion,
                   c.fec_crea_cambio, c.id_agencia,
                   a.descripcion as agencia
            FROM cambio c
            LEFT JOIN agencia a ON a.id_agencia = c.id_agencia
            WHERE c.fec_crea_cambio >= DATE_SUB(NOW(), INTERVAL 7 DAY)
              AND c.estado != 'ANULADO'
              AND c.tipo_cambio > 0
        ");

        foreach ($rows as $r) {
            if ((int)$r['mon_ent'] === 2) {
                $expected = round((float)$r['total_operacion'], 2);
            } else {
                $expected = round((float)$r['total_operacion'] * (float)$r['tipo_cambio_bob'], 2);
            }
            $actual = (float) $r['total_operacion_bob'];
            $diff     = abs($expected - $actual);

            if ($diff > 0.50) {
                $result[] = array(
                    'type'           => 'ROUNDING_INCONSISTENT',
                    'compared_field' => 'total_operacion_bob',
                    'description'    => "Cambio #{$r['id_cambio']} ({$r['agencia']}): total_op×tc_bob={$expected} pero total_operacion_bob={$actual}. Diferencia: {$diff}.",
                    'operation_id'   => (int) $r['id_cambio'],
                    'operation_type' => 'cambio',
                    'value_cgr_bo'   => $expected,
                    'value_conta'    => $actual,
                    'unit'           => 'BOB',
                    'detail_technical' => array(
                        'check'            => 'rounding_inconsistent',
                        'total_operacion'  => (float) $r['total_operacion'],
                        'tipo_cambio_bob'  => (float) $r['tipo_cambio_bob'],
                    ),
                );
            }

            $moneda = ((int)$r['mon_rec'] === 2) ? $r['mon_ent'] : $r['mon_rec'];

            $oficial = Db::val("
                SELECT valor FROM cotizacion
                WHERE id_agencia = :ag AND id_operacion = :op AND id_moneda = :mon
                  AND estado = 'A'
                  AND DATE(fec_crea_cotizacion) = DATE(:fecha)
                ORDER BY fec_crea_cotizacion DESC LIMIT 1
            ", array(
                ':ag'    => $r['id_agencia'],
                ':op'    => $r['id_operacion'],
                ':mon'   => $moneda,
                ':fecha' => $r['fec_crea_cambio'],
            ));

            if ($oficial !== null) {
                $oficialF = (float) $oficial;
                $tasaUsada = ((int)$r['mon_rec'] === 2) ? (float)$r['tipo_cambio_bob'] : (float)$r['tipo_cambio'];
                if ($oficialF > 0) {
                    $pctDiff = abs(($tasaUsada - $oficialF) / $oficialF) * 100;
                    if ($pctDiff > 1.0) {
                        $result[] = array(
                            'type'           => 'RATE_DIFFERENT',
                            'compared_field' => 'tipo_cambio',
                            'description'    => "Cambio #{$r['id_cambio']} ({$r['agencia']}): tasa usada {$tasaUsada} vs cotización {$oficialF} ({$r['mon_rec']}→{$r['mon_ent']}). Δ=" . round($pctDiff, 2) . "%.",
                            'operation_id'   => (int) $r['id_cambio'],
                            'operation_type' => 'cambio',
                            'value_cgr_bo'   => $tasaUsada,
                            'value_conta'    => $oficialF,
                            'unit'           => 'rate',
                            'detail_technical' => array(
                                'check'           => 'rate_different',
                                'tasa_usada'      => $tasaUsada,
                                'tasa_oficial'     => $oficialF,
                                'porcentaje_diff'  => round($pctDiff, 2),
                            ),
                        );
                    }
                }
            }
        }

        return $result;
    }

    private static function checkVoidedWithoutReversal()
    {
        $result = array();

        $rows = Db::q("
            SELECT c.id_cambio, c.monto, c.mon_rec, c.fec_crea_cambio,
                   a.descripcion as agencia
            FROM cambio c
            LEFT JOIN agencia a ON a.id_agencia = c.id_agencia
            WHERE c.estado = 'ANULADO'
              AND c.fec_crea_cambio >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        ");

        foreach ($rows as $r) {
            $hasEntry = Db::val("
                SELECT 1 FROM co_asientos
                WHERE concepto LIKE :ref AND fecha >= DATE(:f)
                LIMIT 1
            ", array(
                ':ref' => '%cambio%' . $r['id_cambio'] . '%',
                ':f'   => $r['fec_crea_cambio'],
            ), 'conta');

            if ($hasEntry) {
                $result[] = array(
                    'type'           => 'VOID_WITHOUT_REVERSAL',
                    'compared_field' => 'reversal_entry_exists',
                    'description'    => "Cambio #{$r['id_cambio']} ({$r['agencia']}) ANULADO pero asiento contable sigue activo.",
                    'operation_id'   => (int) $r['id_cambio'],
                    'operation_type' => 'cambio',
                    'value_cgr_bo'   => 0.0,
                    'value_conta'    => (float) $r['monto'],
                    'unit'           => $r['mon_rec'],
                    'detail_technical' => array('check' => 'voided_without_reversal'),
                );
            }
        }

        return $result;
    }
}
