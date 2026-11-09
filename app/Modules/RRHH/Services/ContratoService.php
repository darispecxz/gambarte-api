<?php

namespace App\Modules\RRHH\Services;

use App\Modules\Shared\Database\RawConnection;

class ContratoService
{
    public function listar(?int $idEmpleado = null, ?string $estado = null): array
    {
        $sql = "SELECT c.*,
                       CONCAT(e.apellido_paterno, ' ', IFNULL(e.apellido_materno,''), ' ', e.nombres) AS empleado,
                       e.ci, CONVERT(ca.descripcion USING utf8mb4) AS cargo,
                       CONVERT(ag.descripcion USING utf8mb4) AS agencia
                FROM rrhh_contrato c
                JOIN rrhh_empleado e ON e.id = c.id_empleado
                LEFT JOIN cargo ca ON ca.id_cargo = c.id_cargo
                LEFT JOIN agencia ag ON ag.id_agencia = c.id_agencia
                WHERE 1=1";
        $params = [];

        if ($idEmpleado) {
            $sql .= " AND c.id_empleado = :emp";
            $params[':emp'] = $idEmpleado;
        }
        if ($estado) {
            $sql .= " AND c.estado = :est";
            $params[':est'] = $estado;
        }

        $sql .= " ORDER BY c.fecha_inicio DESC";
        return RawConnection::select($sql, $params);
    }

    public function detalle(int $id): ?array
    {
        return RawConnection::selectOne(
            "SELECT c.*,
                    CONCAT(e.apellido_paterno, ' ', IFNULL(e.apellido_materno,''), ' ', e.nombres) AS empleado,
                    e.ci, CONVERT(ca.descripcion USING utf8mb4) AS cargo,
                    CONVERT(ag.descripcion USING utf8mb4) AS agencia
             FROM rrhh_contrato c
             JOIN rrhh_empleado e ON e.id = c.id_empleado
             LEFT JOIN cargo ca ON ca.id_cargo = c.id_cargo
             LEFT JOIN agencia ag ON ag.id_agencia = c.id_agencia
             WHERE c.id = :id",
            [':id' => $id]
        );
    }

    public function crear(array $data): array
    {
        $idEmpleado   = (int) ($data['id_empleado'] ?? 0);
        $idCargo      = $data['id_cargo'] ?? null;
        $idAgencia    = $data['id_agencia'] ?? null;
        $tipoContrato = $data['tipo_contrato'] ?? 'INDEFINIDO';
        $fechaInicio  = $data['fecha_inicio'] ?? date('Y-m-d');
        $fechaFin     = $data['fecha_fin'] ?? null;
        $sueldoBase   = (float) ($data['sueldo_base'] ?? 0);
        $jornadaHoras = (float) ($data['jornada_horas'] ?? 8);
        $esProd       = (int) ($data['es_empresa_prod'] ?? 0);

        if (!$idEmpleado) return ['error' => 'id_empleado es obligatorio'];
        if ($sueldoBase <= 0) return ['error' => 'sueldo_base debe ser mayor a 0'];

        $vigente = RawConnection::selectOne(
            "SELECT id FROM rrhh_contrato WHERE id_empleado = :id AND estado = 'VIGENTE' LIMIT 1",
            [':id' => $idEmpleado]
        );
        if ($vigente) {
            return ['error' => 'El empleado ya tiene un contrato vigente (ID: ' . $vigente['id'] . '). Debe rescindirlo primero.'];
        }

        RawConnection::execute(
            "INSERT INTO rrhh_contrato (id_empleado, id_cargo, id_agencia, tipo_contrato, fecha_inicio, fecha_fin,
                                        sueldo_base, jornada_horas, es_empresa_prod, estado, created_at, updated_at)
             VALUES (:emp, :cargo, :ag, :tipo, :fi, :ff, :sueldo, :jornada, :prod, 'VIGENTE', NOW(), NOW())",
            [
                ':emp' => $idEmpleado, ':cargo' => $idCargo, ':ag' => $idAgencia,
                ':tipo' => $tipoContrato, ':fi' => $fechaInicio, ':ff' => $fechaFin,
                ':sueldo' => $sueldoBase, ':jornada' => $jornadaHoras, ':prod' => $esProd,
            ]
        );

        $id = (int) RawConnection::lastInsertId();
        return ['id' => $id, 'mensaje' => 'Contrato creado exitosamente'];
    }

    public function actualizar(int $id, array $data): array
    {
        $contrato = RawConnection::selectOne("SELECT * FROM rrhh_contrato WHERE id = :id", [':id' => $id]);
        if (!$contrato) return ['error' => 'Contrato no encontrado'];

        $sets = [];
        $params = [':id' => $id];

        $campos = ['id_cargo', 'id_agencia', 'tipo_contrato', 'fecha_inicio', 'fecha_fin',
                    'sueldo_base', 'jornada_horas', 'es_empresa_prod'];

        foreach ($campos as $campo) {
            if (array_key_exists($campo, $data)) {
                $sets[] = "{$campo} = :{$campo}";
                $params[":{$campo}"] = $data[$campo];
            }
        }

        if (empty($sets)) return ['error' => 'No se proporcionaron campos para actualizar'];

        $sets[] = "updated_at = NOW()";
        $sql = "UPDATE rrhh_contrato SET " . implode(', ', $sets) . " WHERE id = :id";
        RawConnection::execute($sql, $params);

        return ['id' => $id, 'mensaje' => 'Contrato actualizado exitosamente'];
    }

    public function rescindir(int $id, array $data): array
    {
        $contrato = RawConnection::selectOne("SELECT * FROM rrhh_contrato WHERE id = :id", [':id' => $id]);
        if (!$contrato) return ['error' => 'Contrato no encontrado'];
        if ($contrato['estado'] !== 'VIGENTE') return ['error' => 'Solo se pueden rescindir contratos vigentes'];

        $fechaFin = $data['fecha_fin'] ?? date('Y-m-d');

        RawConnection::execute(
            "UPDATE rrhh_contrato SET estado = 'RESCINDIDO', fecha_fin = :ff, updated_at = NOW() WHERE id = :id",
            [':id' => $id, ':ff' => $fechaFin]
        );

        return ['id' => $id, 'estado' => 'RESCINDIDO', 'fecha_fin' => $fechaFin];
    }
}
