<?php

namespace App\Modules\RRHH\Controllers;

use App\Modules\RRHH\Services\EvaluacionService;
use App\Modules\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EvaluacionController extends ApiController
{
    private EvaluacionService $evaluaciones;

    public function __construct(EvaluacionService $evaluaciones)
    {
        $this->evaluaciones = $evaluaciones;
    }

    public function listar(Request $request): JsonResponse
    {
        $periodo = $request->query('periodo');
        $idEmpleado = $this->intParam($request, 'id_empleado');
        return $this->ok($this->evaluaciones->listar($periodo, $idEmpleado));
    }

    public function detalle(int $id): JsonResponse
    {
        $data = $this->evaluaciones->detalle($id);
        if (!$data) return $this->error('Evaluación no encontrada', 404);
        return $this->ok($data);
    }

    public function crear(Request $request): JsonResponse
    {
        $result = $this->evaluaciones->crear($request->all());
        if (isset($result['error'])) return $this->error($result['error'], 422);
        return $this->ok($result);
    }

    public function actualizar(Request $request, int $id): JsonResponse
    {
        $result = $this->evaluaciones->actualizar($id, $request->all());
        if (isset($result['error'])) return $this->error($result['error'], 422);
        return $this->ok($result);
    }
}
