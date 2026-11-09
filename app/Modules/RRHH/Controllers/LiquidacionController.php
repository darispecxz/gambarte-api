<?php

namespace App\Modules\RRHH\Controllers;

use App\Modules\RRHH\Services\LiquidacionService;
use App\Modules\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LiquidacionController extends ApiController
{
    private LiquidacionService $liquidaciones;

    public function __construct(LiquidacionService $liquidaciones)
    {
        $this->liquidaciones = $liquidaciones;
    }

    public function listar(Request $request): JsonResponse
    {
        $estado = $request->query('estado');
        return $this->ok($this->liquidaciones->listar($estado));
    }

    public function detalle(int $id): JsonResponse
    {
        $data = $this->liquidaciones->detalle($id);
        if (!$data) return $this->error('Liquidación no encontrada', 404);
        return $this->ok($data);
    }

    public function simular(Request $request): JsonResponse
    {
        $idEmpleado = (int) $request->query('id_empleado');
        $fecha      = $this->requireFecha($request);
        $motivo     = $request->query('motivo', 'RENUNCIA');

        $motivosValidos = ['RENUNCIA', 'DESPIDO_JUSTIFICADO', 'DESPIDO_INJUSTIFICADO', 'FIN_CONTRATO', 'FALLECIMIENTO'];
        if (!in_array($motivo, $motivosValidos, true)) {
            return $this->error('Motivo inválido. Válidos: ' . implode(', ', $motivosValidos), 422);
        }

        if (!$idEmpleado) {
            return $this->error('Parámetro id_empleado es requerido', 400);
        }

        try {
            return $this->ok($this->liquidaciones->simular($idEmpleado, $fecha, $motivo));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 400);
        }
    }
}
