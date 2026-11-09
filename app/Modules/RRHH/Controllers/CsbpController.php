<?php

namespace App\Modules\RRHH\Controllers;

use App\Modules\RRHH\Services\CsbpService;
use App\Modules\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CsbpController extends ApiController
{
    private CsbpService $csbp;

    public function __construct(CsbpService $csbp)
    {
        $this->csbp = $csbp;
    }

    public function dashboard(): JsonResponse
    {
        return $this->ok($this->csbp->dashboardCsbp());
    }

    public function altas(Request $request): JsonResponse
    {
        $estado = $request->query('estado');
        return $this->ok($this->csbp->listarAltas($estado));
    }

    public function bajas(Request $request): JsonResponse
    {
        $estado = $request->query('estado');
        return $this->ok($this->csbp->listarBajas($estado));
    }

    public function altasPorContrato(int $idContrato): JsonResponse
    {
        return $this->ok($this->csbp->altasPorContrato($idContrato));
    }

    public function bajasPorContrato(int $idContrato): JsonResponse
    {
        return $this->ok($this->csbp->bajasPorContrato($idContrato));
    }

    public function aportes(Request $request): JsonResponse
    {
        $gestion = (int) $this->requireEjercicio($request);
        return $this->ok($this->csbp->aportes($gestion));
    }

    public function aporteDetalle(int $id): JsonResponse
    {
        $data = $this->csbp->aporteDetalle($id);
        if (!$data) return $this->error('Aporte no encontrado', 404);
        return $this->ok($data);
    }

    public function resumenAnual(Request $request): JsonResponse
    {
        $gestion = (int) $this->requireEjercicio($request);
        return $this->ok($this->csbp->resumenAnual($gestion));
    }

    public function crearAlta(Request $request): JsonResponse
    {
        $result = $this->csbp->crearAlta($request->all());
        if (isset($result['error'])) return $this->error($result['error'], 422);
        return $this->ok($result);
    }

    public function actualizarAlta(Request $request, int $id): JsonResponse
    {
        $result = $this->csbp->actualizarAlta($id, $request->all());
        if (isset($result['error'])) return $this->error($result['error'], 422);
        return $this->ok($result);
    }

    public function crearBaja(Request $request): JsonResponse
    {
        $result = $this->csbp->crearBaja($request->all());
        if (isset($result['error'])) return $this->error($result['error'], 422);
        return $this->ok($result);
    }

    public function crearAporte(Request $request): JsonResponse
    {
        $result = $this->csbp->crearAporte($request->all());
        if (isset($result['error'])) return $this->error($result['error'], 422);
        return $this->ok($result);
    }
}
