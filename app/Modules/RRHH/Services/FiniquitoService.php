<?php

namespace App\Modules\RRHH\Services;

use App\Modules\Shared\Database\RawConnection;

class FiniquitoService
{
    private ParametrosService $params;
    private PlanillaService $planilla;

    public function __construct(ParametrosService $params, PlanillaService $planilla)
    {
        $this->params = $params;
        $this->planilla = $planilla;
    }

    public function listar(?string $estado = null): array
    {
        $sql = "SELECT f.*,
                       CONCAT(e.apellido_paterno, ' ', IFNULL(e.apellido_materno,''), ' ', e.nombres) AS empleado,
                       e.ci, ca.descripcion AS cargo, ag.descripcion AS agencia
                FROM rrhh_finiquito f
                JOIN rrhh_empleado e ON e.id = f.id_empleado
                JOIN rrhh_contrato c ON c.id = f.id_contrato
                LEFT JOIN cargo ca ON ca.id_cargo = c.id_cargo
                LEFT JOIN agencia ag ON ag.id_agencia = c.id_agencia
                WHERE 1=1";
        $params = [];

        if ($estado) {
            $sql .= " AND f.estado = :est";
            $params[':est'] = $estado;
        }

        $sql .= " ORDER BY f.created_at DESC";
        return RawConnection::select($sql, $params);
    }

    public function detalle(int $id): ?array
    {
        return RawConnection::selectOne(
            "SELECT f.*,
                    CONCAT(e.apellido_paterno, ' ', IFNULL(e.apellido_materno,''), ' ', e.nombres) AS empleado,
                    e.ci, ca.descripcion AS cargo, ag.descripcion AS agencia,
                    c.tipo_contrato, c.sueldo_base
             FROM rrhh_finiquito f
             JOIN rrhh_empleado e ON e.id = f.id_empleado
             JOIN rrhh_contrato c ON c.id = f.id_contrato
             LEFT JOIN cargo ca ON ca.id_cargo = c.id_cargo
             LEFT JOIN agencia ag ON ag.id_agencia = c.id_agencia
             WHERE f.id = :id",
            [':id' => $id]
        );
    }

    public function calcular(int $idEmpleado, string $fechaRetiro, string $motivo): array
    {
        $contrato = RawConnection::selectOne(
            "SELECT c.*, ca.descripcion AS cargo
             FROM rrhh_contrato c
             LEFT JOIN cargo ca ON ca.id_cargo = c.id_cargo
             WHERE c.id_empleado = :id
             ORDER BY FIELD(c.estado, 'VIGENTE', 'RESCINDIDO', 'SUSPENDIDO', 'FINALIZADO'), c.id DESC
             LIMIT 1",
            [':id' => $idEmpleado]
        );

        if (!$contrato) {
            throw new \RuntimeException("Empleado {$idEmpleado} no tiene contrato registrado");
        }

        $fechaIngreso = $contrato['fecha_inicio'];
        $sueldo = (float) $contrato['sueldo_base'];
        $gestion = (int) date('Y', strtotime($fechaRetiro));

        $diff = $this->calcularTiempoTrabajo($fechaIngreso, $fechaRetiro);
        $diffDias = (strtotime($fechaRetiro) - strtotime($fechaIngreso)) / 86400;

        $remuneraciones = $this->obtenerRemuneraciones3Meses($idEmpleado, $fechaRetiro, $sueldo);
        $promedio = round(($remuneraciones[0]['total'] + $remuneraciones[1]['total'] + $remuneraciones[2]['total']) / 3, 2);

        $indemnizacion = 0;
        if ($diffDias > 90) {
            $indemnizacion = round(
                $promedio * $diff['anios']
                + ($promedio / 12) * $diff['meses']
                + ($promedio / 360) * $diff['dias'],
                2
            );
        }

        $desahucio = 0;
        if ($motivo === 'FORZOSO') {
            $desahucio = round($promedio * 3, 2);
        }

        $mesRetiro = (int) date('m', strtotime($fechaRetiro));
        $diaRetiro = (int) date('d', strtotime($fechaRetiro));
        $aguinaldo = round(($promedio / 12) * ($mesRetiro - 1) + ($promedio / 360) * $diaRetiro, 2);

        $dobleAg = 0;
        try {
            if ($this->params->valor($gestion, 'DOBLE_AGUINALDO') > 0) {
                $dobleAg = $aguinaldo;
            }
        } catch (\RuntimeException $e) {}

        $diasVac = $this->diasVacacionesPendientes($idEmpleado, $gestion, $diff['anios'], $fechaIngreso, $fechaRetiro);
        $montoVac = round($promedio / 30 * $diasVac, 2);

        $prima = 0;

        $diasUltimoMes = $diaRetiro;
        $otrosBeneficios = round(($promedio / 30) * $diasUltimoMes * 0.8729, 2);

        $totalBeneficios = round($indemnizacion + $desahucio + $aguinaldo + $dobleAg + $montoVac + $prima + $otrosBeneficios, 2);

        $retencionRciva = round(($montoVac + $prima) * 0.13, 2);
        $totalDeducciones = round($retencionRciva, 2);
        $liquido = round($totalBeneficios - $totalDeducciones, 2);

        return [
            'id_empleado'          => $idEmpleado,
            'id_contrato'          => (int) $contrato['id'],
            'cargo'                => $contrato['cargo'] ?? '',
            'motivo_baja'          => $motivo,
            'fecha_ingreso'        => $fechaIngreso,
            'fecha_retiro'         => $fechaRetiro,
            'anios_trabajados'     => $diff['anios'],
            'meses_trabajados'     => $diff['meses'],
            'dias_trabajados'      => $diff['dias'],
            'remuneraciones'       => $remuneraciones,
            'promedio_indemnizacion'=> $promedio,
            'indemnizacion'        => $indemnizacion,
            'desahucio'            => $desahucio,
            'aguinaldo_navidad'    => $aguinaldo,
            'doble_aguinaldo'      => $dobleAg,
            'vacaciones_dias'      => $diasVac,
            'vacaciones_monto'     => $montoVac,
            'prima'                => $prima,
            'otros_beneficios'     => $otrosBeneficios,
            'total_beneficios'     => $totalBeneficios,
            'retencion_rciva'      => $retencionRciva,
            'otros_descuentos'     => 0,
            'total_deducciones'    => $totalDeducciones,
            'liquido_pagable'      => $liquido,
        ];
    }

    public function guardar(int $idEmpleado, string $fechaRetiro, string $motivo): array
    {
        $calculo = $this->calcular($idEmpleado, $fechaRetiro, $motivo);

        RawConnection::execute(
            "INSERT INTO rrhh_finiquito (id_empleado, id_contrato, motivo_baja, fecha_ingreso, fecha_retiro,
                anios_trabajados, meses_trabajados, dias_trabajados, promedio_indemnizacion,
                indemnizacion, desahucio, aguinaldo_navidad, doble_aguinaldo,
                vacaciones_dias, vacaciones_monto, prima, otros_beneficios,
                total_beneficios, retencion_rciva, otros_descuentos, total_deducciones,
                liquido_pagable, estado, created_at, updated_at)
             VALUES (:emp, :ct, :mot, :fi, :fr, :at, :mt, :dt, :pi, :ind, :des, :ag, :dag,
                     :vd, :vm, :pri, :ob, :tb, :rr, :od, :td, :lp, 'CALCULADO', NOW(), NOW())
             ON DUPLICATE KEY UPDATE
                promedio_indemnizacion = VALUES(promedio_indemnizacion),
                indemnizacion = VALUES(indemnizacion), desahucio = VALUES(desahucio),
                aguinaldo_navidad = VALUES(aguinaldo_navidad), doble_aguinaldo = VALUES(doble_aguinaldo),
                vacaciones_dias = VALUES(vacaciones_dias), vacaciones_monto = VALUES(vacaciones_monto),
                total_beneficios = VALUES(total_beneficios), total_deducciones = VALUES(total_deducciones),
                liquido_pagable = VALUES(liquido_pagable), estado = 'CALCULADO', updated_at = NOW()",
            [
                ':emp' => $idEmpleado, ':ct' => $calculo['id_contrato'],
                ':mot' => $motivo, ':fi' => $calculo['fecha_ingreso'], ':fr' => $fechaRetiro,
                ':at' => $calculo['anios_trabajados'], ':mt' => $calculo['meses_trabajados'],
                ':dt' => $calculo['dias_trabajados'], ':pi' => $calculo['promedio_indemnizacion'],
                ':ind' => $calculo['indemnizacion'], ':des' => $calculo['desahucio'],
                ':ag' => $calculo['aguinaldo_navidad'], ':dag' => $calculo['doble_aguinaldo'],
                ':vd' => $calculo['vacaciones_dias'], ':vm' => $calculo['vacaciones_monto'],
                ':pri' => $calculo['prima'], ':ob' => $calculo['otros_beneficios'],
                ':tb' => $calculo['total_beneficios'], ':rr' => $calculo['retencion_rciva'],
                ':od' => $calculo['otros_descuentos'], ':td' => $calculo['total_deducciones'],
                ':lp' => $calculo['liquido_pagable'],
            ]
        );

        $id = (int) RawConnection::scalar(
            "SELECT id FROM rrhh_finiquito WHERE id_empleado = :emp AND id_contrato = :ct ORDER BY id DESC LIMIT 1",
            [':emp' => $idEmpleado, ':ct' => $calculo['id_contrato']]
        );

        $calculo['id_finiquito'] = $id;
        $calculo['estado'] = 'CALCULADO';
        return $calculo;
    }

    private function calcularTiempoTrabajo(string $desde, string $hasta): array
    {
        $d1 = new \DateTime($desde);
        $d2 = new \DateTime($hasta);
        $diff = $d1->diff($d2);

        return [
            'anios' => $diff->y,
            'meses' => $diff->m,
            'dias'  => $diff->d,
        ];
    }

    private function obtenerRemuneraciones3Meses(int $idEmpleado, string $fechaRef, float $sueldoBase): array
    {
        $mesesNombre = ['','Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
        $result = [];
        $fecha = new \DateTime($fechaRef);

        for ($intento = 0; $intento < 12 && count($result) < 3; $intento++) {
            $fecha->modify('-1 month');
            $mes = (int) $fecha->format('m');
            $gest = (int) $fecha->format('Y');

            $row = RawConnection::selectOne(
                "SELECT d.total_ganado, d.sueldo_base AS sueldo, d.bono_antiguedad,
                        (d.total_ganado - d.sueldo_base - d.bono_antiguedad) AS otros_bonos
                 FROM rrhh_planilla_detalle d
                 JOIN rrhh_planilla p ON p.id = d.id_planilla
                 WHERE d.id_empleado = :id AND p.gestion = :g AND p.mes = :m",
                [':id' => $idEmpleado, ':g' => $gest, ':m' => $mes]
            );

            if ($row && (float) $row['total_ganado'] > 0) {
                $result[] = [
                    'mes'             => $mesesNombre[$mes],
                    'gestion'         => $gest,
                    'sueldo'          => round((float) $row['sueldo'], 2),
                    'bono_antiguedad' => round((float) $row['bono_antiguedad'], 2),
                    'otros_bonos'     => round(max(0, (float) $row['otros_bonos']), 2),
                    'total'           => round((float) $row['total_ganado'], 2),
                ];
            }
        }

        while (count($result) < 3) {
            $result[] = [
                'mes' => '-', 'gestion' => 0,
                'sueldo' => $sueldoBase, 'bono_antiguedad' => 0,
                'otros_bonos' => 0, 'total' => $sueldoBase,
            ];
        }

        return $result;
    }

    private function diasVacacionesPendientes(int $idEmpleado, int $gestion, int $anios, string $fechaInicio, string $fechaRetiro): float
    {
        $escala = RawConnection::selectOne(
            "SELECT dias_habiles FROM rrhh_escala_vacaciones
             WHERE gestion = :g AND anios_desde <= :a AND (anios_hasta >= :a2 OR anios_hasta IS NULL)",
            [':g' => $gestion, ':a' => max($anios, 1), ':a2' => max($anios, 1)]
        );
        $diasAnuales = $escala ? (int) $escala['dias_habiles'] : 15;

        $mesRetiro = (int) date('m', strtotime($fechaRetiro));
        $diaRetiro = (int) date('d', strtotime($fechaRetiro));
        $proporcional = round($diasAnuales / 360 * (($mesRetiro - 1) * 30 + $diaRetiro), 2);

        $usados = (int) RawConnection::scalar(
            "SELECT COALESCE(SUM(dias), 0) FROM rrhh_vacacion
             WHERE id_empleado = :id AND tipo = 'VACACION'
               AND estado IN ('APROBADA','GOZADA')
               AND YEAR(fecha_inicio) = :g",
            [':id' => $idEmpleado, ':g' => $gestion]
        );

        return max(0, round($proporcional - $usados, 2));
    }
}
