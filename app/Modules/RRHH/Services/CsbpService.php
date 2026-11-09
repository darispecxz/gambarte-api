<?php

namespace App\Modules\RRHH\Services;

use App\Modules\Shared\Database\RawConnection;

class CsbpService
{
    public function altasPorContrato(int $idContrato): array
    {
        return RawConnection::select(
            "SELECT * FROM rrhh_csbp_alta WHERE id_contrato = :id ORDER BY fecha_alta DESC",
            [':id' => $idContrato]
        );
    }

    public function bajasPorContrato(int $idContrato): array
    {
        return RawConnection::select(
            "SELECT * FROM rrhh_csbp_baja WHERE id_contrato = :id ORDER BY fecha_baja DESC",
            [':id' => $idContrato]
        );
    }

    public function listarAltas(?string $estado = null): array
    {
        $sql = "SELECT a.*,
                       CONCAT(e.apellido_paterno, ' ', IFNULL(e.apellido_materno,''), ' ', e.nombres) AS empleado,
                       e.ci, ca.descripcion AS cargo, ag.descripcion AS agencia
                FROM rrhh_csbp_alta a
                JOIN rrhh_contrato c ON c.id = a.id_contrato
                JOIN rrhh_empleado e ON e.id = c.id_empleado
                LEFT JOIN cargo ca ON ca.id_cargo = c.id_cargo
                LEFT JOIN agencia ag ON ag.id_agencia = c.id_agencia
                WHERE 1=1";
        $params = [];

        if ($estado) {
            $sql .= " AND a.estado = :est";
            $params[':est'] = $estado;
        }

        $sql .= " ORDER BY a.created_at DESC";
        return RawConnection::select($sql, $params);
    }

    public function listarBajas(?string $estado = null): array
    {
        $sql = "SELECT b.*,
                       CONCAT(e.apellido_paterno, ' ', IFNULL(e.apellido_materno,''), ' ', e.nombres) AS empleado,
                       e.ci, ca.descripcion AS cargo, ag.descripcion AS agencia
                FROM rrhh_csbp_baja b
                JOIN rrhh_contrato c ON c.id = b.id_contrato
                JOIN rrhh_empleado e ON e.id = c.id_empleado
                LEFT JOIN cargo ca ON ca.id_cargo = c.id_cargo
                LEFT JOIN agencia ag ON ag.id_agencia = c.id_agencia
                WHERE 1=1";
        $params = [];

        if ($estado) {
            $sql .= " AND b.estado = :est";
            $params[':est'] = $estado;
        }

        $sql .= " ORDER BY b.created_at DESC";
        return RawConnection::select($sql, $params);
    }

    public function aportes(int $gestion): array
    {
        return RawConnection::select(
            "SELECT * FROM rrhh_csbp_aporte
             WHERE gestion = :g
             ORDER BY mes DESC",
            [':g' => $gestion]
        );
    }

    public function aporteDetalle(int $id): ?array
    {
        return RawConnection::selectOne(
            "SELECT * FROM rrhh_csbp_aporte WHERE id = :id",
            [':id' => $id]
        );
    }

    public function resumenAnual(int $gestion): array
    {
        $aportes = $this->aportes($gestion);
        $totalAportado = 0;
        $totalMultas = 0;
        $totalCancelado = 0;
        $mesesPresentados = 0;

        foreach ($aportes as $a) {
            $totalAportado += (float) $a['total_aporte'];
            $totalMultas += (float) $a['multas'] + (float) $a['intereses'];
            $totalCancelado += (float) $a['total_cancelado'];
            if ($a['estado'] !== 'PENDIENTE') $mesesPresentados++;
        }

        return [
            'gestion'            => $gestion,
            'total_aportado'     => round($totalAportado, 2),
            'total_multas'       => round($totalMultas, 2),
            'total_cancelado'    => round($totalCancelado, 2),
            'meses_presentados'  => $mesesPresentados,
            'meses_pendientes'   => 12 - $mesesPresentados,
            'detalle'            => $aportes,
        ];
    }

    public function crearAlta(array $data): array
    {
        $idContrato = (int) ($data['id_contrato'] ?? 0);
        $fechaAlta  = $data['fecha_alta'] ?? date('Y-m-d');
        $fechaEfect = $data['fecha_efectiva'] ?? null;
        $nroAseg    = $data['nro_asegurado'] ?? null;
        $condicion  = $data['condicion'] ?? 'VIGENTE';

        if (!$idContrato) return ['error' => 'id_contrato es obligatorio'];

        RawConnection::execute(
            "INSERT INTO rrhh_csbp_alta (id_contrato, fecha_alta, fecha_efectiva, nro_asegurado, estado, condicion)
             VALUES (:ct, :fa, :fe, :na, 'PENDIENTE', :cond)",
            [':ct' => $idContrato, ':fa' => $fechaAlta, ':fe' => $fechaEfect, ':na' => $nroAseg, ':cond' => $condicion]
        );

        return ['id' => (int) RawConnection::lastInsertId(), 'mensaje' => 'Alta CSBP registrada'];
    }

    public function actualizarAlta(int $id, array $data): array
    {
        $alta = RawConnection::selectOne("SELECT id FROM rrhh_csbp_alta WHERE id = :id", [':id' => $id]);
        if (!$alta) return ['error' => 'Alta no encontrada'];

        $sets = [];
        $params = [':id' => $id];
        foreach (['fecha_alta', 'fecha_efectiva', 'nro_asegurado', 'estado', 'condicion'] as $campo) {
            if (array_key_exists($campo, $data)) {
                $sets[] = "{$campo} = :{$campo}";
                $params[":{$campo}"] = $data[$campo];
            }
        }

        if (empty($sets)) return ['error' => 'No se proporcionaron campos'];
        $sets[] = "updated_at = NOW()";
        RawConnection::execute("UPDATE rrhh_csbp_alta SET " . implode(', ', $sets) . " WHERE id = :id", $params);
        return ['id' => $id, 'mensaje' => 'Alta actualizada'];
    }

    public function crearBaja(array $data): array
    {
        $idContrato = (int) ($data['id_contrato'] ?? 0);
        $fechaBaja  = $data['fecha_baja'] ?? date('Y-m-d');
        $motivo     = $data['motivo'] ?? null;

        if (!$idContrato) return ['error' => 'id_contrato es obligatorio'];

        RawConnection::execute(
            "INSERT INTO rrhh_csbp_baja (id_contrato, fecha_baja, motivo, estado)
             VALUES (:ct, :fb, :mot, 'PENDIENTE')",
            [':ct' => $idContrato, ':fb' => $fechaBaja, ':mot' => $motivo]
        );

        return ['id' => (int) RawConnection::lastInsertId(), 'mensaje' => 'Baja CSBP registrada'];
    }

    public function crearAporte(array $data): array
    {
        $gestion = (int) ($data['gestion'] ?? 0);
        $mes     = (int) ($data['mes'] ?? 0);
        $regional = $data['regional'] ?? 'La Paz';
        $total   = (float) ($data['total_aporte'] ?? 0);

        if (!$gestion || !$mes) return ['error' => 'gestion y mes son obligatorios'];

        RawConnection::execute(
            "INSERT INTO rrhh_csbp_aporte (gestion, mes, regional, fecha_presentacion, total_aporte, total_cancelado, nro_empleados, estado)
             VALUES (:g, :m, :r, :fp, :ta, :tc, :ne, :est)
             ON DUPLICATE KEY UPDATE total_aporte = VALUES(total_aporte), total_cancelado = VALUES(total_cancelado),
                                     nro_empleados = VALUES(nro_empleados), estado = VALUES(estado)",
            [':g' => $gestion, ':m' => $mes, ':r' => $regional,
             ':fp' => $data['fecha_presentacion'] ?? date('Y-m-d'),
             ':ta' => $total, ':tc' => (float) ($data['total_cancelado'] ?? $total),
             ':ne' => (int) ($data['nro_empleados'] ?? 0), ':est' => $data['estado'] ?? 'PENDIENTE']
        );

        return ['mensaje' => 'Aporte CSBP registrado'];
    }

    public function dashboardCsbp(): array
    {
        $altasPendientes = (int) RawConnection::scalar(
            "SELECT COUNT(*) FROM rrhh_csbp_alta WHERE estado IN ('PENDIENTE','EN_TRAMITE')"
        );
        $bajasPendientes = (int) RawConnection::scalar(
            "SELECT COUNT(*) FROM rrhh_csbp_baja WHERE estado IN ('PENDIENTE','EN_TRAMITE')"
        );
        $totalAsegurados = (int) RawConnection::scalar(
            "SELECT COUNT(DISTINCT c.id_empleado)
             FROM rrhh_csbp_alta a
             JOIN rrhh_contrato c ON c.id = a.id_contrato
             WHERE a.estado = 'COMPLETADO'
               AND NOT EXISTS (
                   SELECT 1 FROM rrhh_csbp_baja b
                   WHERE b.id_contrato = a.id_contrato AND b.estado = 'COMPLETADO'
               )"
        );

        return [
            'altas_pendientes'  => $altasPendientes,
            'bajas_pendientes'  => $bajasPendientes,
            'total_asegurados'  => $totalAsegurados,
        ];
    }
}
