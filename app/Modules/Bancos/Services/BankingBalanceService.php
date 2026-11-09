<?php

namespace App\Modules\Bancos\Services;

use App\Modules\Shared\Database\RawConnection as DB;

/**
 * Saldo de una cuenta bancaria.
 *
 * ── Criterio ─────────────────────────────────────────────────────────────────
 * `movimiento_cuenta_bancaria` es un libro de movimientos donde cada fila ya
 * viene con el signo aplicado (los egresos se insertan negativos) y las filas
 * de arqueo bancario ('A' apertura, 'C' cierre) son importes ABSOLUTOS que
 * resetean el arrastre. Por eso el saldo se compone como:
 *
 *      último cierre (o última apertura si no hay cierre)  +  Σ movimientos posteriores
 *
 * Es el mismo criterio que ya aplicaba `BankService::saldos()`, así que el
 * saldo que ve el cajero coincide con el de los reportes existentes.
 *
 * Se expone en una clase propia porque lo necesitan tanto la consulta de
 * cuentas como la validación de fondos al girar, y ambas deben usar
 * exactamente la misma cifra.
 */
class BankingBalanceService
{
    /** Saldo de una cuenta al cierre de una fecha (incluye el arqueo vigente). */
    public function saldoCuentaAlCorte(int $idAgencia, int $idEntidad, string $numCuenta, int $idMoneda, string $hasta): float
    {
        $params = [':ag' => $idAgencia, ':ent' => $idEntidad, ':num' => $numCuenta, ':mon' => $idMoneda, ':hasta_mov' => $hasta];
        $ultimo = DB::selectOne("
            SELECT fec_crea_movimiento_cuenta_bancaria AS fecha, monto
            FROM movimiento_cuenta_bancaria
            WHERE id_agencia = :ag AND id_entidad_financiera = :ent
              AND num_cuenta_bancaria = :num AND id_moneda = :mon
              AND conciliacion IN ('A', 'C')
              AND fec_crea_movimiento_cuenta_bancaria < DATE_ADD(:hasta_mov, INTERVAL 1 DAY)
            ORDER BY fec_crea_movimiento_cuenta_bancaria DESC LIMIT 1
        ", $params);

        $whereArrastre = '';
        if ($ultimo) {
            $params[':desde'] = $ultimo['fecha'];
            $whereArrastre = ' AND fec_crea_movimiento_cuenta_bancaria > :desde';
            $saldoBase = (float) $ultimo['monto'];
        } else {
            $saldoBase = 0.0;
        }

        $movimientos = (float) DB::scalar("
            SELECT COALESCE(SUM(monto), 0) FROM movimiento_cuenta_bancaria
            WHERE id_agencia = :ag AND id_entidad_financiera = :ent
              AND num_cuenta_bancaria = :num AND id_moneda = :mon
              AND conciliacion NOT IN ('A', 'C')
              AND fec_crea_movimiento_cuenta_bancaria < DATE_ADD(:hasta_mov, INTERVAL 1 DAY)
              {$whereArrastre}
        ", $params);

        return round($saldoBase + $movimientos, 4);
    }

    /**
     * Saldo disponible de una cuenta.
     *
     * @param int    $idAgencia   Agencia de la cuenta.
     * @param int    $idEntidad   Entidad financiera.
     * @param string $numCuenta   Número de cuenta.
     * @param int    $idMoneda    Moneda.
     */
    public function saldoCuenta(int $idAgencia, int $idEntidad, string $numCuenta, int $idMoneda): float
    {
        $params = [
            ':ag'  => $idAgencia,
            ':ent' => $idEntidad,
            ':num' => $numCuenta,
            ':mon' => $idMoneda,
        ];

        // Fecha del último arqueo (apertura o cierre) de la cuenta.
        $ultimo = DB::selectOne("
            SELECT fec_crea_movimiento_cuenta_bancaria AS fecha, monto, conciliacion
            FROM movimiento_cuenta_bancaria
            WHERE id_agencia = :ag AND id_entidad_financiera = :ent
              AND num_cuenta_bancaria = :num AND id_moneda = :mon
              AND conciliacion IN ('A', 'C')
            ORDER BY fec_crea_movimiento_cuenta_bancaria DESC
            LIMIT 1
        ", $params);

        if (!$ultimo) {
            // Sin arqueo: solo los movimientos.
            return round((float) DB::scalar("
                SELECT COALESCE(SUM(monto), 0) FROM movimiento_cuenta_bancaria
                WHERE id_agencia = :ag AND id_entidad_financiera = :ent
                  AND num_cuenta_bancaria = :num AND id_moneda = :mon
                  AND conciliacion NOT IN ('A', 'C')
            ", $params), 4);
        }

        $arrastre = (float) $ultimo['monto'];
        $desde = $ultimo['fecha'];

        $movs = (float) DB::scalar("
            SELECT COALESCE(SUM(monto), 0) FROM movimiento_cuenta_bancaria
            WHERE id_agencia = :ag AND id_entidad_financiera = :ent
              AND num_cuenta_bancaria = :num AND id_moneda = :mon
              AND conciliacion NOT IN ('A', 'C')
              AND fec_crea_movimiento_cuenta_bancaria > :desde
        ", $params + [':desde' => $desde]);

        return round($arrastre + $movs, 4);
    }
}
