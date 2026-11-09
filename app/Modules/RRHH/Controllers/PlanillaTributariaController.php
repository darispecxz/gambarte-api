<?php

namespace App\Modules\RRHH\Controllers;

use App\Modules\RRHH\Services\PlanillaTributariaService;
use App\Modules\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanillaTributariaController extends ApiController
{
    private PlanillaTributariaService $tributaria;

    public function __construct(PlanillaTributariaService $tributaria)
    {
        $this->tributaria = $tributaria;
    }

    public function listar(Request $request): JsonResponse
    {
        $gestion = (int) $this->requireEjercicio($request);
        $mes = (int) $request->query('mes', date('m'));

        if ($mes < 1 || $mes > 12) {
            return $this->error('Mes inválido', 422);
        }

        return $this->ok($this->tributaria->listar($gestion, $mes));
    }

    public function detalle(Request $request, int $idEmpleado): JsonResponse
    {
        $gestion = (int) $this->requireEjercicio($request);
        $mes = (int) $request->query('mes', date('m'));

        $data = $this->tributaria->detallePorEmpleado($idEmpleado, $gestion, $mes);
        if (!$data) return $this->error('Planilla tributaria no encontrada', 404);
        return $this->ok($data);
    }

    public function calcular(Request $request): JsonResponse
    {
        $gestion = (int) $this->requireEjercicio($request);
        $mes = (int) $request->query('mes', date('m'));
        $agencia = $this->intParam($request, 'agencia');

        if ($mes < 1 || $mes > 12) {
            return $this->error('Mes inválido', 422);
        }

        try {
            return $this->ok($this->tributaria->calcularTributaria($gestion, $mes, $agencia));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 400);
        }
    }

    public function resumenAnual(Request $request, int $idEmpleado): JsonResponse
    {
        $gestion = (int) $this->requireEjercicio($request);
        return $this->ok($this->tributaria->resumenAnual($gestion, $idEmpleado));
    }
}
