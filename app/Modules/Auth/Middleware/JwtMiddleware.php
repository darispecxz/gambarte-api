<?php

namespace App\Modules\Auth\Middleware;

use App\Modules\Auth\Services\AuthService;
use Closure;
use Illuminate\Http\Request;

class JwtMiddleware
{
    private AuthService $auth;

    public function __construct(AuthService $auth)
    {
        $this->auth = $auth;
    }

    public function handle(Request $request, Closure $next)
    {
        $header = $request->header('Authorization', '');

        if (substr($header, 0, 7) !== 'Bearer ') {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'Token de autenticación requerido.',
                'errors'  => [],
            ], 401);
        }

        $token  = substr($header, 7);
        $claims = $this->auth->validateToken($token);

        if (!$claims) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'Token inválido o expirado.',
                'errors'  => [],
            ], 401);
        }

        $request->attributes->set('jwt_claims', $claims);

        return $next($request);
    }
}
