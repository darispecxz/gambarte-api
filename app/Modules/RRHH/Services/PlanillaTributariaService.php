<?php

namespace App\Modules\RRHH\Services;

use App\Modules\Shared\Database\RawConnection;

class PlanillaTributariaService
{
    private ParametrosService $params;
    private PlanillaService $planilla;

    public function __construct(ParametrosService $params, PlanillaService $planilla)
    {
        $this->params = $params;
        $this->planilla = $planilla;
    }

    public function listar(int $gestion, int $mes): array
    {
        return RawConnection::select(
            "SELECT t.*,
                    CONCAT(e.apellido_paterno, ' ', IFNULL(e.apellido_materno,''), ' ', e.nombres) AS empleado,
                    ca.descripcion AS cargo
             FROM rrhh_planilla_tributaria t
             JOIN rrhh_empleado e ON e.id = t.id_empleado
             LEFT JOIN rrhh_contrato c ON c.id_empleado = e.id AND c.estado = 'VIGENTE'
             LEFT JOIN cargo ca ON ca.id_cargo = c.id_cargo
             WHERE t.gestion = :g AND t.mes = :m
             ORDER BY e.apellido_paterno, e.nombres",
            [':g' => $gestion, ':m' => $mes]
        );
    }

    public function detallePorEmpleado(int $idEmpleado, int $gestion, int $mes): ?array
    {
        return RawConnection::selectOne(
            "SELECT t.*,
                    CONCAT(e.apellido_paterno, ' ', IFNULL(e.apellido_materno,''), ' ', e.nombres) AS empleado,
                    e.ci
             FROM rrhh_planilla_tributaria t
             JOIN rrhh_empleado e ON e.id = t.id_empleado
             WHERE t.id_empleado = :id AND t.gestion = :g AND t.mes = :m",
            [':id' => $idEmpleado, ':g' => $gestion, ':m' => $mes]
        );
    }

    public function calcularTributaria(int $gestion, int $mes, ?int $agencia = null): array
    {
        $preview = $this->planilla->preview($gestion, $mes, $agencia);
        $smn = $this->params->valor($gestion, 'SMN');
        $alicuota = $this->params->valor($gestion, 'RCIVA_ALICUOTA');
        $nExento = $this->params->valor($gestion, 'RCIVA_MIN_EXENTO_SMN');
        $dosSmn = $smn * 2;

        $lineas = [];
        foreach ($preview['lineas'] as $l) {
            $totalGanado = (float) $l['total_ganado'];
            $aportes = (float) $l['aporte_afp'] + $l['riesgo_comun'] + $l['comision_afp']
                     + $l['solidario_aseg'] + $l['nacional_solidario'];

            $smnNoImp = min($dosSmn, $totalGanado);
            $baseImponible = max(0, $totalGanado - $aportes - $smnNoImp);
            $impuesto = round($baseImponible * $alicuota / 100, 2);
            $credito13 = round($dosSmn * $alicuota / 100, 2);
            $impuestoNeto = max(0, round($impuesto - $credito13, 2));

            $saldoAnterior = $this->obtenerSaldoAnterior($l['id_empleado'], $gestion, $mes);
            $saldoAntAct = $saldoAnterior;
            $saldoUtilizado = min($saldoAntAct, $impuestoNeto);
            $rcivaSujetoRetencion = max(0, round($impuestoNeto - $saldoUtilizado, 2));

            $f110 = 0;
            $rcivRetenido = max(0, round($rcivaSujetoRetencion - $f110, 2));

            $saldoFavorFisco = $impuestoNeto > 0 ? $impuestoNeto : 0;
            $saldoFavorDep = $impuestoNeto <= 0 ? abs($impuestoNeto) : 0;
            $saldoCreditoFiscal = max(0, round($saldoAntAct - $saldoUtilizado, 2));

            $lineas[] = [
                'id_empleado'                  => $l['id_empleado'],
                'ci'                           => $l['ci'] ?? '',
                'empleado'                     => $l['empleado'] ?? '',
                'gestion'                      => $gestion,
                'mes'                          => $mes,
                'total_ganado'                 => $totalGanado,
                'total_aportes_laborales'      => round($aportes, 2),
                'smn_no_imponibles'            => round($smnNoImp, 2),
                'importe_sujeto_impuesto'      => round($baseImponible, 2),
                'impuesto_rciva'               => $impuesto,
                'credito_13pct_2smn'           => $credito13,
                'impuesto_neto'                => $impuestoNeto,
                'f110_casilla_693'             => $f110,
                'saldo_favor_fisco'            => round($saldoFavorFisco, 2),
                'saldo_favor_dependiente'      => round($saldoFavorDep, 2),
                'saldo_anterior_dependiente'   => round($saldoAnterior, 2),
                'mantenimiento_valor'          => 0,
                'saldo_anterior_actualizado'   => round($saldoAntAct, 2),
                'saldo_utilizado'              => round($saldoUtilizado, 2),
                'rciva_retenido'               => $rcivRetenido,
                'saldo_credito_fiscal'         => $saldoCreditoFiscal,
                'saldo_pago_cuenta_7rg'        => 0,
            ];
        }

        $totales = [
            'total_ganado'    => 0, 'total_aportes' => 0, 'impuesto_rciva' => 0,
            'impuesto_neto'   => 0, 'rciva_retenido' => 0,
        ];
        foreach ($lineas as $ln) {
            $totales['total_ganado']    += $ln['total_ganado'];
            $totales['total_aportes']   += $ln['total_aportes_laborales'];
            $totales['impuesto_rciva']  += $ln['impuesto_rciva'];
            $totales['impuesto_neto']   += $ln['impuesto_neto'];
            $totales['rciva_retenido']  += $ln['rciva_retenido'];
        }
        foreach ($totales as &$v) $v = round($v, 2);

        return [
            'gestion'  => $gestion,
            'mes'      => $mes,
            'lineas'   => $lineas,
            'totales'  => $totales,
        ];
    }

    private function obtenerSaldoAnterior(int $idEmpleado, int $gestion, int $mes): float
    {
        $mesAnt = $mes - 1;
        $gestAnt = $gestion;
        if ($mesAnt < 1) { $mesAnt = 12; $gestAnt--; }

        $saldo = RawConnection::scalar(
            "SELECT saldo_credito_fiscal FROM rrhh_planilla_tributaria
             WHERE id_empleado = :id AND gestion = :g AND mes = :m",
            [':id' => $idEmpleado, ':g' => $gestAnt, ':m' => $mesAnt]
        );

        return $saldo !== null ? (float) $saldo : 0;
    }

    public function resumenAnual(int $gestion, int $idEmpleado): array
    {
        return RawConnection::select(
            "SELECT mes, total_ganado, total_aportes_laborales, impuesto_rciva,
                    impuesto_neto, f110_casilla_693, rciva_retenido,
                    saldo_credito_fiscal
             FROM rrhh_planilla_tributaria
             WHERE id_empleado = :id AND gestion = :g
             ORDER BY mes",
            [':id' => $idEmpleado, ':g' => $gestion]
        );
    }
}
