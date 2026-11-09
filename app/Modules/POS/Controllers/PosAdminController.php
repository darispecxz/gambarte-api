<?php

namespace App\Modules\POS\Controllers;

use App\Modules\POS\Services\PosAdminService;
use App\Modules\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PosAdminController extends ApiController
{
    private PosAdminService $service;

    public function __construct(PosAdminService $service)
    {
        $this->service = $service;
    }

    public function dashboard(Request $request): JsonResponse
    {
        $fecha   = $this->requireFecha($request);
        $agencia = $this->intParam($request, 'agencia');

        return $this->ok([
            'fecha'    => $fecha,
            'agencia'  => $agencia,
            'agencies' => $this->service->dashboard($fecha, $agencia),
        ]);
    }

    public function movements(Request $request): JsonResponse
    {
        $fecha   = $this->requireFecha($request);
        $numCaja = (int) $request->query('num_caja');
        $agencia = (int) $request->query('agencia');

        if (!$numCaja || !$agencia) {
            return $this->error('Parámetros num_caja y agencia son requeridos', 400);
        }

        return $this->ok([
            'fecha'     => $fecha,
            'numCaja'   => $numCaja,
            'agencia'   => $agencia,
            'movements' => $this->service->movements($numCaja, $agencia, $fecha),
        ]);
    }

    public function arqueo(Request $request): JsonResponse
    {
        $fecha   = $this->requireFecha($request);
        $numCaja = (int) $request->query('num_caja');
        $agencia = (int) $request->query('agencia');
        $tipo    = $request->query('tipo', 'apertura');

        if (!$numCaja || !$agencia) {
            return $this->error('Parámetros num_caja y agencia son requeridos', 400);
        }

        return $this->ok([
            'fecha'   => $fecha,
            'numCaja' => $numCaja,
            'agencia' => $agencia,
            'arqueo'  => $this->service->arqueo($numCaja, $agencia, $fecha, $tipo),
        ]);
    }

    public function saldoHistory(Request $request, int $idCaja): JsonResponse
    {
        $fecha = $this->requireFecha($request);

        return $this->ok([
            'fecha'   => $fecha,
            'idCaja'  => $idCaja,
            'history' => $this->service->saldoHistory($idCaja, $fecha),
        ]);
    }

    public function fallasCaja(Request $request): JsonResponse
    {
        $desde   = $request->query('desde', date('Y-m-01'));
        $hasta   = $request->query('hasta', date('Y-m-d'));
        $agencia = $this->intParam($request, 'agencia');
        $holgura = (float) ($request->query('holgura', 1));

        return $this->ok([
            'desde'  => $desde,
            'hasta'  => $hasta,
            'fallas' => $this->service->fallasCaja($desde, $hasta, $agencia, $holgura),
        ]);
    }

    public function ratificarFalla(Request $request): JsonResponse
    {
        $idCaja     = (int) $request->input('id_caja');
        $fecha      = $request->input('fecha');
        $diferencia = (float) $request->input('diferencia');

        if (!$idCaja || !$fecha) {
            return $this->error('Parámetros id_caja y fecha son requeridos', 400);
        }

        $result = $this->service->ratificarFalla($idCaja, $fecha, $diferencia);

        if (!$result['ok']) {
            return $this->error($result['message'], 422);
        }

        return $this->ok(['message' => $result['message']]);
    }

    public function anularCierre(Request $request): JsonResponse
    {
        $idCaja = (int) $request->input('id_caja');
        $fecha  = $request->input('fecha');

        if (!$idCaja || !$fecha) {
            return $this->error('Parámetros id_caja y fecha son requeridos', 400);
        }

        $result = $this->service->anularCierre($idCaja, $fecha);

        if (!$result['ok']) {
            return $this->error($result['message'], 422);
        }

        return $this->ok(['message' => $result['message']]);
    }
}
