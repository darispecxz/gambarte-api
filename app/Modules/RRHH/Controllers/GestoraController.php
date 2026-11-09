<?php

namespace App\Modules\RRHH\Controllers;

use App\Modules\RRHH\Services\GestoraService;
use App\Modules\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GestoraController extends ApiController
{
    private GestoraService $gestora;

    public function __construct(GestoraService $gestora)
    {
        $this->gestora = $gestora;
    }

    public function listar(Request $request): JsonResponse
    {
        $estado = $request->query('estado');
        return $this->ok($this->gestora->listarGestoras($estado));
    }

    public function porContrato(int $idContrato): JsonResponse
    {
        $data = $this->gestora->gestoraPorContrato($idContrato);
        if (!$data) return $this->error('Gestora no encontrada para este contrato', 404);
        return $this->ok($data);
    }

    public function contribuciones(Request $request): JsonResponse
    {
        $gestion = (int) $this->requireEjercicio($request);
        $gestora = $request->query('gestora');
        return $this->ok($this->gestora->contribuciones($gestion, $gestora));
    }

    public function contribucionDetalle(int $id): JsonResponse
    {
        $data = $this->gestora->contribucionDetalle($id);
        if (!$data) return $this->error('Contribución no encontrada', 404);
        return $this->ok($data);
    }

    public function resumen(Request $request): JsonResponse
    {
        $gestion = (int) $this->requireEjercicio($request);
        return $this->ok($this->gestora->resumenContribuciones($gestion));
    }

    public function sinGestora(): JsonResponse
    {
        return $this->ok($this->gestora->empleadosSinGestora());
    }

    public function crear(Request $request): JsonResponse
    {
        $result = $this->gestora->crear($request->all());
        if (isset($result['error'])) return $this->error($result['error'], 422);
        return $this->ok($result);
    }

    public function actualizar(Request $request, int $id): JsonResponse
    {
        $result = $this->gestora->actualizar($id, $request->all());
        if (isset($result['error'])) return $this->error($result['error'], 422);
        return $this->ok($result);
    }

    public function crearContribucion(Request $request): JsonResponse
    {
        $result = $this->gestora->crearContribucion($request->all());
        if (isset($result['error'])) return $this->error($result['error'], 422);
        return $this->ok($result);
    }
}
