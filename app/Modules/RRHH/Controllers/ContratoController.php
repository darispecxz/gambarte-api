<?php

namespace App\Modules\RRHH\Controllers;

use App\Modules\RRHH\Services\ContratoService;
use App\Modules\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ContratoController extends ApiController
{
    private ContratoService $contratos;

    public function __construct(ContratoService $contratos)
    {
        $this->contratos = $contratos;
    }

    public function listar(Request $request): JsonResponse
    {
        $idEmpleado = $this->intParam($request, 'id_empleado');
        $estado = $request->query('estado');
        return $this->ok($this->contratos->listar($idEmpleado, $estado));
    }

    public function detalle(int $id): JsonResponse
    {
        $data = $this->contratos->detalle($id);
        if (!$data) return $this->error('Contrato no encontrado', 404);
        return $this->ok($data);
    }

    public function crear(Request $request): JsonResponse
    {
        $result = $this->contratos->crear($request->all());
        if (isset($result['error'])) return $this->error($result['error'], 422);
        return $this->ok($result);
    }

    public function actualizar(Request $request, int $id): JsonResponse
    {
        $result = $this->contratos->actualizar($id, $request->all());
        if (isset($result['error'])) return $this->error($result['error'], 422);
        return $this->ok($result);
    }

    public function rescindir(Request $request, int $id): JsonResponse
    {
        $result = $this->contratos->rescindir($id, $request->all());
        if (isset($result['error'])) return $this->error($result['error'], 422);
        return $this->ok($result);
    }
}
