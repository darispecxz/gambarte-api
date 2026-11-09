<?php

namespace App\Modules\Cajero\Services;

use App\Modules\Shared\Database\RawConnection as DB;

/**
 * Cálculo de saldos de caja y bóveda.
 *
 * ── Semántica de `saldo_caja` / `saldo_boveda` ───────────────────────────────
 *
 * Son tablas de doble uso. La columna `saldo` NO significa lo mismo en todas
 * las filas:
 *
 *   · FILAS DE ARQUEO (snapshot absoluto) → `saldo` es el importe CONTADO.
 *       'A' = apertura del día   (arrastra el cierre del día anterior)
 *       'C' = cierre del día
 *
 *   · FILAS DE MOVIMIENTO → `saldo` es un DELTA con signo (positivo entra,
 *     negativo sale). Incluye los códigos que interpreta
 *     `Contabilidad\AccountingService::queryReconciliation()`:
 *
 *       Ingresos : EC, AC, EG, IG, OG, AG, ER, IR, OR, AR, ET, EO, EE, RB, IA
 *       Egresos  : SC, VC, SG, DG, SR, LR, VR, ST, SO, SE, DR, DB, EA, PG
 *       Traspasos/ajustes : SO, EO, ST, ET, IC, SX, EX, D, S, ...
 *
 *     En `saldo_boveda` los códigos de traspaso llevan sufijo con la caja
 *     origen/destino (p. ej. 'SAC-774' = salida de caja 774, 'ECC-774' = su
 *     reverso). Son movimientos con signo, no arqueos.
 *
 * Por eso el saldo disponible se calcula SIEMPRE como:
 *
 *       ancla + Σ(deltas de movimiento del día)
 *
 * donde el ancla es la apertura de ese día o, si la unidad no se abrió, el
 * último cierre anterior. NUNCA como `SUM(saldo)` a secas: eso incluiría las
 * filas 'A' y 'C' dentro del total, duplicando el saldo (el cierre ya contiene
 * la apertura más los movimientos).
 *
 * Contabilidad calcula igual (`AccountingService::computeTotals()`:
 * `apertura + Σingresos − Σegresos`, comparado contra `C` como `cierre_caja`),
 * y con esta fórmula el 96,8% de los días cerrados de caja y el 94,9% de los de
 * bóveda cuadran exactamente contra el cierre declarado en la base; el resto
 * son correcciones puntuales de arqueo.
 *
 * Tampoco sirve acumular los movimientos de todo el histórico: los arqueos se
 * reescriben a diario y el acumulado histórico no tiene significado.
 */
class CajeroBalanceService
{
    /** Filas de arqueo: `saldo` es absoluto, no un delta. */
    private const ARQUEOS = ['A', 'C'];

    /**
     * Saldo disponible de una caja para una fecha.
     *
     * @param string $fecha Formato AAAA-MM-DD.
     */
    public function saldoCaja(int $idCaja, string $fecha): float
    {
        return $this->saldo('saldo_caja', 'id_caja', $idCaja, $fecha);
    }

    /**
     * Saldo disponible de una bóveda para una fecha.
     *
     * @param string $fecha Formato AAAA-MM-DD.
     */
    public function saldoBoveda(int $idBoveda, string $fecha): float
    {
        return $this->saldo('saldo_boveda', 'id_boveda', $idBoveda, $fecha);
    }

    /**
     * Ancla + suma de los movimientos del día.
     */
    private function saldo(string $tabla, string $columnaId, int $id, string $fecha): float
    {
        $ancla = $this->ancla($tabla, $columnaId, $id, $fecha);

        $movimientos = (float) DB::scalar(
            "SELECT COALESCE(SUM(saldo), 0) AS saldo
             FROM {$tabla}
             WHERE {$columnaId} = :id
               AND DATE(fec_crea_saldo) = :fecha
               AND conciliacion NOT IN (:a, :c)",
            [':a' => self::ARQUEOS[0], ':c' => self::ARQUEOS[1], ':id' => $id, ':fecha' => $fecha]
        );

        return round($ancla + $movimientos, 4);
    }

    /**
     * Importe de partida del día.
     *
     * Se prefiere la apertura del propio día. Si la unidad no se abrió ese día
     * (puede pasar en bóvedas, que reciben cargos sin sesión de apertura), se
     * usa el último cierre disponible anterior a la fecha.
     */
    private function ancla(string $tabla, string $columnaId, int $id, string $fecha): float
    {
        $apertura = DB::scalar(
            "SELECT saldo FROM {$tabla}
             WHERE {$columnaId} = :id
               AND conciliacion = :a
               AND DATE(fec_crea_saldo) = :fecha
             ORDER BY fec_crea_saldo DESC
             LIMIT 1",
            [':id' => $id, ':a' => self::ARQUEOS[0], ':fecha' => $fecha]
        );

        if ($apertura !== null) {
            return (float) $apertura;
        }

        $cierre = DB::scalar(
            "SELECT saldo FROM {$tabla}
             WHERE {$columnaId} = :id
               AND conciliacion = :c
               AND DATE(fec_crea_saldo) < :fecha
             ORDER BY fec_crea_saldo DESC
             LIMIT 1",
            [':id' => $id, ':c' => self::ARQUEOS[1], ':fecha' => $fecha]
        );

        return $cierre === null ? 0.0 : (float) $cierre;
    }
}
