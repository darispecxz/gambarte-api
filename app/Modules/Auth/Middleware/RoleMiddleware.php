<?php

namespace App\Modules\Auth\Middleware;

use Closure;
use Illuminate\Http\Request;

class RoleMiddleware
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $claims = $request->attributes->get('jwt_claims');

        if (!$claims) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'Token de autenticación requerido.',
                'errors'  => [],
            ], 401);
        }

        $rol = is_object($claims['rol'] ?? null)
            ? ((array) $claims['rol'])['id_rol'] ?? null
            : ($claims['rol']['id_rol'] ?? null);

        if (!$rol || !in_array((string) $rol, $roles, true)) {
            return response()->json([
                'success' => false,
                'data'    => null,
                'message' => 'No tiene permisos para acceder a este recurso.',
                'errors'  => [],
            ], 403);
        }

        return $next($request);
    }
}
