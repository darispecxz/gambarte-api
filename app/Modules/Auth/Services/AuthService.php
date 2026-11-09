<?php

namespace App\Modules\Auth\Services;

use App\Modules\Auth\Repositories\AuthRepository;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class AuthService
{
    private AuthRepository $repo;
    private string $secret;
    private int    $ttl;
    private string $algo;
    private string $issuer;

    public function __construct(AuthRepository $repo)
    {
        $this->repo   = $repo;
        $this->secret = config('jwt.secret');
        $this->ttl    = config('jwt.ttl');
        $this->algo   = config('jwt.algo');
        $this->issuer = config('jwt.issuer');
    }

    public function authenticate(string $login, string $password, int $idAgencia): array
    {
        $user = $this->repo->findActiveUser(strtolower(trim($login)));
        if (!$user) {
            return ['error' => 'Credenciales inválidas.', 'status' => 401];
        }

        if (md5($password) !== $user['clave_usu']) {
            return ['error' => 'Credenciales inválidas.', 'status' => 401];
        }

        $access = $this->repo->userHasAgencyAccess((int) $user['cod_usuario'], $idAgencia);
        if (!$access) {
            return ['error' => 'No tiene acceso a la agencia seleccionada.', 'status' => 403];
        }

        $role = $this->repo->getUserActiveRole((int) $user['cod_usuario']);
        if (!$role) {
            return ['error' => 'Usuario sin rol activo asignado.', 'status' => 403];
        }

        $now = time();
        $payload = [
            'iss'  => $this->issuer,
            'iat'  => $now,
            'exp'  => $now + $this->ttl,
            'sub'  => (int) $user['cod_usuario'],
            'user' => [
                'cod_usuario'    => (int) $user['cod_usuario'],
                'login'          => $user['login_usu'],
                'nombre'         => trim($user['nombre_completo']),
                'email'          => $user['email_corp'],
            ],
            'agencia' => [
                'id_agencia'   => (int) $access['id_agencia'],
                'cod_agencia'  => $access['cod_agencia'],
                'descripcion'  => $access['agencia_descripcion'],
                'abreviatura'  => $access['abreviatura'],
            ],
            'rol' => [
                'id_rol'      => (int) $role['id_rol'],
                'descripcion' => $role['rol_descripcion'],
            ],
        ];

        $token = JWT::encode($payload, $this->secret, $this->algo);

        return [
            'token'      => $token,
            'expires_in' => $this->ttl,
            'user'       => $payload['user'],
            'agencia'    => $payload['agencia'],
            'rol'        => $payload['rol'],
        ];
    }

    public function validateToken(string $token): ?array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secret, $this->algo));
            return json_decode(json_encode($decoded), true);
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getAgenciesByLogin(string $login): array
    {
        $user = $this->repo->findActiveUser(strtolower(trim($login)));
        if (!$user) {
            return [];
        }
        return $this->repo->getUserAgencies((int) $user['cod_usuario']);
    }
}
