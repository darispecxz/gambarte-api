<?php

namespace App\Modules\Cajero\Services;

use App\Modules\Shared\Database\RawConnection as DB;

class CajeroQueryService
{
    private CajeroBalanceService $balance;

    public function __construct(CajeroBalanceService $balance)
    {
        $this->balance = $balance;
    }

    public function misCajas(int $codUsuario, int $idAgencia): array
    {
        $sql = "
            SELECT
                c.id_caja, c.num_caja, c.id_moneda, c.id_agencia,
                c.maximo, c.minimo, c.estado,
                m.abreviatura AS moneda, m.descripcion AS moneda_desc,
                a.descripcion AS agencia, a.abreviatura AS agencia_abr
            FROM caja c
            JOIN moneda m ON m.id_moneda = c.id_moneda
            JOIN agencia a ON a.id_agencia = c.id_agencia
            WHERE c.cod_usuario = :cod_usuario
              AND c.id_agencia = :id_agencia
              AND c.estado = 'A'
              AND c.id_tipo_unidad = 2
            ORDER BY c.id_moneda
        ";

        $cajas = DB::select($sql, [':cod_usuario' => $codUsuario, ':id_agencia' => $idAgencia]);
        $hoy = date('Y-m-d');

        $bovedaAbierta = $this->isBovedaAbierta($idAgencia);

        foreach ($cajas as &$caja) {
            $caja['saldo_actual'] = $this->saldoActual((int) $caja['id_caja'], $hoy);
            $caja['sesion_hoy']   = $this->sesionHoy((int) $caja['id_caja'], $hoy);
            $caja['boveda_disponible'] = $bovedaAbierta
                ? $this->bovedaDisponible((int) $caja['id_agencia'], (int) $caja['id_moneda'], $hoy)
                : null;
            $caja['boveda_abierta'] = $bovedaAbierta;
        }

        return $cajas;
    }

    public function denominaciones(?int $idMoneda): array
    {
        $where = '';
        $params = [];
        if ($idMoneda) {
            $where = 'AND co.id_moneda = :id_moneda';
            $params[':id_moneda'] = $idMoneda;
        }

        $sql = "
            SELECT
                co.id_corte, co.valor, co.id_moneda,
                m.abreviatura AS moneda,
                atc.id_tipo_corte, tc.descripcion AS tipo_corte,
                atc.id_agrupacion, atc.cantidad AS multiplicador,
                ag.descripcion AS agrupacion
            FROM corte co
            JOIN moneda m ON m.id_moneda = co.id_moneda
            JOIN agrupacion_tipo_corte atc ON atc.id_tipo_corte = co.id_tipo_corte
            JOIN tipo_corte tc ON tc.id_tipo_corte = atc.id_tipo_corte
            JOIN agrupacion ag ON ag.id_agrupacion = atc.id_agrupacion
            WHERE 1=1 $where
            ORDER BY co.id_moneda, co.valor DESC, atc.id_agrupacion
        ";

        return DB::select($sql, $params);
    }

    public function disponibilidad(int $codUsuario, int $idCaja, string $fecha): array
    {
        $caja = DB::selectOne(
            "SELECT c.*, m.abreviatura AS moneda
             FROM caja c
             JOIN moneda m ON m.id_moneda = c.id_moneda
             WHERE c.id_caja = :id_caja AND c.cod_usuario = :cod_usuario",
            [':id_caja' => $idCaja, ':cod_usuario' => $codUsuario]
        );

        if (!$caja) {
            return ['error' => 'Caja no encontrada o no pertenece al usuario'];
        }

        $saldoActual   = $this->saldoActual($idCaja, $fecha);
        $sesion        = $this->sesionHoy($idCaja, $fecha);
        $movimientos   = $this->contarMovimientos($idCaja, $fecha);

        $boveda = DB::selectOne(
            "SELECT b.id_boveda, b.num_boveda, b.maximo, b.minimo
             FROM boveda b
             WHERE b.id_agencia = :ag AND b.id_moneda = :mon",
            [':ag' => $caja['id_agencia'], ':mon' => $caja['id_moneda']]
        );

        $saldoBoveda = null;
        if ($boveda) {
            $saldoBoveda = $this->balance->saldoBoveda((int) $boveda['id_boveda'], $fecha);
        }

        return [
            'caja'           => $caja,
            'saldo_actual'   => $saldoActual,
            'sesion'         => $sesion,
            'movimientos'    => $movimientos,
            'boveda_saldo'   => $saldoBoveda,
            'boveda_minimo'  => $boveda ? (float) $boveda['minimo'] : null,
            'boveda_maximo'  => $boveda ? (float) $boveda['maximo'] : null,
        ];
    }

    /**
     * Saldo disponible de una caja para una fecha.
     *
     * OJO: no es `SUM(saldo)`. `saldo_caja` mezcla filas de control ('A' apertura,
     * 'C' cierre) cuyo `saldo` es absoluto, con filas de movimiento cuyo `saldo`
     * es un delta. Ver `CajeroBalanceService`.
     */
    public function saldoActual(int $idCaja, string $fecha): float
    {
        return $this->balance->saldoCaja($idCaja, $fecha);
    }

    private function sesionHoy(int $idCaja, string $fecha): ?array
    {
        $apertura = DB::selectOne(
            "SELECT saldo, fec_crea_saldo
             FROM saldo_caja
             WHERE id_caja = :id AND conciliacion = 'A' AND DATE(fec_crea_saldo) = :fecha
             LIMIT 1",
            [':id' => $idCaja, ':fecha' => $fecha]
        );

        $cierre = DB::selectOne(
            "SELECT saldo, fec_crea_saldo
             FROM saldo_caja
             WHERE id_caja = :id AND conciliacion = 'C' AND DATE(fec_crea_saldo) = :fecha
             LIMIT 1",
            [':id' => $idCaja, ':fecha' => $fecha]
        );

        if (!$apertura) {
            return ['estado' => 'sin_abrir'];
        }

        if ($cierre) {
            return [
                'estado'         => 'cerrada',
                'saldo_apertura' => (float) $apertura['saldo'],
                'hora_apertura'  => $apertura['fec_crea_saldo'],
                'saldo_cierre'   => (float) $cierre['saldo'],
                'hora_cierre'    => $cierre['fec_crea_saldo'],
            ];
        }

        return [
            'estado'         => 'abierta',
            'saldo_apertura' => (float) $apertura['saldo'],
            'hora_apertura'  => $apertura['fec_crea_saldo'],
        ];
    }

    private function isBovedaAbierta(int $idAgencia): bool
    {
        $count = (int) DB::scalar(
            "SELECT COUNT(*) FROM saldo_boveda
             WHERE id_boveda IN (SELECT id_boveda FROM boveda WHERE id_agencia = :ag)
               AND DATE(fec_crea_saldo) = CURDATE() AND conciliacion = 'A'",
            [':ag' => $idAgencia]
        );
        return $count > 0;
    }

    /**
     * Saldo de la bóveda de una agencia/moneda a una fecha.
     *
     * Se calcula como apertura del día + movimientos del día (ver
     * `CajeroBalanceService`); no es `SUM(saldo)`.
     */
    private function bovedaDisponible(int $idAgencia, int $idMoneda, string $fecha): ?float
    {
        $boveda = DB::selectOne(
            "SELECT b.id_boveda, b.minimo FROM boveda b WHERE b.id_agencia = :ag AND b.id_moneda = :mon",
            [':ag' => $idAgencia, ':mon' => $idMoneda]
        );
        if (!$boveda) {
            return null;
        }

        $saldoBoveda = $this->balance->saldoBoveda((int) $boveda['id_boveda'], $fecha);
        return max(0, $saldoBoveda - (float) $boveda['minimo']);
    }

    private function contarMovimientos(int $idCaja, string $fecha): int
    {
        return (int) DB::scalar(
            "SELECT COUNT(*) FROM saldo_caja
             WHERE id_caja = :id AND DATE(fec_crea_saldo) = :fecha
               AND conciliacion NOT IN ('A', 'C')",
            [':id' => $idCaja, ':fecha' => $fecha]
        );
    }
}
