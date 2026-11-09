<?php

namespace App\Modules\RRHH\Services;

use App\Modules\Shared\Database\RawConnection;

class HorarioService
{
    public function listarHorarios(): array
    {
        return RawConnection::select(
            "SELECT h.*, GROUP_CONCAT(h2.dia ORDER BY FIELD(h2.dia,'LUNES','MARTES','MIERCOLES','JUEVES','VIERNES','SABADO','DOMINGO')) AS dias
             FROM rrhh_horario h
             LEFT JOIN rrhh_horario h2 ON h2.nombre = h.nombre AND h2.activo = 1
             WHERE h.activo = 1
             GROUP BY h.nombre, h.hora_inicio, h.hora_fin
             ORDER BY h.nombre, h.hora_inicio"
        );
    }

    public function horariosPorNombre(string $nombre): array
    {
        return RawConnection::select(
            "SELECT * FROM rrhh_horario
             WHERE nombre = :n AND activo = 1
             ORDER BY FIELD(dia,'LUNES','MARTES','MIERCOLES','JUEVES','VIERNES','SABADO','DOMINGO'), hora_inicio",
            [':n' => $nombre]
        );
    }

    public function horarioPorContrato(int $idContrato, ?string $fecha = null): array
    {
        $fecha = $fecha ?? date('Y-m-d');

        return RawConnection::select(
            "SELECT h.*
             FROM rrhh_contrato_horario ch
             JOIN rrhh_horario h ON h.id = ch.id_horario AND h.activo = 1
             WHERE ch.id_contrato = :id
               AND ch.vigencia_desde <= :f
               AND (ch.vigencia_hasta IS NULL OR ch.vigencia_hasta >= :f2)
             ORDER BY FIELD(h.dia,'LUNES','MARTES','MIERCOLES','JUEVES','VIERNES','SABADO','DOMINGO'), h.hora_inicio",
            [':id' => $idContrato, ':f' => $fecha, ':f2' => $fecha]
        );
    }

    public function asignacionesVigentes(?int $idAgencia = null): array
    {
        $sql = "SELECT ch.*, h.nombre AS horario_nombre, h.dia, h.hora_inicio, h.hora_fin,
                       CONCAT(e.apellido_paterno, ' ', IFNULL(e.apellido_materno,''), ' ', e.nombres) AS empleado,
                       e.ci, ca.descripcion AS cargo, ag.descripcion AS agencia
                FROM rrhh_contrato_horario ch
                JOIN rrhh_horario h ON h.id = ch.id_horario
                JOIN rrhh_contrato c ON c.id = ch.id_contrato AND c.estado = 'VIGENTE'
                JOIN rrhh_empleado e ON e.id = c.id_empleado
                LEFT JOIN cargo ca ON ca.id_cargo = c.id_cargo
                LEFT JOIN agencia ag ON ag.id_agencia = c.id_agencia
                WHERE (ch.vigencia_hasta IS NULL OR ch.vigencia_hasta >= CURDATE())";
        $params = [];

        if ($idAgencia) {
            $sql .= " AND c.id_agencia = :ag";
            $params[':ag'] = $idAgencia;
        }

        $sql .= " ORDER BY e.apellido_paterno, h.dia, h.hora_inicio";
        return RawConnection::select($sql, $params);
    }

    public function empleadosSinHorario(): array
    {
        return RawConnection::select(
            "SELECT e.id, CONCAT(e.apellido_paterno, ' ', IFNULL(e.apellido_materno,''), ' ', e.nombres) AS empleado,
                    e.ci, ca.descripcion AS cargo, ag.descripcion AS agencia
             FROM rrhh_empleado e
             JOIN rrhh_contrato c ON c.id_empleado = e.id AND c.estado = 'VIGENTE'
             LEFT JOIN cargo ca ON ca.id_cargo = c.id_cargo
             LEFT JOIN agencia ag ON ag.id_agencia = c.id_agencia
             WHERE e.estado = 'A'
               AND NOT EXISTS (
                   SELECT 1 FROM rrhh_contrato_horario ch
                   WHERE ch.id_contrato = c.id
                     AND (ch.vigencia_hasta IS NULL OR ch.vigencia_hasta >= CURDATE())
               )
             ORDER BY e.apellido_paterno"
        );
    }

    public function crear(array $data): array
    {
        $nombre = $data['nombre'] ?? '';
        $dia    = $data['dia'] ?? '';
        $hi     = $data['hora_inicio'] ?? '';
        $hf     = $data['hora_fin'] ?? '';

        if (!$nombre || !$dia || !$hi || !$hf) return ['error' => 'nombre, dia, hora_inicio y hora_fin son obligatorios'];

        $diasValidos = ['LUNES','MARTES','MIERCOLES','JUEVES','VIERNES','SABADO','DOMINGO'];
        if (!in_array($dia, $diasValidos)) return ['error' => 'Día inválido'];

        RawConnection::execute(
            "INSERT INTO rrhh_horario (nombre, dia, hora_inicio, hora_fin, activo) VALUES (:n, :d, :hi, :hf, 1)",
            [':n' => $nombre, ':d' => $dia, ':hi' => $hi, ':hf' => $hf]
        );

        return ['id' => (int) RawConnection::lastInsertId(), 'mensaje' => 'Horario creado'];
    }

    public function actualizar(int $id, array $data): array
    {
        $h = RawConnection::selectOne("SELECT id FROM rrhh_horario WHERE id = :id", [':id' => $id]);
        if (!$h) return ['error' => 'Horario no encontrado'];

        $sets = [];
        $params = [':id' => $id];
        foreach (['nombre', 'dia', 'hora_inicio', 'hora_fin', 'activo'] as $campo) {
            if (array_key_exists($campo, $data)) {
                $sets[] = "{$campo} = :{$campo}";
                $params[":{$campo}"] = $data[$campo];
            }
        }

        if (empty($sets)) return ['error' => 'No se proporcionaron campos'];
        RawConnection::execute("UPDATE rrhh_horario SET " . implode(', ', $sets) . " WHERE id = :id", $params);
        return ['id' => $id, 'mensaje' => 'Horario actualizado'];
    }

    public function desactivar(int $id): array
    {
        $h = RawConnection::selectOne("SELECT id FROM rrhh_horario WHERE id = :id", [':id' => $id]);
        if (!$h) return ['error' => 'Horario no encontrado'];

        RawConnection::execute("UPDATE rrhh_horario SET activo = 0 WHERE id = :id", [':id' => $id]);
        return ['id' => $id, 'mensaje' => 'Horario desactivado'];
    }

    public function asignar(array $data): array
    {
        $idContrato = (int) ($data['id_contrato'] ?? 0);
        $idHorario  = (int) ($data['id_horario'] ?? 0);
        $desde      = $data['vigencia_desde'] ?? date('Y-m-d');

        if (!$idContrato || !$idHorario) return ['error' => 'id_contrato e id_horario son obligatorios'];

        RawConnection::execute(
            "UPDATE rrhh_contrato_horario SET vigencia_hasta = :hasta
             WHERE id_contrato = :ct AND vigencia_hasta IS NULL",
            [':ct' => $idContrato, ':hasta' => $desde]
        );

        RawConnection::execute(
            "INSERT INTO rrhh_contrato_horario (id_contrato, id_horario, vigencia_desde)
             VALUES (:ct, :hr, :desde)",
            [':ct' => $idContrato, ':hr' => $idHorario, ':desde' => $desde]
        );

        return ['id' => (int) RawConnection::lastInsertId(), 'mensaje' => 'Horario asignado'];
    }

    public function resumenSemanal(int $idContrato, ?string $fecha = null): array
    {
        $horarios = $this->horarioPorContrato($idContrato, $fecha);

        $dias = [];
        $totalHoras = 0;
        foreach ($horarios as $h) {
            $inicio = strtotime($h['hora_inicio']);
            $fin = strtotime($h['hora_fin']);
            $horas = round(($fin - $inicio) / 3600, 2);

            if (!isset($dias[$h['dia']])) {
                $dias[$h['dia']] = ['dia' => $h['dia'], 'turnos' => [], 'total_horas' => 0];
            }
            $dias[$h['dia']]['turnos'][] = [
                'hora_inicio' => $h['hora_inicio'],
                'hora_fin'    => $h['hora_fin'],
                'horas'       => $horas,
            ];
            $dias[$h['dia']]['total_horas'] += $horas;
            $totalHoras += $horas;
        }

        return [
            'id_contrato' => $idContrato,
            'dias'        => array_values($dias),
            'total_horas_semana' => round($totalHoras, 2),
        ];
    }
}
