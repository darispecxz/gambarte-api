<?php

namespace App\Modules\RRHH\Services;

use App\Modules\Shared\Database\RawConnection;

class VacacionService
{
    private ParametrosService $parametros;

    public function __construct(ParametrosService $parametros)
    {
        $this->parametros = $parametros;
    }

    public function resumen(int $gestion, ?int $agencia = null): array
    {
        $where = $agencia ? "AND e.id_agencia = :ag" : "";
        $params = $agencia ? [':ag' => $agencia] : [];

        $pendientes = (int) RawConnection::scalar(
            "SELECT COUNT(*) FROM rrhh_vacacion WHERE estado = 'SOLICITADA'", []
        );

        $enVacacion = (int) RawConnection::scalar(
            "SELECT COUNT(DISTINCT v.id_empleado) FROM rrhh_vacacion v
             WHERE v.estado = 'APROBADA' AND v.fecha_inicio <= CURDATE() AND v.fecha_fin >= CURDATE()", []
        );

        $proximasVac = (int) RawConnection::scalar(
            "SELECT COUNT(DISTINCT v.id_empleado) FROM rrhh_vacacion v
             WHERE v.estado = 'APROBADA'
               AND v.fecha_inicio BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)", []
        );

        $escala = $this->parametros->escalaVacaciones($gestion);

        $saldos = RawConnection::select(
            "SELECT e.id, e.ci, e.genero,
                    CONCAT(e.apellido_paterno, ' ', IFNULL(e.apellido_materno,''), ' ', e.nombres) AS empleado,
                    co.fecha_inicio, co.sueldo_base,
                    e.cargo, e.area, e.agencia
             FROM rrhh_empleado e
             JOIN rrhh_contrato co ON co.id_empleado = e.id AND co.estado = 'VIGENTE'
             WHERE e.estado = 'A' {$where}
             ORDER BY e.apellido_paterno, e.apellido_materno",
            $params
        );

        $saldosCompletos = [];
        foreach ($saldos as $emp) {
            $anios = (int) floor((strtotime("{$gestion}-12-31") - strtotime($emp['fecha_inicio'])) / (365.25 * 86400));
            $diasCorresponde = $this->diasPorEscala($escala, $anios);

            $diasUsados = (int) RawConnection::scalar(
                "SELECT COALESCE(SUM(dias), 0) FROM rrhh_vacacion
                 WHERE id_empleado = :id AND tipo = 'VACACION'
                   AND estado IN ('APROBADA','GOZADA')
                   AND YEAR(fecha_inicio) = :g",
                [':id' => $emp['id'], ':g' => $gestion]
            );

            $diasSolicitados = (int) RawConnection::scalar(
                "SELECT COALESCE(SUM(dias), 0) FROM rrhh_vacacion
                 WHERE id_empleado = :id AND tipo = 'VACACION'
                   AND estado = 'SOLICITADA'
                   AND YEAR(fecha_inicio) = :g",
                [':id' => $emp['id'], ':g' => $gestion]
            );

            $saldosCompletos[] = [
                'id'               => (int) $emp['id'],
                'ci'               => $emp['ci'],
                'empleado'         => trim($emp['empleado']),
                'cargo'            => $emp['cargo'],
                'area'             => $emp['area'],
                'agencia'          => $emp['agencia'],
                'fecha_ingreso'    => $emp['fecha_inicio'],
                'antiguedad_anios' => $anios,
                'dias_corresponde' => $diasCorresponde,
                'dias_usados'      => $diasUsados,
                'dias_solicitados' => $diasSolicitados,
                'saldo'            => $diasCorresponde - $diasUsados,
            ];
        }

        $solicitudes = RawConnection::select(
            "SELECT v.id, v.tipo, v.fecha_inicio, v.fecha_fin, v.dias, v.estado,
                    v.observaciones, v.created_at,
                    CONCAT(e.apellido_paterno, ' ', IFNULL(e.apellido_materno,''), ' ', e.nombres) AS empleado,
                    e.cargo
             FROM rrhh_vacacion v
             JOIN rrhh_empleado e ON e.id = v.id_empleado
             ORDER BY v.created_at DESC LIMIT 30",
            []
        );

        return [
            'gestion'               => $gestion,
            'pendientes_aprobacion' => $pendientes,
            'en_vacacion_hoy'       => $enVacacion,
            'proximas_30_dias'      => $proximasVac,
            'total_empleados'       => count($saldosCompletos),
            'escala'                => $escala,
            'saldos'                => $saldosCompletos,
            'solicitudes'           => $solicitudes,
        ];
    }

    public function cronograma(int $gestion): array
    {
        $feriados = $this->parametros->feriados($gestion);

        $feriadoMap = [];
        foreach ($feriados as $f) {
            $feriadoMap[$f['fecha']] = $f['nombre'];
        }

        $empleados = RawConnection::select(
            "SELECT e.id, e.ci, e.genero,
                    CONCAT(e.apellido_paterno, ' ', IFNULL(e.apellido_materno,''), ' ', e.nombres) AS nombre_completo,
                    e.apellido_paterno, e.nombres,
                    e.cargo, e.area,
                    e.agencia, e.agencia AS agencia_cod
             FROM rrhh_empleado e
             JOIN rrhh_contrato co ON co.id_empleado = e.id AND co.estado = 'VIGENTE'
             WHERE e.estado = 'A'
             ORDER BY e.agencia, e.apellido_paterno",
            []
        );

        $vacaciones = RawConnection::select(
            "SELECT v.id_empleado, v.fecha_inicio, v.fecha_fin, v.tipo, v.estado
             FROM rrhh_vacacion v
             JOIN rrhh_empleado e ON e.id = v.id_empleado AND e.estado = 'A'
             WHERE v.estado IN ('APROBADA','GOZADA','SOLICITADA')
               AND (YEAR(v.fecha_inicio) = :g OR YEAR(v.fecha_fin) = :g2)
             ORDER BY v.fecha_inicio",
            [':g' => $gestion, ':g2' => $gestion]
        );

        return $this->buildCronograma($gestion, $empleados, $vacaciones, $feriadoMap);
    }

    private function buildCronograma(int $gestion, array $empleados, array $vacaciones, array $feriadoMap): array
    {
        $empMap = [];
        foreach ($empleados as $e) {
            $id = $e['id'];
            if (!isset($e['codigo'])) {
                $parts = explode(' ', $e['apellido_paterno'] ?? $e['nombre_completo']);
                $e['codigo'] = strtoupper(substr($parts[0] ?? 'X', 0, 3));
                $e['grupo'] = strtolower(substr($e['agencia_cod'] ?? $e['agencia'] ?? 'OFC', 0, 3));
            }
            $empMap[$id] = $e;
        }

        $vacPorEmpleado = [];
        foreach ($vacaciones as $v) {
            $eid = (int) $v['id_empleado'];
            $start = strtotime($v['fecha_inicio']);
            $end = strtotime($v['fecha_fin']);
            $current = $start;
            while ($current <= $end) {
                $fecha = date('Y-m-d', $current);
                if ((int) date('Y', $current) === $gestion) {
                    $vacPorEmpleado[$eid][$fecha] = $v['estado'];
                }
                $current = strtotime('+1 day', $current);
            }
        }

        $meses = [];
        for ($m = 1; $m <= 12; $m++) {
            $diasEnMes = (int) date('t', mktime(0, 0, 0, $m, 1, $gestion));
            $dias = [];
            for ($d = 1; $d <= $diasEnMes; $d++) {
                $fecha = sprintf('%04d-%02d-%02d', $gestion, $m, $d);
                $dow = (int) date('w', strtotime($fecha));
                $esFeriado = isset($feriadoMap[$fecha]);
                $esFinde = ($dow === 0 || $dow === 6);

                $empEnDia = [];
                foreach ($vacPorEmpleado as $eid => $fechas) {
                    if (isset($fechas[$fecha]) && isset($empMap[$eid])) {
                        $empEnDia[] = [
                            'codigo' => $empMap[$eid]['codigo'],
                            'grupo'  => $empMap[$eid]['grupo'],
                            'estado' => $fechas[$fecha],
                        ];
                    }
                }

                $dias[] = [
                    'dia'       => $d,
                    'dow'       => $dow,
                    'feriado'   => $esFeriado ? $feriadoMap[$fecha] : null,
                    'es_finde'  => $esFinde,
                    'empleados' => $empEnDia,
                ];
            }
            $meses[] = [
                'mes'    => $m,
                'nombre' => $this->nombreMes($m),
                'dias'   => $dias,
            ];
        }

        $leyenda = [];
        foreach ($empMap as $e) {
            $ag = $e['agencia_cod'] ?? strtoupper(substr($e['agencia'] ?? '', 0, 3));
            $leyenda[] = [
                'codigo'   => $e['codigo'],
                'nombre'   => $e['nombre_completo'],
                'cargo'    => $e['cargo'] ?? null,
                'agencia'  => $ag,
                'grupo'    => $e['grupo'],
            ];
        }

        usort($leyenda, fn($a, $b) => strcmp($a['agencia'] . $a['codigo'], $b['agencia'] . $b['codigo']));

        return [
            'gestion'  => $gestion,
            'meses'    => $meses,
            'leyenda'  => $leyenda,
            'feriados' => array_map(fn($f, $n) => ['fecha' => $f, 'nombre' => $n], array_keys($feriadoMap), array_values($feriadoMap)),
        ];
    }

    private function nombreMes(int $m): string
    {
        $nombres = ['', 'Enero', 'Febrero', 'Marzo', 'Abril', 'Mayo', 'Junio', 'Julio', 'Agosto', 'Septiembre', 'Octubre', 'Noviembre', 'Diciembre'];
        return $nombres[$m] ?? '';
    }

    private function diasPorEscala(array $escala, int $anios): int
    {
        foreach ($escala as $rango) {
            $desde = (int) $rango['anios_desde'];
            $hasta = $rango['anios_hasta'] !== null ? (int) $rango['anios_hasta'] : 999;
            if ($anios >= $desde && $anios <= $hasta) {
                return (int) $rango['dias_habiles'];
            }
        }
        return 15;
    }

    public function solicitar(array $data, int $gestion): array
    {
        $idEmpleado = (int) ($data['id_empleado'] ?? 0);
        $fechaInicio = $data['fecha_inicio'] ?? '';
        $fechaFin = $data['fecha_fin'] ?? '';
        $tipo = $data['tipo'] ?? 'VACACION';
        $observaciones = $data['observaciones'] ?? null;

        if (!$idEmpleado || !$fechaInicio || !$fechaFin) {
            return ['error' => 'Campos obligatorios: id_empleado, fecha_inicio, fecha_fin'];
        }

        if (strtotime($fechaFin) < strtotime($fechaInicio)) {
            return ['error' => 'La fecha fin no puede ser anterior a la fecha inicio'];
        }

        $dias = $this->calcularDiasHabiles($fechaInicio, $fechaFin, $gestion);

        $contrato = RawConnection::selectOne(
            "SELECT co.fecha_inicio FROM rrhh_contrato co WHERE co.id_empleado = :id AND co.estado = 'VIGENTE' LIMIT 1",
            [':id' => $idEmpleado]
        );

        if (!$contrato) {
            return ['error' => 'Empleado no encontrado o sin contrato vigente'];
        }

        $anios = (int) floor((strtotime("{$gestion}-12-31") - strtotime($contrato['fecha_inicio'])) / (365.25 * 86400));

        if ($anios < 1 && $tipo === 'VACACION') {
            return ['error' => 'El empleado no cumple 1 año de antigüedad (DS 17288). No tiene derecho a vacación aún.'];
        }

        $escala = $this->parametros->escalaVacaciones($gestion);
        $diasCorresponde = $this->diasPorEscala($escala, $anios);

        $diasUsados = (int) RawConnection::scalar(
            "SELECT COALESCE(SUM(dias), 0) FROM rrhh_vacacion
             WHERE id_empleado = :id AND tipo = 'VACACION'
               AND estado IN ('APROBADA','GOZADA')
               AND YEAR(fecha_inicio) = :g",
            [':id' => $idEmpleado, ':g' => $gestion]
        );
        $saldo = $diasCorresponde - $diasUsados;

        if ($tipo === 'VACACION' && $dias > $saldo) {
            return ['error' => "Saldo insuficiente. Disponible: {$saldo} días, solicitados: {$dias} días."];
        }

        RawConnection::execute(
            "INSERT INTO rrhh_vacacion (id_empleado, tipo, fecha_inicio, fecha_fin, dias, estado, observaciones, created_at, updated_at)
             VALUES (:id_emp, :tipo, :fi, :ff, :dias, 'SOLICITADA', :obs, NOW(), NOW())",
            [':id_emp' => $idEmpleado, ':tipo' => $tipo, ':fi' => $fechaInicio, ':ff' => $fechaFin, ':dias' => $dias, ':obs' => $observaciones]
        );

        $id = RawConnection::lastInsertId();

        return [
            'solicitud' => [
                'id' => (int) $id,
                'id_empleado' => $idEmpleado,
                'tipo' => $tipo,
                'fecha_inicio' => $fechaInicio,
                'fecha_fin' => $fechaFin,
                'dias' => $dias,
                'estado' => 'SOLICITADA',
                'observaciones' => $observaciones,
            ],
            'saldo_restante' => $saldo - $dias,
        ];
    }

    public function aprobar(int $idVacacion): array
    {
        $vac = RawConnection::selectOne("SELECT * FROM rrhh_vacacion WHERE id = :id", [':id' => $idVacacion]);
        if (!$vac) return ['error' => 'Solicitud no encontrada'];
        if ($vac['estado'] !== 'SOLICITADA') return ['error' => 'Solo se pueden aprobar solicitudes en estado SOLICITADA'];

        RawConnection::execute("UPDATE rrhh_vacacion SET estado = 'APROBADA', updated_at = NOW() WHERE id = :id", [':id' => $idVacacion]);
        return ['id' => $idVacacion, 'estado' => 'APROBADA'];
    }

    public function rechazar(int $idVacacion, ?string $motivo = null): array
    {
        $vac = RawConnection::selectOne("SELECT * FROM rrhh_vacacion WHERE id = :id", [':id' => $idVacacion]);
        if (!$vac) return ['error' => 'Solicitud no encontrada'];
        if ($vac['estado'] !== 'SOLICITADA') return ['error' => 'Solo se pueden rechazar solicitudes en estado SOLICITADA'];

        $obs = $motivo ? $motivo : $vac['observaciones'];
        RawConnection::execute("UPDATE rrhh_vacacion SET estado = 'RECHAZADA', observaciones = :obs, updated_at = NOW() WHERE id = :id", [':id' => $idVacacion, ':obs' => $obs]);
        return ['id' => $idVacacion, 'estado' => 'RECHAZADA'];
    }

    private function calcularDiasHabiles(string $desde, string $hasta, int $gestion): int
    {
        $feriados = array_column($this->parametros->feriados($gestion), 'fecha');

        $dias = 0;
        $current = strtotime($desde);
        $end = strtotime($hasta);
        while ($current <= $end) {
            $dow = (int) date('w', $current);
            $fecha = date('Y-m-d', $current);
            if ($dow !== 0 && $dow !== 6 && !in_array($fecha, $feriados)) {
                $dias++;
            }
            $current = strtotime('+1 day', $current);
        }
        return $dias;
    }
}
