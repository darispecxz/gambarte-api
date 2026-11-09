<?php

namespace App\Modules\RRHH\Controllers;

use App\Modules\RRHH\Services\CapacitacionService;
use App\Modules\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CapacitacionController extends ApiController
{
    private CapacitacionService $capacitaciones;

    public function __construct(CapacitacionService $capacitaciones)
    {
        $this->capacitaciones = $capacitaciones;
    }

    public function listar(Request $request): JsonResponse
    {
        $tipo   = $request->query('tipo');
        $estado = $request->query('estado');
        return $this->ok($this->capacitaciones->listar($tipo, $estado));
    }

    public function detalle(int $id): JsonResponse
    {
        $data = $this->capacitaciones->detalle($id);
        if (!$data) return $this->error('Capacitación no encontrada', 404);
        return $this->ok($data);
    }

    public function cumplimiento(): JsonResponse
    {
        return $this->ok($this->capacitaciones->cumplimiento());
    }

    public function crear(Request $request): JsonResponse
    {
        $result = $this->capacitaciones->crear($request->all());
        if (isset($result['error'])) return $this->error($result['error'], 422);
        return $this->ok($result);
    }

    public function actualizar(Request $request, int $id): JsonResponse
    {
        $result = $this->capacitaciones->actualizar($id, $request->all());
        if (isset($result['error'])) return $this->error($result['error'], 422);
        return $this->ok($result);
    }

    public function inscribir(Request $request, int $id): JsonResponse
    {
        $idEmpleado = (int) $request->input('id_empleado');
        if (!$idEmpleado) return $this->error('id_empleado es obligatorio', 422);
        $result = $this->capacitaciones->inscribir($id, $idEmpleado);
        if (isset($result['error'])) return $this->error($result['error'], 422);
        return $this->ok($result);
    }

    public function calificar(Request $request, int $id, int $idEmpleado): JsonResponse
    {
        $result = $this->capacitaciones->calificar($id, $idEmpleado, $request->all());
        if (isset($result['error'])) return $this->error($result['error'], 422);
        return $this->ok($result);
    }
}
