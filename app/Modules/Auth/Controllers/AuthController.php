<?php

namespace App\Modules\Auth\Controllers;

use App\Modules\Auth\Services\AuthService;
use App\Modules\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuthController extends ApiController
{
    private AuthService $auth;

    public function __construct(AuthService $auth)
    {
        $this->auth = $auth;
    }

    public function login(Request $request): JsonResponse
    {
        $login     = $request->input('usuario', '');
        $password  = $request->input('password', '');
        $idAgencia = (int) $request->input('id_agencia', -1);

        if (!$login || !$password || $idAgencia < 0) {
            return $this->error('Debe ingresar usuario, contraseña y agencia.', 422);
        }

        $result = $this->auth->authenticate($login, $password, $idAgencia);

        if (isset($result['error'])) {
            return $this->error($result['error'], $result['status']);
        }

        return $this->ok($result, 'Inicio de sesión exitoso.');
    }

    public function agencies(Request $request): JsonResponse
    {
        $login = trim($request->query('usuario', ''));
        if (!$login) {
            return $this->error('Debe enviar el parámetro usuario.', 422);
        }

        $agencies = $this->auth->getAgenciesByLogin($login);
        return $this->ok($agencies);
    }

    public function me(Request $request): JsonResponse
    {
        $claims = $request->attributes->get('jwt_claims');
        return $this->ok([
            'user'    => $claims['user'] ?? null,
            'agencia' => $claims['agencia'] ?? null,
            'rol'     => $claims['rol'] ?? null,
        ]);
    }
}
