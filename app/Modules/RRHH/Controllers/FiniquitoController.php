<?php

namespace App\Modules\RRHH\Controllers;

use App\Modules\RRHH\Services\FiniquitoService;
use App\Modules\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class FiniquitoController extends ApiController
{
    private FiniquitoService $finiquito;

    public function __construct(FiniquitoService $finiquito)
    {
        $this->finiquito = $finiquito;
    }

    public function listar(Request $request): JsonResponse
    {
        $estado = $request->query('estado');
        return $this->ok($this->finiquito->listar($estado));
    }

    public function detalle(int $id): JsonResponse
    {
        $data = $this->finiquito->detalle($id);
        if (!$data) return $this->error('Finiquito no encontrado', 404);
        return $this->ok($data);
    }

    public function calcular(Request $request): JsonResponse
    {
        $idEmpleado = (int) $request->query('id_empleado');
        $fechaRetiro = $request->query('fecha_retiro');
        $motivo = $request->query('motivo', 'VOLUNTARIO');

        if (!$idEmpleado || !$fechaRetiro) {
            return $this->error('Se requiere id_empleado y fecha_retiro', 422);
        }

        $motivosValidos = ['VOLUNTARIO', 'FORZOSO', 'CONCLUSION_CONTRATO', 'CONCLUSION_OBRA', 'INCUMPLIMIENTO_CONTRATO'];
        if (!in_array($motivo, $motivosValidos)) {
            return $this->error('Motivo de baja inválido', 422);
        }

        try {
            return $this->ok($this->finiquito->calcular($idEmpleado, $fechaRetiro, $motivo));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 400);
        }
    }

    public function guardar(Request $request): JsonResponse
    {
        $idEmpleado = (int) $request->input('id_empleado');
        $fechaRetiro = $request->input('fecha_retiro');
        $motivo = $request->input('motivo', 'VOLUNTARIO');

        if (!$idEmpleado || !$fechaRetiro) {
            return $this->error('Se requiere id_empleado y fecha_retiro', 422);
        }

        try {
            return $this->ok($this->finiquito->guardar($idEmpleado, $fechaRetiro, $motivo));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 400);
        }
    }
}
