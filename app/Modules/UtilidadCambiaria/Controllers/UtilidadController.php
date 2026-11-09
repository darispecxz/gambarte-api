<?php

namespace App\Modules\UtilidadCambiaria\Controllers;

use App\Modules\Shared\Http\ApiController;
use App\Modules\UtilidadCambiaria\Services\UtilidadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * UtilidadController — API de Utilidad Cambiaria (rentabilidad FIFO).
 *
 * Endpoints:
 *   GET /api/utilidad-cambiaria/meta           → agencias, monedas, cajeros
 *   GET /api/utilidad-cambiaria/{vista}        → datos de la pestaña
 *     vista ∈ empresa | agencia | cajero | kardex | ranking | tendencia | inventario | comparar
 *   Query: ini, fin (YYYY-MM-DD), agencia (0=todas), moneda (0=todas),
 *          cajero (login, solo cajero/kardex), orden (utilidad_asc|utilidad_desc)
 */
class UtilidadController extends ApiController
{
    private const VISTAS = ['empresa', 'agencia', 'cajero', 'kardex', 'ranking', 'tendencia', 'inventario', 'comparar', 'redistribucion'];

    private UtilidadService $service;

    public function __construct(UtilidadService $service)
    {
        $this->service = $service;
    }

    /** Catálogos para los filtros del frontend. */
    public function meta(): JsonResponse
    {
        return $this->ok([
            'agencias' => $this->service->agencias(),
            'monedas'  => $this->service->monedas(),
            'cajeros'  => $this->service->cajeros(),
        ]);
    }

    /** Datos de una vista/pestaña. */
    public function show(Request $request, string $vista): JsonResponse
    {
        if (!in_array($vista, self::VISTAS, true)) {
            abort(422, "Valor inválido para 'vista'. Permitidos: " . implode(', ', self::VISTAS) . '.');
        }

        [$ini, $fin] = $this->resolveRango($request);
        $ag = $this->intParam($request, 'agencia') ?: null;
        $mn = $this->intParam($request, 'moneda') ?: null;
        $cajero = trim((string) $request->query('cajero', ''));
        $orden = $request->query('orden', '');
        if (!in_array($orden, ['utilidad_asc', 'utilidad_desc'], true)) $orden = '';

        switch ($vista) {
            case 'empresa':    $result = $this->service->empresa($ini, $fin, $ag, $mn, $orden); break;
            case 'agencia':    $result = $this->service->agencia($ini, $fin, $ag, $mn); break;
            case 'cajero':     $result = $this->service->cajero($ini, $fin, $ag, $mn, $cajero, $orden); break;
            case 'kardex':     $result = $this->service->kardex($ini, $fin, $ag, $mn, $cajero); break;
            case 'ranking':    $result = $this->service->ranking($ini, $fin, $ag, $mn); break;
            case 'tendencia':  $result = $this->service->tendencia($ini, $fin, $ag, $mn); break;
            case 'inventario': $result = $this->service->inventario($ini, $fin, $ag, $mn); break;
            case 'comparar':   $result = $this->service->comparar($ini, $fin, $ag, $mn); break;
            case 'redistribucion': $result = $this->service->redistribucion($ini, $fin, $ag, $mn); break;
            default:           $result = [];
        }

        return $this->ok(array_merge(['vista' => $vista, 'ini' => $ini, 'fin' => $fin], $result));
    }

    /** Resuelve el rango [ini, fin]; default: mes actual → hoy. Garantiza ini <= fin. */
    private function resolveRango(Request $request): array
    {
        $ini = $this->validDate($request->query('ini')) ?? date('Y-m-01');
        $fin = $this->validDate($request->query('fin')) ?? date('Y-m-d');
        if ($ini > $fin) { [$ini, $fin] = [$fin, $ini]; }
        return [$ini, $fin];
    }

    private function validDate(?string $s): ?string
    {
        if ($s && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s) !== false) {
            return $s;
        }
        return null;
    }
}
