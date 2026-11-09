<?php

namespace App\Modules\RRHH\Services;

use App\Modules\Shared\Database\RawConnection;

class PlanillaService
{
    private ParametrosService $params;

    public function __construct(ParametrosService $params)
    {
        $this->params = $params;
    }

    public function listar(int $gestion, ?int $agencia = null): array
    {
        $sql = "SELECT p.*, CONVERT(a.descripcion USING utf8mb4) AS agencia
                FROM rrhh_planilla p
                LEFT JOIN agencia a ON a.id_agencia = p.id_agencia
                WHERE p.gestion = :g";
        $prm = [':g' => $gestion];

        if ($agencia) {
            $sql .= " AND p.id_agencia = :ag";
            $prm[':ag'] = $agencia;
        }

        $sql .= " ORDER BY p.mes DESC";
        return RawConnection::select($sql, $prm);
    }

    public function detalle(int $idPlanilla): ?array
    {
        $planilla = RawConnection::selectOne(
            "SELECT p.*, CONVERT(a.descripcion USING utf8mb4) AS agencia
             FROM rrhh_planilla p
             LEFT JOIN agencia a ON a.id_agencia = p.id_agencia
             WHERE p.id = :id",
            [':id' => $idPlanilla]
        );
        if (!$planilla) return null;

        $detalle = RawConnection::select(
            "SELECT d.*,
                    CONCAT(e.apellido_paterno, ' ', IFNULL(e.apellido_materno,''), ' ', e.nombres) AS empleado,
                    e.ci, CONVERT(ca.descripcion USING utf8mb4) AS cargo
             FROM rrhh_planilla_detalle d
             JOIN rrhh_empleado e ON e.id = d.id_empleado
             JOIN rrhh_contrato ct ON ct.id = d.id_contrato
             LEFT JOIN cargo ca ON ca.id_cargo = ct.id_cargo
             WHERE d.id_planilla = :id
             ORDER BY e.apellido_paterno, e.apellido_materno, e.nombres",
            [':id' => $idPlanilla]
        );

        return [
            'planilla' => $planilla,
            'detalle'  => $detalle,
        ];
    }

    public function calcularEmpleado(int $gestion, int $mes, int $idEmpleado): array
    {
        $contrato = RawConnection::selectOne(
            "SELECT c.*, CONVERT(ca.descripcion USING utf8mb4) AS cargo
             FROM rrhh_contrato c
             LEFT JOIN cargo ca ON ca.id_cargo = c.id_cargo
             WHERE c.id_empleado = :id AND c.estado = 'VIGENTE' LIMIT 1",
            [':id' => $idEmpleado]
        );

        if (!$contrato) {
            throw new \RuntimeException("Empleado {$idEmpleado} no tiene contrato vigente");
        }

        $smn           = $this->params->valor($gestion, 'SMN');
        $sueldo        = (float) $contrato['sueldo_base'];
        $fechaInicio   = $contrato['fecha_inicio'];
        $esProd        = (bool) $contrato['es_empresa_prod'];

        $anios = (int) floor((strtotime("{$gestion}-{$mes}-28") - strtotime($fechaInicio)) / (365.25 * 86400));

        $bonoAnt = $this->calcularBonoAntiguedad($gestion, $anios, $smn, $esProd);

        $periodo = sprintf('%04d-%02d', $gestion, $mes);
        $horas = RawConnection::selectOne(
            "SELECT COALESCE(SUM(horas_extra), 0) AS he,
                    COALESCE(SUM(CASE WHEN es_dominical = 1 THEN 1 ELSE 0 END), 0) AS dom
             FROM rrhh_asistencia
             WHERE id_empleado = :id AND DATE_FORMAT(fecha, '%Y-%m') = :p",
            [':id' => $idEmpleado, ':p' => $periodo]
        );

        $horasExtra   = (float) ($horas['he'] ?? 0);
        $dominicales  = (float) ($horas['dom'] ?? 0);
        $valorHora    = $sueldo / 30 / 8;
        $montoHE      = round($horasExtra * $valorHora * 2, 2);
        $montoDom     = round($dominicales * ($sueldo / 30) * 3, 2);

        $totalGanado  = round($sueldo + $bonoAnt + $montoHE + $montoDom, 2);

        $pctAfp       = $this->params->valor($gestion, 'APORTE_LAB_AFP');
        $pctRC        = $this->params->valor($gestion, 'APORTE_LAB_RIESGO_COMUN');
        $pctCom       = $this->params->valor($gestion, 'APORTE_LAB_COMISION');
        $pctSolAseg   = $this->params->valor($gestion, 'APORTE_LAB_SOLIDARIO');

        $aporteAfp    = round($totalGanado * $pctAfp / 100, 2);
        $riesgoComun  = round($totalGanado * $pctRC / 100, 2);
        $comisionAfp  = round($totalGanado * $pctCom / 100, 2);
        $solAseg      = round($totalGanado * $pctSolAseg / 100, 2);
        $nacSol       = $this->calcularNacionalSolidario($gestion, $totalGanado);

        $rciva = $this->calcularRCIVA($gestion, $totalGanado, $aporteAfp + $riesgoComun + $comisionAfp + $solAseg + $nacSol);

        $totalDesc    = round($aporteAfp + $riesgoComun + $comisionAfp + $solAseg + $nacSol + $rciva, 2);
        $liquido      = round($totalGanado - $totalDesc, 2);

        $pctCNS       = $this->params->valor($gestion, 'APORTE_PAT_CNS');
        $pctRP        = $this->params->valor($gestion, 'APORTE_PAT_RIESGO_PROF');
        $pctPV        = $this->params->valor($gestion, 'APORTE_PAT_PROVIVIENDA');
        $pctPS        = $this->params->valor($gestion, 'APORTE_PAT_SOLIDARIO');

        $patCNS       = round($totalGanado * $pctCNS / 100, 2);
        $patRP        = round($totalGanado * $pctRP / 100, 2);
        $patPV        = round($totalGanado * $pctPV / 100, 2);
        $patSol       = round($totalGanado * $pctPS / 100, 2);
        $totalPat     = round($patCNS + $patRP + $patPV + $patSol, 2);

        $emp = RawConnection::selectOne(
            "SELECT ci FROM rrhh_empleado WHERE id = :id",
            [':id' => $idEmpleado]
        );

        return [
            'id_empleado'       => $idEmpleado,
            'id_contrato'       => (int) $contrato['id'],
            'ci'                => $emp['ci'] ?? '',
            'empleado'          => '',
            'cargo'             => $contrato['cargo'] ?? '',
            'sueldo_base'       => $sueldo,
            'bono_antiguedad'   => $bonoAnt,
            'antiguedad_anios'  => $anios,
            'horas_extra'       => $horasExtra,
            'monto_horas_extra' => $montoHE,
            'dominicales_trab'  => $dominicales,
            'monto_dominicales' => $montoDom,
            'total_ganado'      => $totalGanado,
            'aporte_afp'        => $aporteAfp,
            'riesgo_comun'      => $riesgoComun,
            'comision_afp'      => $comisionAfp,
            'solidario_aseg'    => $solAseg,
            'nacional_solidario'=> $nacSol,
            'rciva'             => $rciva,
            'total_descuentos'  => $totalDesc,
            'liquido_pagable'   => $liquido,
            'pat_cns'           => $patCNS,
            'pat_riesgo_prof'   => $patRP,
            'pat_provivienda'   => $patPV,
            'pat_solidario'     => $patSol,
            'total_patronal'    => $totalPat,
        ];
    }

    public function preview(int $gestion, int $mes, ?int $agencia = null): array
    {
        $sql = "SELECT e.id FROM rrhh_empleado e
                JOIN rrhh_contrato c ON c.id_empleado = e.id AND c.estado = 'VIGENTE'
                WHERE e.estado = 'A'";
        $params = [];
        if ($agencia) {
            $sql .= " AND e.id_agencia = :ag";
            $params[':ag'] = $agencia;
        }
        $empleados = RawConnection::select($sql, $params);

        $lineas = [];
        $totG = $totD = $totL = $totP = 0;

        foreach ($empleados as $emp) {
            try {
                $linea = $this->calcularEmpleado($gestion, $mes, (int) $emp['id']);
                $totG += $linea['total_ganado'];
                $totD += $linea['total_descuentos'];
                $totL += $linea['liquido_pagable'];
                $totP += $linea['total_patronal'];
                $lineas[] = $linea;
            } catch (\RuntimeException $e) {
                continue;
            }
        }

        return [
            'gestion'         => $gestion,
            'mes'             => $mes,
            'id_agencia'      => $agencia,
            'lineas'          => $lineas,
            'total_ganado'    => round($totG, 2),
            'total_descuento' => round($totD, 2),
            'total_liquido'   => round($totL, 2),
            'total_patronal'  => round($totP, 2),
        ];
    }

    public function generar(int $gestion, int $mes, ?int $agencia = null): array
    {
        $preview = $this->preview($gestion, $mes, $agencia);

        RawConnection::execute(
            "INSERT INTO rrhh_planilla (gestion, mes, id_agencia, estado, total_ganado, total_descuento, total_liquido, total_patronal)
             VALUES (:g, :m, :ag, 'CALCULADA', :tg, :td, :tl, :tp)
             ON DUPLICATE KEY UPDATE
                estado = 'CALCULADA', total_ganado = VALUES(total_ganado), total_descuento = VALUES(total_descuento),
                total_liquido = VALUES(total_liquido), total_patronal = VALUES(total_patronal), updated_at = NOW()",
            [':g' => $gestion, ':m' => $mes, ':ag' => $agencia,
             ':tg' => $preview['total_ganado'], ':td' => $preview['total_descuento'],
             ':tl' => $preview['total_liquido'], ':tp' => $preview['total_patronal']]
        );

        $idPlanilla = (int) RawConnection::scalar(
            "SELECT id FROM rrhh_planilla WHERE gestion = :g AND mes = :m AND (id_agencia = :ag OR (:ag2 IS NULL AND id_agencia IS NULL))",
            [':g' => $gestion, ':m' => $mes, ':ag' => $agencia, ':ag2' => $agencia]
        );

        RawConnection::execute(
            "DELETE FROM rrhh_planilla_detalle WHERE id_planilla = :id",
            [':id' => $idPlanilla]
        );

        foreach ($preview['lineas'] as $l) {
            RawConnection::execute(
                "INSERT INTO rrhh_planilla_detalle
                    (id_planilla, id_empleado, id_contrato, sueldo_base, bono_antiguedad, antiguedad_anios,
                     horas_extra, monto_horas_extra, dominicales_trab, monto_dominicales, total_ganado,
                     aporte_afp, riesgo_comun, comision_afp, solidario_aseg, nacional_solidario, rciva,
                     total_descuentos, liquido_pagable, pat_cns, pat_riesgo_prof, pat_provivienda, pat_solidario, total_patronal)
                 VALUES
                    (:pl, :emp, :ct, :sb, :ba, :aa, :he, :mhe, :dt, :md, :tg,
                     :afp, :rc, :ca, :sa, :ns, :rcv, :td, :lp, :pcns, :prp, :ppv, :ps, :tp)",
                [
                    ':pl' => $idPlanilla, ':emp' => $l['id_empleado'], ':ct' => $l['id_contrato'],
                    ':sb' => $l['sueldo_base'], ':ba' => $l['bono_antiguedad'], ':aa' => $l['antiguedad_anios'],
                    ':he' => $l['horas_extra'], ':mhe' => $l['monto_horas_extra'],
                    ':dt' => $l['dominicales_trab'], ':md' => $l['monto_dominicales'],
                    ':tg' => $l['total_ganado'], ':afp' => $l['aporte_afp'], ':rc' => $l['riesgo_comun'],
                    ':ca' => $l['comision_afp'], ':sa' => $l['solidario_aseg'], ':ns' => $l['nacional_solidario'],
                    ':rcv' => $l['rciva'], ':td' => $l['total_descuentos'], ':lp' => $l['liquido_pagable'],
                    ':pcns' => $l['pat_cns'], ':prp' => $l['pat_riesgo_prof'],
                    ':ppv' => $l['pat_provivienda'], ':ps' => $l['pat_solidario'], ':tp' => $l['total_patronal'],
                ]
            );
        }

        return ['id_planilla' => $idPlanilla, 'preview' => $preview];
    }

    private function calcularBonoAntiguedad(int $gestion, int $anios, float $smn, bool $esProd): float
    {
        if ($anios < 2) return 0;

        $escala = RawConnection::selectOne(
            "SELECT porcentaje FROM rrhh_escala_bono_antiguedad
             WHERE gestion = :g AND anios_desde <= :a AND (anios_hasta >= :a2 OR anios_hasta IS NULL)
             ORDER BY anios_desde DESC LIMIT 1",
            [':g' => $gestion, ':a' => $anios, ':a2' => $anios]
        );

        if (!$escala) return 0;

        $codigoBase = $esProd ? 'BONO_ANT_BASE_SMN_PROD' : 'BONO_ANT_BASE_SMN';
        $nroSmn = $this->params->valor($gestion, $codigoBase);
        $base = $smn * $nroSmn;

        return round($base * (float) $escala['porcentaje'] / 100, 2);
    }

    private function calcularNacionalSolidario(int $gestion, float $totalGanado): float
    {
        $ns = 0;
        if ($totalGanado > 35000) {
            $pct = $this->params->valor($gestion, 'APORTE_LAB_NAC_SOL3');
            $ns = round(($totalGanado - 35000) * $pct / 100, 2);
            $totalGanado = 35000;
        }
        if ($totalGanado > 25000) {
            $pct = $this->params->valor($gestion, 'APORTE_LAB_NAC_SOL2');
            $ns += round(($totalGanado - 25000) * $pct / 100, 2);
            $totalGanado = 25000;
        }
        if ($totalGanado > 13000) {
            $pct = $this->params->valor($gestion, 'APORTE_LAB_NACIONAL_SOL');
            $ns += round(($totalGanado - 13000) * $pct / 100, 2);
        }
        return $ns;
    }

    private function calcularRCIVA(int $gestion, float $totalGanado, float $totalAportes): float
    {
        $smn      = $this->params->valor($gestion, 'SMN');
        $alicuota = $this->params->valor($gestion, 'RCIVA_ALICUOTA');
        $nExento  = $this->params->valor($gestion, 'RCIVA_MIN_EXENTO_SMN');

        $baseImponible = $totalGanado - $totalAportes;
        $minExento = $smn * $nExento;

        if ($baseImponible <= $minExento) return 0;

        $impuesto = ($baseImponible - $minExento) * $alicuota / 100;
        return round(max(0, $impuesto), 2);
    }
}
