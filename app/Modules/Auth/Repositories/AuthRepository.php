<?php

namespace App\Modules\Auth\Repositories;

use App\Modules\Shared\Database\RawConnection;

class AuthRepository
{
    public function findActiveUser(string $login): ?array
    {
        return RawConnection::selectOne(
            "SELECT u.cod_usuario, u.cod_trab, u.login_usu, u.clave_usu,
                    u.email_corp, u.estado, u.fec_ult_cambio_clave,
                    CONCAT(t.nom_trab, ' ', t.ape_pat_trab, ' ', IFNULL(t.ape_mat_trab, '')) AS nombre_completo
             FROM usuario u
             INNER JOIN trabajador t ON t.cod_trab = u.cod_trab
             WHERE u.login_usu = ? AND u.estado = 'A'",
            [$login]
        );
    }

    public function userHasAgencyAccess(int $codUsuario, int $idAgencia): ?array
    {
        return RawConnection::selectOne(
            "SELECT ac.cod_usuario, ac.id_agencia,
                    ag.cod_agencia, ag.descripcion AS agencia_descripcion, ag.abreviatura
             FROM acceso ac
             INNER JOIN agencia ag ON ag.id_agencia = ac.id_agencia AND ag.estado = 'A'
             WHERE ac.cod_usuario = ? AND ac.id_agencia = ? AND ac.estado = 'A'",
            [$codUsuario, $idAgencia]
        );
    }

    public function getUserAgencies(int $codUsuario): array
    {
        return RawConnection::select(
            "SELECT ag.id_agencia, ag.cod_agencia, ag.descripcion, ag.abreviatura
             FROM acceso ac
             INNER JOIN agencia ag ON ag.id_agencia = ac.id_agencia AND ag.estado = 'A'
             WHERE ac.cod_usuario = ? AND ac.estado = 'A'
             ORDER BY ag.descripcion",
            [$codUsuario]
        );
    }

    public function getUserActiveRole(int $codUsuario): ?array
    {
        return RawConnection::selectOne(
            "SELECT r.id_rol, r.descripcion AS rol_descripcion
             FROM rol_usuario ru
             INNER JOIN rol r ON r.id_rol = ru.id_rol AND r.estado = 'A'
             WHERE ru.cod_usuario = ? AND ru.est_rol_usu = 'A'
             LIMIT 1",
            [$codUsuario]
        );
    }
}
