<?php

namespace App\Modules\RRHH\Services;

use App\Modules\Shared\Database\RawConnection;

class EmpleadoService
{
    public function listar(?string $estado = null, ?int $agencia = null, ?string $buscar = null): array
    {
        $sql = "
            SELECT e.id, e.cod_trab, e.ci, e.ci_expedido, e.nombres,
                   e.apellido_paterno, e.apellido_materno, e.fecha_nacimiento,
                   e.genero, e.estado_civil, e.telefono, e.email_personal,
                   e.nua_cua, e.nro_asegurado_cns, e.estado, e.foto_url,
                   e.cargo, e.area, e.agencia, e.id_agencia,
                   c.id AS id_contrato, c.tipo_contrato, c.fecha_inicio,
                   c.fecha_fin, c.sueldo_base, c.estado AS estado_contrato
            FROM rrhh_empleado e
            LEFT JOIN rrhh_contrato c ON c.id_empleado = e.id AND c.estado = 'VIGENTE'
            WHERE 1=1
        ";
        $params = [];

        if ($estado) {
            $sql .= " AND e.estado = :estado";
            $params[':estado'] = $estado;
        }
        if ($agencia) {
            $sql .= " AND e.id_agencia = :agencia";
            $params[':agencia'] = $agencia;
        }
        if ($buscar) {
            $sql .= " AND (e.nombres LIKE :b OR e.apellido_paterno LIKE :b2 OR e.ci LIKE :b3 OR e.apellido_materno LIKE :b4)";
            $params[':b'] = "%{$buscar}%";
            $params[':b2'] = "%{$buscar}%";
            $params[':b3'] = "%{$buscar}%";
            $params[':b4'] = "%{$buscar}%";
        }

        $sql .= " ORDER BY e.apellido_paterno, e.apellido_materno, e.nombres";

        return RawConnection::select($sql, $params);
    }

    public function detalle(int $id): ?array
    {
        $empleado = RawConnection::selectOne(
            "SELECT * FROM rrhh_empleado WHERE id = :id",
            [':id' => $id]
        );
        if (!$empleado) return null;

        $contratos = RawConnection::select(
            "SELECT c.*, CONVERT(ca.descripcion USING utf8mb4) AS cargo, CONVERT(a.descripcion USING utf8mb4) AS agencia
             FROM rrhh_contrato c
             LEFT JOIN cargo ca ON ca.id_cargo = c.id_cargo
             LEFT JOIN agencia a ON a.id_agencia = c.id_agencia
             WHERE c.id_empleado = :id ORDER BY c.fecha_inicio DESC",
            [':id' => $id]
        );

        $vacaciones = RawConnection::select(
            "SELECT * FROM rrhh_vacacion WHERE id_empleado = :id ORDER BY fecha_inicio DESC LIMIT 20",
            [':id' => $id]
        );

        $capacitaciones = RawConnection::select(
            "SELECT ce.*, c.titulo, c.tipo, c.fecha_inicio, c.fecha_fin, c.es_obligatoria
             FROM rrhh_capacitacion_empleado ce
             JOIN rrhh_capacitacion c ON c.id = ce.id_capacitacion
             WHERE ce.id_empleado = :id ORDER BY c.fecha_inicio DESC",
            [':id' => $id]
        );

        $evaluaciones = RawConnection::select(
            "SELECT * FROM rrhh_evaluacion WHERE id_empleado = :id ORDER BY periodo DESC",
            [':id' => $id]
        );

        $roles = RawConnection::select("
            SELECT CONVERT(r.descripcion USING utf8mb4) AS descripcion, CONVERT(r.abreviatura USING utf8mb4) AS abreviatura
            FROM rol_usuario ru
            JOIN rol r ON r.id_rol = ru.id_rol
            JOIN usuario u ON u.cod_usuario = ru.cod_usuario
            WHERE u.cod_trab = :id AND ru.est_rol_usu = 'A'
        ", [':id' => $id]);

        $empleado['roles'] = $roles;

        return [
            'empleado'       => $empleado,
            'contratos'      => $contratos,
            'vacaciones'     => $vacaciones,
            'capacitaciones' => $capacitaciones,
            'evaluaciones'   => $evaluaciones,
        ];
    }

    public function dashboard(?int $agencia = null): array
    {
        $where = $agencia ? "AND e.id_agencia = :ag" : "";
        $params = $agencia ? [':ag' => $agencia] : [];

        $totalActivos = (int) RawConnection::scalar(
            "SELECT COUNT(*) FROM rrhh_empleado e
             WHERE e.estado = 'A' {$where}",
            $params
        );

        $porTipo = RawConnection::select(
            "SELECT COALESCE(c.tipo_contrato, 'INDEFINIDO') AS tipo_contrato, COUNT(*) AS total
             FROM rrhh_empleado e
             LEFT JOIN rrhh_contrato c ON c.id_empleado = e.id AND c.estado = 'VIGENTE'
             WHERE e.estado = 'A' {$where}
             GROUP BY COALESCE(c.tipo_contrato, 'INDEFINIDO')",
            $params
        );

        $porArea = RawConnection::select(
            "SELECT e.area, COUNT(*) AS total
             FROM rrhh_empleado e
             WHERE e.estado = 'A' {$where}
             GROUP BY e.area ORDER BY total DESC",
            $params
        );

        $contratosProxVencer = RawConnection::select(
            "SELECT c.id, c.fecha_fin, c.tipo_contrato,
                    CONCAT(e.apellido_paterno, ' ', IFNULL(e.apellido_materno,''), ' ', e.nombres) AS empleado,
                    e.cargo, e.agencia,
                    DATEDIFF(c.fecha_fin, CURDATE()) AS dias_restantes
             FROM rrhh_contrato c
             JOIN rrhh_empleado e ON e.id = c.id_empleado
             WHERE c.estado = 'VIGENTE' AND c.fecha_fin IS NOT NULL
               AND c.fecha_fin BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 60 DAY)
               {$where}
             ORDER BY c.fecha_fin",
            $params
        );

        $vacacionesPendientes = RawConnection::select(
            "SELECT v.id, v.tipo, v.fecha_inicio, v.fecha_fin, v.dias, v.estado,
                    v.observaciones, v.created_at,
                    CONCAT(e.apellido_paterno, ' ', IFNULL(e.apellido_materno,''), ' ', e.nombres) AS empleado
             FROM rrhh_vacacion v
             JOIN rrhh_empleado e ON e.id = v.id_empleado
             WHERE v.estado = 'SOLICITADA'
             ORDER BY v.created_at DESC LIMIT 10",
            []
        );

        $capPendientes = (int) RawConnection::scalar(
            "SELECT COUNT(*) FROM rrhh_capacitacion
             WHERE es_obligatoria = 1 AND estado IN ('PROGRAMADA','EN_CURSO')",
            []
        );

        return [
            'total_activos'           => $totalActivos,
            'por_tipo_contrato'       => $porTipo,
            'por_area'                => $porArea,
            'contratos_prox_vencer'   => $contratosProxVencer,
            'vacaciones_pendientes'   => $vacacionesPendientes,
            'capacitaciones_obligatorias_pendientes' => $capPendientes,
        ];
    }

    public function saldoVacaciones(int $idEmpleado, int $gestion): array
    {
        $contrato = RawConnection::selectOne(
            "SELECT c.fecha_inicio FROM rrhh_contrato c
             WHERE c.id_empleado = :id AND c.estado = 'VIGENTE' LIMIT 1",
            [':id' => $idEmpleado]
        );

        if (!$contrato) {
            $trab = RawConnection::selectOne(
                "SELECT fec_ingreso FROM trabajador WHERE cod_trab = :id",
                [':id' => $idEmpleado]
            );
            if (!$trab) return ['error' => 'Empleado no encontrado'];
            $fechaInicio = $trab['fec_ingreso'];
        } else {
            $fechaInicio = $contrato['fecha_inicio'];
        }

        $anios = (int) floor((strtotime("{$gestion}-12-31") - strtotime($fechaInicio)) / (365.25 * 86400));
        if ($anios < 0) $anios = 0;

        $escala = RawConnection::selectOne(
            "SELECT dias_habiles FROM rrhh_escala_vacaciones
             WHERE gestion = :g AND anios_desde <= :a AND (anios_hasta >= :a2 OR anios_hasta IS NULL)",
            [':g' => $gestion, ':a' => $anios, ':a2' => $anios]
        );

        $diasCorresponde = $escala ? (int) $escala['dias_habiles'] : 15;

        $diasUsados = (int) RawConnection::scalar(
            "SELECT COALESCE(SUM(dias), 0) FROM rrhh_vacacion
             WHERE id_empleado = :id AND tipo = 'VACACION'
               AND estado IN ('APROBADA','GOZADA')
               AND YEAR(fecha_inicio) = :g",
            [':id' => $idEmpleado, ':g' => $gestion]
        );

        return [
            'antiguedad_anios'  => $anios,
            'dias_corresponde'  => $diasCorresponde,
            'dias_usados'       => $diasUsados,
            'saldo'             => $diasCorresponde - $diasUsados,
        ];
    }
}
