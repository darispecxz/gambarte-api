<?php

namespace App\Modules\RRHH\Services;

use App\Modules\Shared\Database\RawConnection;

class EvaluacionService
{
    public function listar(?string $periodo = null, ?int $idEmpleado = null): array
    {
        $sql = "SELECT ev.*,
                       CONCAT(e.apellido_paterno, ' ', IFNULL(e.apellido_materno,''), ' ', e.nombres) AS empleado,
                       e.ci, e.cargo, e.area, e.agencia
                FROM rrhh_evaluacion ev
                JOIN rrhh_empleado e ON e.id = ev.id_empleado
                WHERE 1=1";
        $params = [];

        if ($periodo) {
            $sql .= " AND ev.periodo = :per";
            $params[':per'] = $periodo;
        }
        if ($idEmpleado) {
            $sql .= " AND ev.id_empleado = :emp";
            $params[':emp'] = $idEmpleado;
        }

        $sql .= " ORDER BY ev.periodo DESC, e.apellido_paterno";
        return RawConnection::select($sql, $params);
    }

    public function detalle(int $id): ?array
    {
        return RawConnection::selectOne(
            "SELECT ev.*,
                    CONCAT(e.apellido_paterno, ' ', IFNULL(e.apellido_materno,''), ' ', e.nombres) AS empleado,
                    e.ci, e.cargo, e.area, e.agencia
             FROM rrhh_evaluacion ev
             JOIN rrhh_empleado e ON e.id = ev.id_empleado
             WHERE ev.id = :id",
            [':id' => $id]
        );
    }

    public function crear(array $data): array
    {
        $idEmpleado    = (int) ($data['id_empleado'] ?? 0);
        $periodo       = $data['periodo'] ?? '';
        $puntaje       = $data['puntaje'] ?? null;
        $categoria     = $data['categoria'] ?? null;
        $estado        = $data['estado'] ?? 'PENDIENTE';
        $observaciones = $data['observaciones'] ?? null;

        if (!$idEmpleado || !$periodo) return ['error' => 'id_empleado y periodo son obligatorios'];

        if ($puntaje !== null && !$categoria) {
            $p = (float) $puntaje;
            if ($p >= 90) $categoria = 'EXCELENTE';
            elseif ($p >= 75) $categoria = 'BUENO';
            elseif ($p >= 60) $categoria = 'REGULAR';
            else $categoria = 'DEFICIENTE';
        }

        RawConnection::execute(
            "INSERT INTO rrhh_evaluacion (id_empleado, periodo, puntaje, categoria, estado, observaciones)
             VALUES (:emp, :per, :pts, :cat, :est, :obs)",
            [':emp' => $idEmpleado, ':per' => $periodo, ':pts' => $puntaje,
             ':cat' => $categoria, ':est' => $estado, ':obs' => $observaciones]
        );

        return ['id' => (int) RawConnection::lastInsertId(), 'mensaje' => 'Evaluación creada'];
    }

    public function actualizar(int $id, array $data): array
    {
        $ev = RawConnection::selectOne("SELECT id FROM rrhh_evaluacion WHERE id = :id", [':id' => $id]);
        if (!$ev) return ['error' => 'Evaluación no encontrada'];

        $sets = [];
        $params = [':id' => $id];
        foreach (['puntaje', 'categoria', 'estado', 'observaciones'] as $campo) {
            if (array_key_exists($campo, $data)) {
                $sets[] = "{$campo} = :{$campo}";
                $params[":{$campo}"] = $data[$campo];
            }
        }

        if (isset($data['puntaje']) && !isset($data['categoria'])) {
            $p = (float) $data['puntaje'];
            $cat = $p >= 90 ? 'EXCELENTE' : ($p >= 75 ? 'BUENO' : ($p >= 60 ? 'REGULAR' : 'DEFICIENTE'));
            $sets[] = "categoria = :categoria";
            $params[':categoria'] = $cat;
        }

        if (empty($sets)) return ['error' => 'No se proporcionaron campos'];
        RawConnection::execute("UPDATE rrhh_evaluacion SET " . implode(', ', $sets) . " WHERE id = :id", $params);
        return ['id' => $id, 'mensaje' => 'Evaluación actualizada'];
    }
}
