<?php

namespace App\Modules\Shared\Http;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class ApiController extends Controller
{
    protected function ok($data, ?string $message = null): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => $data,
            'message' => $message,
            'errors'  => [],
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }

    protected function error(string $message, int $status = 400, array $errors = []): JsonResponse
    {
        return response()->json([
            'success' => false,
            'data'    => null,
            'message' => $message,
            'errors'  => $errors,
        ], $status, [], JSON_UNESCAPED_UNICODE);
    }

    protected function requireFecha(Request $request, string $param = 'fecha'): string
    {
        $v = trim($request->query($param, date('Y-m-d')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || strtotime($v) === false) {
            abort(422, "Parámetro '{$param}' inválido (use AAAA-MM-DD).");
        }
        return $v;
    }

    protected function optionalFecha(Request $request, string $param): ?string
    {
        if (!$request->filled($param)) return null;
        $v = trim($request->query($param));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || strtotime($v) === false) {
            abort(422, "Parámetro '{$param}' inválido (use AAAA-MM-DD).");
        }
        return $v;
    }

    protected function requireEjercicio(Request $request): string
    {
        $v = trim($request->query('ejercicio', date('Y')));
        if (!preg_match('/^\d{4}$/', $v)) {
            abort(422, "Parámetro 'ejercicio' inválido.");
        }
        return $v;
    }

    protected function fechaRango(Request $request): array
    {
        $ej    = $this->requireEjercicio($request);
        $desde = trim($request->query('desde', "{$ej}-01-01"));
        $hasta = trim($request->query('hasta', date('Y-m-d')));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $desde) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $hasta)) {
            abort(422, "Parámetros 'desde'/'hasta' inválidos (use AAAA-MM-DD).");
        }

        return [$ej, $desde, $hasta];
    }

    protected function intParam(Request $request, string $param, ?int $default = null): ?int
    {
        if (!$request->filled($param)) return $default;
        return (int) $request->query($param);
    }
}
