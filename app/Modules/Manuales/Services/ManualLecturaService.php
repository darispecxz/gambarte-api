<?php

namespace App\Modules\Manuales\Services;

use App\Modules\Shared\Database\RawConnection;

class ManualLecturaService
{
    public function listarMias(int $userId, int $agencyId): array
    {
        if (!$userId) return [];
        return RawConnection::select(
            'SELECT manual_key, version, leido_en FROM pos_manual_lectura WHERE cod_usuario = :usuario AND id_agencia = :agencia',
            [':usuario' => $userId, ':agencia' => $agencyId]
        );
    }

    public function marcarLeido(int $userId, int $agencyId, string $manualKey, string $version): array
    {
        RawConnection::execute(
            'INSERT INTO pos_manual_lectura (cod_usuario, id_agencia, manual_key, version, leido_en)
             VALUES (:usuario, :agencia, :manual, :version, NOW())
             ON DUPLICATE KEY UPDATE id_agencia = VALUES(id_agencia), leido_en = VALUES(leido_en)',
            [':usuario' => $userId, ':agencia' => $agencyId ?: null, ':manual' => $manualKey, ':version' => $version]
        );

        return RawConnection::selectOne(
            'SELECT manual_key, version, leido_en FROM pos_manual_lectura
             WHERE cod_usuario = :usuario AND id_agencia = :agencia AND manual_key = :manual AND version = :version',
            [':usuario' => $userId, ':agencia' => $agencyId, ':manual' => $manualKey, ':version' => $version]
        ) ?? [];
    }

    public function seguimiento(?string $manualKey, ?int $agencyId): array
    {
        $filters = ['u.estado = \'A\'', 'ru.est_rol_usu = \'A\'', 'ru.id_rol = 6'];
        $params = [];
        if ($agencyId) {
            $filters[] = 'ac.id_agencia = :agencia';
            $params[':agencia'] = $agencyId;
        }
        $where = implode(' AND ', $filters);
        return RawConnection::select(
            "SELECT u.cod_usuario, u.login_usu AS login,
                    TRIM(CONCAT(t.nom_trab, ' ', t.ape_pat_trab, ' ', IFNULL(t.ape_mat_trab, ''))) AS cajero,
                    ag.id_agencia, ag.descripcion AS agencia,
                    l.manual_key, l.version, l.leido_en
             FROM usuario u
             INNER JOIN trabajador t ON t.cod_trab = u.cod_trab
             INNER JOIN rol_usuario ru ON ru.cod_usuario = u.cod_usuario
             INNER JOIN acceso ac ON ac.cod_usuario = u.cod_usuario AND ac.estado = 'A'
             INNER JOIN agencia ag ON ag.id_agencia = ac.id_agencia AND ag.estado = 'A'
             LEFT JOIN pos_manual_lectura l ON l.cod_usuario = u.cod_usuario AND l.id_agencia = ac.id_agencia
                  AND l.manual_key = :manual_join
             WHERE {$where}
             ORDER BY ag.descripcion, cajero",
            array_merge($params, [':manual_join' => $manualKey ?? 'apertura_caja'])
        );
    }
}
