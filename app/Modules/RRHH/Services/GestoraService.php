<?php

namespace App\Modules\RRHH\Services;

use App\Modules\Shared\Database\RawConnection;

class GestoraService
{
    public function gestoraPorContrato(int $idContrato): ?array
    {
        return RawConnection::selectOne(
            "SELECT * FROM rrhh_gestora WHERE id_contrato = :id AND estado = 'ACTIVO' LIMIT 1",
            [':id' => $idContrato]
        );
    }

    public function listarGestoras(?string $estado = null): array
    {
        $sql = "SELECT g.*,
                       CONCAT(e.apellido_paterno, ' ', IFNULL(e.apellido_materno,''), ' ', e.nombres) AS empleado,
                       e.ci, ca.descripcion AS cargo, ag.descripcion AS agencia
                FROM rrhh_gestora g
                JOIN rrhh_contrato c ON c.id = g.id_contrato
                JOIN rrhh_empleado e ON e.id = c.id_empleado
                LEFT JOIN cargo ca ON ca.id_cargo = c.id_cargo
                LEFT JOIN agencia ag ON ag.id_agencia = c.id_agencia
                WHERE 1=1";
        $params = [];

        if ($estado) {
            $sql .= " AND g.estado = :est";
            $params[':est'] = $estado;
        }

        $sql .= " ORDER BY e.apellido_paterno, e.nombres";
        return RawConnection::select($sql, $params);
    }

    public function contribuciones(int $gestion, ?string $gestora = null): array
    {
        $sql = "SELECT * FROM rrhh_contribucion_afp WHERE gestion = :g";
        $params = [':g' => $gestion];

        if ($gestora) {
            $sql .= " AND nombre_gestora = :gest";
            $params[':gest'] = $gestora;
        }

        $sql .= " ORDER BY mes DESC";
        return RawConnection::select($sql, $params);
    }

    public function contribucionDetalle(int $id): ?array
    {
        return RawConnection::selectOne(
            "SELECT * FROM rrhh_contribucion_afp WHERE id = :id",
            [':id' => $id]
        );
    }

    public function resumenContribuciones(int $gestion): array
    {
        $contribs = $this->contribuciones($gestion);

        $porGestora = [];
        foreach ($contribs as $c) {
            $nombre = $c['nombre_gestora'];
            if (!isset($porGestora[$nombre])) {
                $porGestora[$nombre] = [
                    'gestora'         => $nombre,
                    'total_laboral'   => 0,
                    'total_patronal'  => 0,
                    'total'           => 0,
                    'meses_pagados'   => 0,
                ];
            }
            $porGestora[$nombre]['total_laboral']  += (float) $c['total_aporte_laboral'];
            $porGestora[$nombre]['total_patronal'] += (float) $c['total_aporte_patronal'];
            $porGestora[$nombre]['total']          += (float) $c['total_contribucion'];
            if ($c['estado'] === 'PAGADO') $porGestora[$nombre]['meses_pagados']++;
        }

        $totalLaboral = $totalPatronal = $totalGeneral = 0;
        foreach ($porGestora as &$g) {
            $g['total_laboral']  = round($g['total_laboral'], 2);
            $g['total_patronal'] = round($g['total_patronal'], 2);
            $g['total']          = round($g['total'], 2);
            $totalLaboral  += $g['total_laboral'];
            $totalPatronal += $g['total_patronal'];
            $totalGeneral  += $g['total'];
        }

        return [
            'gestion'         => $gestion,
            'total_laboral'   => round($totalLaboral, 2),
            'total_patronal'  => round($totalPatronal, 2),
            'total_general'   => round($totalGeneral, 2),
            'por_gestora'     => array_values($porGestora),
            'detalle'         => $contribs,
        ];
    }

    public function crear(array $data): array
    {
        $idContrato = (int) ($data['id_contrato'] ?? 0);
        $nombre     = $data['nombre_gestora'] ?? '';
        $nuaCua     = $data['nua_cua'] ?? '';
        $fechaAfil  = $data['fecha_afiliacion'] ?? date('Y-m-d');

        if (!$idContrato || !$nombre) return ['error' => 'id_contrato y nombre_gestora son obligatorios'];

        $activa = RawConnection::selectOne(
            "SELECT id FROM rrhh_gestora WHERE id_contrato = :id AND estado = 'ACTIVO'",
            [':id' => $idContrato]
        );
        if ($activa) {
            RawConnection::execute("UPDATE rrhh_gestora SET estado = 'INACTIVO', updated_at = NOW() WHERE id = :id", [':id' => $activa['id']]);
        }

        RawConnection::execute(
            "INSERT INTO rrhh_gestora (id_contrato, nombre_gestora, nua_cua, fecha_afiliacion, estado)
             VALUES (:ct, :nom, :nua, :fa, 'ACTIVO')",
            [':ct' => $idContrato, ':nom' => $nombre, ':nua' => $nuaCua, ':fa' => $fechaAfil]
        );

        return ['id' => (int) RawConnection::lastInsertId(), 'mensaje' => 'Gestora asignada'];
    }

    public function actualizar(int $id, array $data): array
    {
        $gest = RawConnection::selectOne("SELECT id FROM rrhh_gestora WHERE id = :id", [':id' => $id]);
        if (!$gest) return ['error' => 'Gestora no encontrada'];

        $sets = [];
        $params = [':id' => $id];
        foreach (['nombre_gestora', 'nua_cua', 'fecha_afiliacion', 'estado'] as $campo) {
            if (array_key_exists($campo, $data)) {
                $sets[] = "{$campo} = :{$campo}";
                $params[":{$campo}"] = $data[$campo];
            }
        }

        if (empty($sets)) return ['error' => 'No se proporcionaron campos'];
        $sets[] = "updated_at = NOW()";
        RawConnection::execute("UPDATE rrhh_gestora SET " . implode(', ', $sets) . " WHERE id = :id", $params);
        return ['id' => $id, 'mensaje' => 'Gestora actualizada'];
    }

    public function crearContribucion(array $data): array
    {
        $gestion = (int) ($data['gestion'] ?? 0);
        $mes     = (int) ($data['mes'] ?? 0);
        $gestora = $data['nombre_gestora'] ?? '';

        if (!$gestion || !$mes || !$gestora) return ['error' => 'gestion, mes y nombre_gestora son obligatorios'];

        RawConnection::execute(
            "INSERT INTO rrhh_contribucion_afp (gestion, mes, nombre_gestora, total_aporte_laboral, total_aporte_patronal,
                                                total_contribucion, nro_asegurados, estado)
             VALUES (:g, :m, :gest, :lab, :pat, :tot, :n, :est)
             ON DUPLICATE KEY UPDATE total_aporte_laboral = VALUES(total_aporte_laboral),
                                     total_aporte_patronal = VALUES(total_aporte_patronal),
                                     total_contribucion = VALUES(total_contribucion),
                                     nro_asegurados = VALUES(nro_asegurados), estado = VALUES(estado)",
            [':g' => $gestion, ':m' => $mes, ':gest' => $gestora,
             ':lab' => (float) ($data['total_aporte_laboral'] ?? 0),
             ':pat' => (float) ($data['total_aporte_patronal'] ?? 0),
             ':tot' => (float) ($data['total_contribucion'] ?? 0),
             ':n' => (int) ($data['nro_asegurados'] ?? 0),
             ':est' => $data['estado'] ?? 'PENDIENTE']
        );

        return ['mensaje' => 'Contribución AFP registrada'];
    }

    public function empleadosSinGestora(): array
    {
        return RawConnection::select(
            "SELECT e.id, CONCAT(e.apellido_paterno, ' ', IFNULL(e.apellido_materno,''), ' ', e.nombres) AS empleado,
                    e.ci, ca.descripcion AS cargo
             FROM rrhh_empleado e
             JOIN rrhh_contrato c ON c.id_empleado = e.id AND c.estado = 'VIGENTE'
             LEFT JOIN cargo ca ON ca.id_cargo = c.id_cargo
             WHERE e.estado = 'A'
               AND NOT EXISTS (
                   SELECT 1 FROM rrhh_gestora g WHERE g.id_contrato = c.id AND g.estado = 'ACTIVO'
               )
             ORDER BY e.apellido_paterno"
        );
    }
}
