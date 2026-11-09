<?php

namespace App\Modules\RRHH\Services;

use App\Modules\Shared\Database\RawConnection;

class LiquidacionService
{
    private ParametrosService $params;

    public function __construct(ParametrosService $params)
    {
        $this->params = $params;
    }

    public function listar(?string $estado = null): array
    {
        $sql = "SELECT f.id, f.id_empleado, f.id_contrato, f.motivo_baja AS motivo,
                       f.fecha_ingreso, f.fecha_retiro AS fecha_desvinculacion,
                       f.anios_trabajados, f.promedio_indemnizacion AS sueldo_promedio,
                       f.indemnizacion, f.desahucio, f.aguinaldo_navidad AS aguinaldo_proporcional,
                       f.doble_aguinaldo AS doble_aguinaldo_prop,
                       f.vacaciones_dias AS vacaciones_pendientes, f.vacaciones_monto AS monto_vacaciones,
                       f.total_beneficios AS total_liquidacion,
                       f.total_deducciones AS total_descuentos,
                       f.liquido_pagable AS liquido_final,
                       f.estado, f.created_at,
                       CONCAT(e.apellido_paterno, ' ', IFNULL(e.apellido_materno,''), ' ', e.nombres) AS empleado,
                       e.ci, ca.descripcion AS cargo
                FROM rrhh_finiquito f
                JOIN rrhh_empleado e ON e.id = f.id_empleado
                JOIN rrhh_contrato c ON c.id = f.id_contrato
                LEFT JOIN cargo ca ON ca.id_cargo = c.id_cargo
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
            "SELECT f.*, f.motivo_baja AS motivo,
                    f.fecha_retiro AS fecha_desvinculacion,
                    f.promedio_indemnizacion AS sueldo_promedio,
                    f.aguinaldo_navidad AS aguinaldo_proporcional,
                    f.total_beneficios AS total_liquidacion,
                    f.total_deducciones AS total_descuentos,
                    f.liquido_pagable AS liquido_final,
                    CONCAT(e.apellido_paterno, ' ', IFNULL(e.apellido_materno,''), ' ', e.nombres) AS empleado,
                    e.ci, ca.descripcion AS cargo, c.fecha_inicio,
                    c.tipo_contrato, a.descripcion AS agencia
             FROM rrhh_finiquito f
             JOIN rrhh_empleado e ON e.id = f.id_empleado
             JOIN rrhh_contrato c ON c.id = f.id_contrato
             LEFT JOIN cargo ca ON ca.id_cargo = c.id_cargo
             LEFT JOIN agencia a ON a.id_agencia = c.id_agencia
             WHERE f.id = :id",
            [':id' => $id]
        );
    }

    public function simular(int $idEmpleado, string $fechaDesvinculacion, string $motivo): array
    {
        $contrato = RawConnection::selectOne(
            "SELECT c.*, ca.descripcion AS cargo
             FROM rrhh_contrato c
             LEFT JOIN cargo ca ON ca.id_cargo = c.id_cargo
             WHERE c.id_empleado = :id AND c.estado = 'VIGENTE' LIMIT 1",
            [':id' => $idEmpleado]
        );

        if (!$contrato) {
            throw new \RuntimeException("Empleado {$idEmpleado} no tiene contrato vigente");
        }

        $gestion     = (int) date('Y', strtotime($fechaDesvinculacion));
        $fechaInicio = $contrato['fecha_inicio'];
        $sueldo      = (float) $contrato['sueldo_base'];

        $diffDias    = (strtotime($fechaDesvinculacion) - strtotime($fechaInicio)) / 86400;
        $anios       = $diffDias / 365.25;
        $aniosInt    = (int) floor($anios);

        $promedio = $this->promedioUltimos3Meses($idEmpleado, $fechaDesvinculacion, $sueldo);

        $indemnizacion = 0;
        if ($diffDias > 90) {
            $indemnizacion = round($promedio * $anios, 2);
        }

        $desahucio = 0;
        if ($motivo === 'DESPIDO_INJUSTIFICADO') {
            $desahucio = round($promedio * 3, 2);
        }

        $mesDesv   = (int) date('m', strtotime($fechaDesvinculacion));
        $aguinaldo = round($promedio / 12 * $mesDesv, 2);

        $dobleAg   = 0;
        try {
            $aplica = $this->params->valor($gestion, 'DOBLE_AGUINALDO');
            if ($aplica > 0) {
                $dobleAg = round($promedio / 12 * $mesDesv, 2);
            }
        } catch (\RuntimeException $e) {}

        $diasVacPend = $this->diasVacacionesPendientes($idEmpleado, $gestion, $aniosInt, $fechaInicio, $fechaDesvinculacion);
        $montoVac    = round($promedio / 30 * $diasVacPend, 2);

        $totalLiq    = round($indemnizacion + $desahucio + $aguinaldo + $dobleAg + $montoVac, 2);

        return [
            'id_empleado'           => $idEmpleado,
            'id_contrato'           => (int) $contrato['id'],
            'cargo'                 => $contrato['cargo'] ?? '',
            'fecha_ingreso'         => $fechaInicio,
            'fecha_desvinculacion'  => $fechaDesvinculacion,
            'motivo'                => $motivo,
            'anios_trabajados'      => round($anios, 2),
            'sueldo_promedio'       => $promedio,
            'indemnizacion'         => $indemnizacion,
            'desahucio'             => $desahucio,
            'aguinaldo_proporcional'=> $aguinaldo,
            'doble_aguinaldo_prop'  => $dobleAg,
            'vacaciones_pendientes' => $diasVacPend,
            'monto_vacaciones'      => $montoVac,
            'total_liquidacion'     => $totalLiq,
            'total_descuentos'      => 0,
            'liquido_final'         => $totalLiq,
        ];
    }

    private function promedioUltimos3Meses(int $idEmpleado, string $fecha, float $sueldoBase): float
    {
        $rows = RawConnection::select(
            "SELECT total_ganado FROM rrhh_planilla_detalle d
             JOIN rrhh_planilla p ON p.id = d.id_planilla
             WHERE d.id_empleado = :id
             ORDER BY p.gestion DESC, p.mes DESC LIMIT 3",
            [':id' => $idEmpleado]
        );

        if (count($rows) === 0) return $sueldoBase;

        $sum = array_sum(array_column($rows, 'total_ganado'));
        return round($sum / count($rows), 2);
    }

    private function diasVacacionesPendientes(int $idEmpleado, int $gestion, int $anios, string $fechaInicio, string $fechaDesv): float
    {
        $escala = RawConnection::selectOne(
            "SELECT dias_habiles FROM rrhh_escala_vacaciones
             WHERE gestion = :g AND anios_desde <= :a AND (anios_hasta >= :a2 OR anios_hasta IS NULL)",
            [':g' => $gestion, ':a' => max($anios, 1), ':a2' => max($anios, 1)]
        );
        $diasAnuales = $escala ? (int) $escala['dias_habiles'] : 15;

        $mesDesv = (int) date('m', strtotime($fechaDesv));
        $diaDesv = (int) date('d', strtotime($fechaDesv));
        $proporcional = round($diasAnuales / 360 * (($mesDesv - 1) * 30 + $diaDesv), 2);

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
