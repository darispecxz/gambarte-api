<?php

namespace App\Modules\RRHH\Services;

use App\Modules\Shared\Database\RawConnection;

class ParametrosService
{
    public function listar(int $gestion): array
    {
        return RawConnection::select(
            "SELECT id, gestion, codigo, valor, descripcion, norma_referencia,
                    vigente_desde, vigente_hasta, created_at, updated_at
             FROM rrhh_parametro_gestion
             WHERE gestion = :g
             ORDER BY codigo",
            [':g' => $gestion]
        );
    }

    public function obtener(int $gestion, string $codigo): ?array
    {
        return RawConnection::selectOne(
            "SELECT * FROM rrhh_parametro_gestion WHERE gestion = :g AND codigo = :c",
            [':g' => $gestion, ':c' => $codigo]
        );
    }

    public function valor(int $gestion, string $codigo): float
    {
        $v = RawConnection::scalar(
            "SELECT valor FROM rrhh_parametro_gestion WHERE gestion = :g AND codigo = :c",
            [':g' => $gestion, ':c' => $codigo]
        );
        if ($v === null) {
            throw new \RuntimeException("Parámetro '{$codigo}' no encontrado para gestión {$gestion}");
        }
        return (float) $v;
    }

    public function escalaBono(int $gestion): array
    {
        return RawConnection::select(
            "SELECT id, gestion, anios_desde, anios_hasta, porcentaje
             FROM rrhh_escala_bono_antiguedad
             WHERE gestion = :g ORDER BY anios_desde",
            [':g' => $gestion]
        );
    }

    public function escalaVacaciones(int $gestion): array
    {
        return RawConnection::select(
            "SELECT id, gestion, anios_desde, anios_hasta, dias_habiles
             FROM rrhh_escala_vacaciones
             WHERE gestion = :g ORDER BY anios_desde",
            [':g' => $gestion]
        );
    }

    public function feriados(int $gestion, ?string $departamento = null): array
    {
        $sql = "SELECT id, gestion, fecha, nombre, tipo, departamento
                FROM rrhh_feriado WHERE gestion = :g";
        $params = [':g' => $gestion];

        if ($departamento) {
            $sql .= " AND (tipo = 'NACIONAL' OR departamento = :dep)";
            $params[':dep'] = $departamento;
        }

        $sql .= " ORDER BY fecha";
        return RawConnection::select($sql, $params);
    }

    public function crearParametro(array $data): array
    {
        $gestion = (int) ($data['gestion'] ?? 0);
        $codigo  = $data['codigo'] ?? '';
        $valor   = $data['valor'] ?? '';
        $desc    = $data['descripcion'] ?? '';
        $norma   = $data['norma_referencia'] ?? null;

        if (!$gestion || !$codigo) return ['error' => 'gestion y codigo son obligatorios'];

        $existe = RawConnection::selectOne(
            "SELECT id FROM rrhh_parametro_gestion WHERE gestion = :g AND codigo = :c",
            [':g' => $gestion, ':c' => $codigo]
        );
        if ($existe) return ['error' => "Ya existe el parámetro '{$codigo}' para la gestión {$gestion}"];

        RawConnection::execute(
            "INSERT INTO rrhh_parametro_gestion (gestion, codigo, valor, descripcion, norma_referencia, created_at, updated_at)
             VALUES (:g, :c, :v, :d, :n, NOW(), NOW())",
            [':g' => $gestion, ':c' => $codigo, ':v' => $valor, ':d' => $desc, ':n' => $norma]
        );

        return ['id' => (int) RawConnection::lastInsertId(), 'mensaje' => 'Parámetro creado'];
    }

    public function actualizarParametro(int $id, array $data): array
    {
        $param = RawConnection::selectOne("SELECT id FROM rrhh_parametro_gestion WHERE id = :id", [':id' => $id]);
        if (!$param) return ['error' => 'Parámetro no encontrado'];

        $sets = [];
        $params = [':id' => $id];
        foreach (['valor', 'descripcion', 'norma_referencia', 'vigente_desde', 'vigente_hasta'] as $campo) {
            if (array_key_exists($campo, $data)) {
                $sets[] = "{$campo} = :{$campo}";
                $params[":{$campo}"] = $data[$campo];
            }
        }

        if (empty($sets)) return ['error' => 'No se proporcionaron campos para actualizar'];
        $sets[] = "updated_at = NOW()";
        RawConnection::execute("UPDATE rrhh_parametro_gestion SET " . implode(', ', $sets) . " WHERE id = :id", $params);
        return ['id' => $id, 'mensaje' => 'Parámetro actualizado'];
    }

    public function crearFeriado(array $data): array
    {
        $gestion = (int) ($data['gestion'] ?? 0);
        $fecha   = $data['fecha'] ?? '';
        $nombre  = $data['nombre'] ?? '';
        $tipo    = $data['tipo'] ?? 'NACIONAL';
        $depto   = $data['departamento'] ?? null;

        if (!$gestion || !$fecha || !$nombre) return ['error' => 'gestion, fecha y nombre son obligatorios'];

        RawConnection::execute(
            "INSERT INTO rrhh_feriado (gestion, fecha, nombre, tipo, departamento) VALUES (:g, :f, :n, :t, :d)",
            [':g' => $gestion, ':f' => $fecha, ':n' => $nombre, ':t' => $tipo, ':d' => $depto]
        );

        return ['id' => (int) RawConnection::lastInsertId(), 'mensaje' => 'Feriado creado'];
    }

    public function actualizarFeriado(int $id, array $data): array
    {
        $fer = RawConnection::selectOne("SELECT id FROM rrhh_feriado WHERE id = :id", [':id' => $id]);
        if (!$fer) return ['error' => 'Feriado no encontrado'];

        $sets = [];
        $params = [':id' => $id];
        foreach (['fecha', 'nombre', 'tipo', 'departamento'] as $campo) {
            if (array_key_exists($campo, $data)) {
                $sets[] = "{$campo} = :{$campo}";
                $params[":{$campo}"] = $data[$campo];
            }
        }

        if (empty($sets)) return ['error' => 'No se proporcionaron campos para actualizar'];
        RawConnection::execute("UPDATE rrhh_feriado SET " . implode(', ', $sets) . " WHERE id = :id", $params);
        return ['id' => $id, 'mensaje' => 'Feriado actualizado'];
    }

    public function eliminarFeriado(int $id): array
    {
        $fer = RawConnection::selectOne("SELECT id FROM rrhh_feriado WHERE id = :id", [':id' => $id]);
        if (!$fer) return ['error' => 'Feriado no encontrado'];

        RawConnection::execute("DELETE FROM rrhh_feriado WHERE id = :id", [':id' => $id]);
        return ['id' => $id, 'mensaje' => 'Feriado eliminado'];
    }

    public function gestiones(): array
    {
        return RawConnection::select(
            "SELECT DISTINCT gestion FROM rrhh_parametro_gestion ORDER BY gestion DESC"
        );
    }
}
