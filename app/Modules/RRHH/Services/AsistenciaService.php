<?php

namespace App\Modules\RRHH\Services;

use App\Modules\Shared\Database\RawConnection;

class AsistenciaService
{
    public function resumenMensual(int $gestion, int $mes, ?int $agencia = null): array
    {
        $periodo = sprintf('%04d-%02d', $gestion, $mes);
        $where = $agencia ? "AND e.id_agencia = :ag" : "";
        $params = [':p' => $periodo];
        if ($agencia) $params[':ag'] = $agencia;

        return RawConnection::select(
            "SELECT e.id AS id_empleado,
                    CONCAT(e.apellido_paterno, ' ', IFNULL(e.apellido_materno,''), ' ', e.nombres) AS empleado,
                    e.genero,
                    c.jornada_horas,
                    COUNT(a.id) AS registros,
                    SUM(CASE WHEN a.tipo = 'NORMAL' THEN 1 ELSE 0 END) AS dias_trabajados,
                    SUM(CASE WHEN a.tipo = 'FALTA' THEN 1 ELSE 0 END) AS faltas,
                    SUM(CASE WHEN a.tipo = 'PERMISO' THEN 1 ELSE 0 END) AS permisos,
                    SUM(CASE WHEN a.tipo = 'LICENCIA' THEN 1 ELSE 0 END) AS licencias,
                    COALESCE(SUM(a.horas_extra), 0) AS horas_extra,
                    SUM(CASE WHEN a.es_dominical = 1 THEN 1 ELSE 0 END) AS dominicales,
                    COALESCE(SUM(TIMESTAMPDIFF(HOUR, a.hora_entrada, a.hora_salida)), 0) AS horas_trabajadas
             FROM rrhh_empleado e
             JOIN rrhh_contrato c ON c.id_empleado = e.id AND c.estado = 'VIGENTE'
             LEFT JOIN rrhh_asistencia a ON a.id_empleado = e.id AND DATE_FORMAT(a.fecha, '%Y-%m') = :p
             WHERE e.estado = 'A' {$where}
             GROUP BY e.id
             ORDER BY e.apellido_paterno, e.apellido_materno",
            $params
        );
    }

    public function detalleEmpleado(int $idEmpleado, int $gestion, int $mes): array
    {
        $periodo = sprintf('%04d-%02d', $gestion, $mes);
        $registros = RawConnection::select(
            "SELECT a.*, e.nombres, e.apellido_paterno, e.apellido_materno,
                    e.cargo AS cargo_nombre, e.agencia AS agencia_nombre
             FROM rrhh_asistencia a
             JOIN rrhh_empleado e ON e.id = a.id_empleado
             WHERE a.id_empleado = :id AND DATE_FORMAT(a.fecha, '%Y-%m') = :p
             ORDER BY a.fecha",
            [':id' => $idEmpleado, ':p' => $periodo]
        );

        $nombre = '';
        $cargo = '';
        $agencia = 'Oficina Central';
        if (!empty($registros)) {
            $r0 = $registros[0];
            $nombre = trim(($r0['apellido_paterno'] ?? '') . ' ' . ($r0['apellido_materno'] ?? '') . ' ' . ($r0['nombres'] ?? ''));
            $cargo = $r0['cargo_nombre'] ?? '';
            $agencia = $r0['agencia_nombre'] ?? 'Oficina Central';
        } else {
            $emp = RawConnection::selectOne(
                "SELECT nombres, apellido_paterno, apellido_materno, cargo, agencia FROM rrhh_empleado WHERE id = :id",
                [':id' => $idEmpleado]
            );
            if ($emp) {
                $nombre = trim(($emp['apellido_paterno'] ?? '') . ' ' . ($emp['apellido_materno'] ?? '') . ' ' . ($emp['nombres'] ?? ''));
                $cargo = $emp['cargo'] ?? '';
                $agencia = $emp['agencia'] ?? 'Oficina Central';
            }
        }

        $totalFaltas = 0;
        $totalFeriados = 0;
        $totalAtrasos = 0;
        $totalExtras = 0.0;
        $diasTrabajados = 0;

        foreach ($registros as &$r) {
            if (($r['tipo'] ?? 'NORMAL') === 'NORMAL') $diasTrabajados++;
            if (($r['tipo'] ?? '') === 'FALTA') $totalFaltas++;
            if (($r['tipo'] ?? '') === 'FERIADO') $totalFeriados++;
            $totalAtrasos += (int)($r['atrasos_min'] ?? 0);
            $totalExtras += (float)($r['horas_extra'] ?? 0);
            unset($r['nombres'], $r['apellido_paterno'], $r['apellido_materno'], $r['cargo_nombre'], $r['agencia_nombre']);
        }

        return [
            'empleado' => $nombre,
            'cargo' => $cargo,
            'lugar_trabajo' => $agencia,
            'gestion' => $gestion,
            'mes' => $mes,
            'registros' => $registros,
            'resumen' => [
                'dias_trabajados' => $diasTrabajados,
                'faltas' => $totalFaltas,
                'feriados' => $totalFeriados,
                'atrasos_total' => $totalAtrasos,
                'horas_extra_total' => $totalExtras,
            ],
        ];
    }

    public function controlJornada(int $gestion, int $mes, ?int $agencia = null): array
    {
        $resumen = $this->resumenMensual($gestion, $mes, $agencia);
        $alertas = [];

        foreach ($resumen as &$emp) {
            $genero = $emp['genero'] ?? 'M';
            $jornadaDiaria = $genero === 'F' ? 7.0 : 8.0;
            $jornadaSemanal = $genero === 'F' ? 40.0 : 48.0;
            $diasTrabajados = (int) ($emp['dias_trabajados'] ?? 0);
            $horasTrabajadas = (float) ($emp['horas_trabajadas'] ?? ($diasTrabajados * $jornadaDiaria));
            $horasEsperadas = $diasTrabajados * $jornadaDiaria;
            $horasMaxMes = 30 * $jornadaDiaria;

            $emp['jornada_diaria'] = $jornadaDiaria;
            $emp['jornada_semanal'] = $jornadaSemanal;
            $emp['horas_trabajadas'] = $horasTrabajadas;
            $emp['horas_esperadas'] = $horasEsperadas;
            $emp['horas_max_mes'] = $horasMaxMes;
            $emp['porcentaje_jornada'] = $horasMaxMes > 0 ? round($horasTrabajadas / $horasMaxMes * 100, 1) : 0;

            $empAlertas = [];

            if ($horasTrabajadas > $horasMaxMes) {
                $empAlertas[] = [
                    'tipo' => 'EXCESO_JORNADA',
                    'severidad' => 'alta',
                    'mensaje' => sprintf(
                        'Excede jornada mensual: %.1fh trabajadas vs %.1fh máximo (%s)',
                        $horasTrabajadas, $horasMaxMes, $genero === 'F' ? 'mujer: 7h/día' : 'hombre: 8h/día'
                    ),
                ];
            }

            if ($horasTrabajadas >= $horasMaxMes * 0.9 && $horasTrabajadas <= $horasMaxMes) {
                $empAlertas[] = [
                    'tipo' => 'CERCA_LIMITE',
                    'severidad' => 'media',
                    'mensaje' => sprintf(
                        'Cerca del límite: %.1fh de %.1fh (%.1f%%)',
                        $horasTrabajadas, $horasMaxMes, $emp['porcentaje_jornada']
                    ),
                ];
            }

            $horasExtra = (float) ($emp['horas_extra'] ?? 0);
            if ($horasExtra > 2 * $diasTrabajados) {
                $empAlertas[] = [
                    'tipo' => 'EXCESO_HORAS_EXTRA',
                    'severidad' => 'alta',
                    'mensaje' => sprintf('Horas extra exceden máximo legal (2h/día): %.1fh en %d días', $horasExtra, $diasTrabajados),
                ];
            }

            $emp['alertas'] = $empAlertas;
            if (count($empAlertas) > 0) {
                $alertas[] = [
                    'empleado' => $emp['empleado'],
                    'id_empleado' => $emp['id_empleado'],
                    'alertas' => $empAlertas,
                ];
            }
        }

        return [
            'gestion' => $gestion,
            'mes' => $mes,
            'resumen' => $resumen,
            'alertas' => $alertas,
            'total_empleados' => count($resumen),
            'total_con_alertas' => count($alertas),
        ];
    }

    public function registrar(array $data): array
    {
        $idEmpleado  = (int) ($data['id_empleado'] ?? 0);
        $fecha       = $data['fecha'] ?? '';
        $amIngreso   = $data['am_ingreso'] ?? null;
        $amSalida    = $data['am_salida'] ?? null;
        $pmIngreso   = $data['pm_ingreso'] ?? null;
        $pmSalida    = $data['pm_salida'] ?? null;
        $extras      = (float) ($data['extras'] ?? 0);
        $atrasos     = (int) ($data['atrasos'] ?? 0);
        $tipo        = $data['tipo'] ?? 'NORMAL';
        $observaciones = $data['observaciones'] ?? null;

        if (!$idEmpleado) throw new \RuntimeException('Debe seleccionar un empleado');
        if (!$fecha) throw new \RuntimeException('Debe indicar la fecha');
        if (!in_array($tipo, ['NORMAL', 'FALTA', 'FERIADO', 'PERMISO', 'LICENCIA'])) {
            throw new \RuntimeException('Tipo de asistencia inválido');
        }

        if ($tipo === 'NORMAL' && !$amIngreso) {
            throw new \RuntimeException('Debe registrar al menos la hora de ingreso del turno mañana');
        }

        $horaEntrada = $amIngreso;
        $horaSalida  = $pmSalida ?: $amSalida;

        RawConnection::execute(
            "INSERT INTO rrhh_asistencia
                (id_empleado, fecha, hora_entrada, hora_salida, am_ingreso, am_salida, pm_ingreso, pm_salida,
                 horas_extra, atrasos_min, tipo, observaciones, es_feriado, es_dominical)
             VALUES (:emp, :fecha, :entrada, :salida, :ami, :ams, :pmi, :pms,
                     :extras, :atrasos, :tipo, :obs, :fer, :dom)
             ON DUPLICATE KEY UPDATE
                hora_entrada = VALUES(hora_entrada), hora_salida = VALUES(hora_salida),
                am_ingreso = VALUES(am_ingreso), am_salida = VALUES(am_salida),
                pm_ingreso = VALUES(pm_ingreso), pm_salida = VALUES(pm_salida),
                horas_extra = VALUES(horas_extra), atrasos_min = VALUES(atrasos_min),
                tipo = VALUES(tipo), observaciones = VALUES(observaciones),
                es_feriado = VALUES(es_feriado)",
            [
                ':emp' => $idEmpleado, ':fecha' => $fecha,
                ':entrada' => $horaEntrada, ':salida' => $horaSalida,
                ':ami' => $amIngreso, ':ams' => $amSalida,
                ':pmi' => $pmIngreso, ':pms' => $pmSalida,
                ':extras' => $extras, ':atrasos' => $atrasos,
                ':tipo' => $tipo, ':obs' => $observaciones,
                ':fer' => $tipo === 'FERIADO' ? 1 : 0,
                ':dom' => ((int) date('N', strtotime($fecha))) === 7 ? 1 : 0,
            ]
        );

        $id = RawConnection::scalar(
            "SELECT id FROM rrhh_asistencia WHERE id_empleado = :emp AND fecha = :f",
            [':emp' => $idEmpleado, ':f' => $fecha]
        );

        return [
            'registro' => [
                'id' => $id,
                'id_empleado' => $idEmpleado,
                'fecha' => $fecha,
                'hora_entrada' => $horaEntrada,
                'hora_salida' => $horaSalida,
                'am_ingreso' => $amIngreso,
                'am_salida' => $amSalida,
                'pm_ingreso' => $pmIngreso,
                'pm_salida' => $pmSalida,
                'horas_extra' => $extras,
                'atrasos_min' => $atrasos,
                'tipo' => $tipo,
                'observaciones' => $observaciones,
            ],
        ];
    }
}
