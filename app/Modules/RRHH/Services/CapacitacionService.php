<?php

namespace App\Modules\RRHH\Services;

use App\Modules\Shared\Database\RawConnection;

class CapacitacionService
{
    public function listar(?string $tipo = null, ?string $estado = null): array
    {
        $sql = "SELECT c.*, COUNT(ce.id) AS inscritos,
                       SUM(CASE WHEN ce.estado = 'APROBADO' THEN 1 ELSE 0 END) AS aprobados
                FROM rrhh_capacitacion c
                LEFT JOIN rrhh_capacitacion_empleado ce ON ce.id_capacitacion = c.id
                WHERE 1=1";
        $params = [];

        if ($tipo) {
            $sql .= " AND c.tipo = :tipo";
            $params[':tipo'] = $tipo;
        }
        if ($estado) {
            $sql .= " AND c.estado = :est";
            $params[':est'] = $estado;
        }

        $sql .= " GROUP BY c.id ORDER BY c.fecha_inicio DESC";
        return RawConnection::select($sql, $params);
    }

    public function detalle(int $id): ?array
    {
        $cap = RawConnection::selectOne(
            "SELECT * FROM rrhh_capacitacion WHERE id = :id",
            [':id' => $id]
        );
        if (!$cap) return null;

        $participantes = RawConnection::select(
            "SELECT ce.*,
                    CONCAT(e.apellido_paterno, ' ', IFNULL(e.apellido_materno,''), ' ', e.nombres) AS empleado,
                    e.ci, ca.descripcion AS cargo
             FROM rrhh_capacitacion_empleado ce
             JOIN rrhh_empleado e ON e.id = ce.id_empleado
             LEFT JOIN rrhh_contrato ct ON ct.id_empleado = e.id AND ct.estado = 'VIGENTE'
             LEFT JOIN cargo ca ON ca.id_cargo = ct.id_cargo
             WHERE ce.id_capacitacion = :id
             ORDER BY e.apellido_paterno",
            [':id' => $id]
        );

        return [
            'capacitacion'  => $cap,
            'participantes' => $participantes,
        ];
    }

    public function crear(array $data): array
    {
        $titulo   = $data['titulo'] ?? '';
        $tipo     = $data['tipo'] ?? 'DESARROLLO';
        $fi       = $data['fecha_inicio'] ?? '';
        $ff       = $data['fecha_fin'] ?? '';
        $horas    = (int) ($data['horas_duracion'] ?? 0);
        $oblig    = (int) ($data['es_obligatoria'] ?? 0);
        $estado   = $data['estado'] ?? 'PROGRAMADA';

        if (!$titulo || !$fi || !$ff) return ['error' => 'titulo, fecha_inicio y fecha_fin son obligatorios'];

        RawConnection::execute(
            "INSERT INTO rrhh_capacitacion (titulo, tipo, fecha_inicio, fecha_fin, horas_duracion, es_obligatoria, estado)
             VALUES (:t, :tipo, :fi, :ff, :h, :ob, :est)",
            [':t' => $titulo, ':tipo' => $tipo, ':fi' => $fi, ':ff' => $ff, ':h' => $horas, ':ob' => $oblig, ':est' => $estado]
        );

        return ['id' => (int) RawConnection::lastInsertId(), 'mensaje' => 'Capacitación creada exitosamente'];
    }

    public function actualizar(int $id, array $data): array
    {
        $cap = RawConnection::selectOne("SELECT id FROM rrhh_capacitacion WHERE id = :id", [':id' => $id]);
        if (!$cap) return ['error' => 'Capacitación no encontrada'];

        $sets = [];
        $params = [':id' => $id];
        foreach (['titulo', 'tipo', 'fecha_inicio', 'fecha_fin', 'horas_duracion', 'es_obligatoria', 'estado'] as $campo) {
            if (array_key_exists($campo, $data)) {
                $sets[] = "{$campo} = :{$campo}";
                $params[":{$campo}"] = $data[$campo];
            }
        }

        if (empty($sets)) return ['error' => 'No se proporcionaron campos para actualizar'];
        RawConnection::execute("UPDATE rrhh_capacitacion SET " . implode(', ', $sets) . " WHERE id = :id", $params);
        return ['id' => $id, 'mensaje' => 'Capacitación actualizada'];
    }

    public function inscribir(int $idCapacitacion, int $idEmpleado): array
    {
        $existe = RawConnection::selectOne(
            "SELECT id FROM rrhh_capacitacion_empleado WHERE id_capacitacion = :cap AND id_empleado = :emp",
            [':cap' => $idCapacitacion, ':emp' => $idEmpleado]
        );
        if ($existe) return ['error' => 'El empleado ya está inscrito en esta capacitación'];

        RawConnection::execute(
            "INSERT INTO rrhh_capacitacion_empleado (id_empleado, id_capacitacion, estado) VALUES (:emp, :cap, 'INSCRITO')",
            [':emp' => $idEmpleado, ':cap' => $idCapacitacion]
        );

        return ['id' => (int) RawConnection::lastInsertId(), 'mensaje' => 'Empleado inscrito exitosamente'];
    }

    public function calificar(int $idCapacitacion, int $idEmpleado, array $data): array
    {
        $registro = RawConnection::selectOne(
            "SELECT id FROM rrhh_capacitacion_empleado WHERE id_capacitacion = :cap AND id_empleado = :emp",
            [':cap' => $idCapacitacion, ':emp' => $idEmpleado]
        );
        if (!$registro) return ['error' => 'El empleado no está inscrito en esta capacitación'];

        $nota = $data['nota'] ?? null;
        $estado = $data['estado'] ?? ($nota !== null && $nota >= 51 ? 'APROBADO' : 'REPROBADO');

        RawConnection::execute(
            "UPDATE rrhh_capacitacion_empleado SET nota = :nota, estado = :est WHERE id_capacitacion = :cap AND id_empleado = :emp",
            [':nota' => $nota, ':est' => $estado, ':cap' => $idCapacitacion, ':emp' => $idEmpleado]
        );

        return ['mensaje' => 'Calificación registrada', 'estado' => $estado, 'nota' => $nota];
    }

    public function cumplimiento(): array
    {
        return RawConnection::select(
            "SELECT c.id, c.titulo, c.tipo, c.fecha_inicio, c.fecha_fin, c.estado,
                    COUNT(ce.id) AS inscritos,
                    SUM(CASE WHEN ce.estado = 'APROBADO' THEN 1 ELSE 0 END) AS aprobados,
                    (SELECT COUNT(*) FROM rrhh_empleado e2 WHERE e2.estado = 'A') AS total_activos
             FROM rrhh_capacitacion c
             LEFT JOIN rrhh_capacitacion_empleado ce ON ce.id_capacitacion = c.id
             WHERE c.es_obligatoria = 1
             GROUP BY c.id
             ORDER BY c.fecha_inicio DESC",
            []
        );
    }
}
