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
    private int    $refreshTtl;
    private string $algo;
    private string $issuer;

    public function __construct(AuthRepository $repo)
    {
        $this->repo   = $repo;
        $this->secret = config('jwt.secret');
        $this->ttl    = config('jwt.ttl');
        $this->refreshTtl = config('jwt.refresh_ttl');
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

        $identity = [
            'sub' => (int) $user['cod_usuario'],
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

        return $this->issueTokens($identity);
    }

    public function refresh(string $refreshToken): ?array
    {
        try {
            $decoded = JWT::decode($refreshToken, new Key($this->secret, $this->algo));
            $claims = json_decode(json_encode($decoded), true);
        } catch (\Exception $e) {
            return null;
        }

        if (($claims['type'] ?? null) !== 'refresh' || empty($claims['sub'])) {
            return null;
        }

        $user = $this->repo->findActiveUserById((int) $claims['sub']);
        $agencyId = (int) ($claims['agencia']['id_agencia'] ?? 0);
        if (!$user || !$agencyId) {
            return null;
        }

        $access = $this->repo->userHasAgencyAccess((int) $user['cod_usuario'], $agencyId);
        $role = $this->repo->getUserActiveRole((int) $user['cod_usuario']);
        if (!$access || !$role) {
            return null;
        }

        $identity = [
            'sub' => (int) $user['cod_usuario'],
            'user' => [
                'cod_usuario' => (int) $user['cod_usuario'],
                'login' => $user['login_usu'],
                'nombre' => trim($user['nombre_completo']),
                'email' => $user['email_corp'],
            ],
            'agencia' => [
                'id_agencia' => (int) $access['id_agencia'],
                'cod_agencia' => $access['cod_agencia'],
                'descripcion' => $access['agencia_descripcion'],
                'abreviatura' => $access['abreviatura'],
            ],
            'rol' => [
                'id_rol' => (int) $role['id_rol'],
                'descripcion' => $role['rol_descripcion'],
            ],
        ];

        return $this->issueTokens($identity);
    }

    private function issueTokens(array $identity): array
    {
        $now = time();
        $payload = array_merge($identity, [
            'iss' => $this->issuer,
            'iat' => $now,
            'exp' => $now + $this->ttl,
        ]);
        $refreshPayload = array_merge($identity, [
            'iss' => $this->issuer,
            'iat' => $now,
            'exp' => $now + $this->refreshTtl,
            'type' => 'refresh',
        ]);

        return [
            'token'      => JWT::encode($payload, $this->secret, $this->algo),
            'refresh_token' => JWT::encode($refreshPayload, $this->secret, $this->algo),
            'expires_in' => $this->ttl,
            'refresh_expires_in' => $this->refreshTtl,
            'user'       => $identity['user'],
            'agencia'    => $identity['agencia'],
            'rol'        => $identity['rol'],
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
