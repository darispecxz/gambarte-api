<?php

namespace App\Modules\Bancos\Controllers;

use App\Modules\Bancos\Services\BankService;
use App\Modules\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BankingController extends ApiController
{
    private BankService $service;

    public function __construct(BankService $service)
    {
        $this->service = $service;
    }

    public function entities(): JsonResponse
    {
        return $this->ok($this->service->entities());
    }

    public function accounts(Request $request): JsonResponse
    {
        $agencia = $this->intParam($request, 'agencia');
        return $this->ok($this->service->accounts($agencia));
    }

    public function movements(Request $request): JsonResponse
    {
        $desde   = $this->requireFecha($request, 'desde');
        $hasta   = $this->optionalFecha($request, 'hasta');
        $agencia = $this->intParam($request, 'agencia');
        $moneda  = $this->intParam($request, 'moneda');
        $cuenta  = $request->filled('cuenta') ? trim($request->query('cuenta')) : null;
        $conc    = $request->filled('conciliacion') ? trim($request->query('conciliacion')) : null;

        $result = $this->service->movements($desde, $hasta, $agencia, $moneda, $cuenta, $conc);

        return $this->ok([
            'desde'     => $desde,
            'hasta'     => $hasta ?: $desde,
            'total'     => count($result),
            'movements' => $result,
        ]);
    }

    public function conciliacion(Request $request): JsonResponse
    {
        $fecha   = $this->requireFecha($request);
        $agencia = $this->intParam($request, 'agencia');

        return $this->ok($this->service->conciliacionContable($fecha, $agencia));
    }

    public function dailySummary(Request $request): JsonResponse
    {
        $fecha = $this->requireFecha($request);
        return $this->ok(['fecha' => $fecha, 'report' => $this->service->dailySummary($fecha)]);
    }

    public function conciliacionDetalle(Request $request): JsonResponse
    {
        $fecha   = $this->requireFecha($request);
        $agencia = $this->intParam($request, 'agencia');
        $entidad = $this->intParam($request, 'entidad');
        $cuenta  = $request->filled('cuenta') ? trim($request->query('cuenta')) : null;
        $moneda  = $this->intParam($request, 'moneda');

        if ($agencia === null || $entidad === null || $cuenta === null || $moneda === null) {
            abort(422, 'Faltan parámetros requeridos (agencia, entidad, cuenta, moneda).');
        }

        return $this->ok($this->service->conciliacionDetalle($fecha, $agencia, $entidad, $cuenta, $moneda));
    }

    public function movementDetail(Request $request): JsonResponse
    {
        $agencia = $this->intParam($request, 'agencia');
        $entidad = $this->intParam($request, 'entidad');
        $cuenta  = $request->filled('cuenta') ? trim($request->query('cuenta')) : null;
        $moneda  = $this->intParam($request, 'moneda');
        $fecha   = $request->filled('fecha') ? trim($request->query('fecha')) : null;
        $conc    = $request->filled('conciliacion') ? trim($request->query('conciliacion')) : null;
        $monto   = $request->filled('monto') ? (float) $request->query('monto') : null;

        if ($agencia === null || $entidad === null || $cuenta === null ||
            $moneda === null || $fecha === null || $conc === null || $monto === null) {
            abort(422, 'Faltan parámetros requeridos (agencia, entidad, cuenta, moneda, fecha, conciliacion, monto).');
        }

        $result = $this->service->movementDetail($agencia, $entidad, $cuenta, $moneda, $fecha, $conc, $monto);
        if ($result === null) {
            abort(404, 'Movimiento no encontrado.');
        }

        return $this->ok($result);
    }

    public function saldos(Request $request): JsonResponse
    {
        $agencia = $this->intParam($request, 'agencia');
        return $this->ok($this->service->saldos($agencia));
    }

    public function transfers(Request $request): JsonResponse
    {
        $desde  = $this->requireFecha($request, 'desde');
        $hasta  = $this->optionalFecha($request, 'hasta');
        $estado = $request->filled('estado') ? trim($request->query('estado')) : null;

        $result = $this->service->transfers($desde, $hasta, $estado);

        return $this->ok([
            'desde'     => $desde,
            'hasta'     => $hasta ?: $desde,
            'total'     => count($result),
            'transfers' => $result,
        ]);
    }
}
