<?php

namespace App\Modules\RRHH\Controllers;

use App\Modules\RRHH\Services\PlanillaService;
use App\Modules\RRHH\Services\AsistenciaService;
use App\Modules\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PlanillaController extends ApiController
{
    private PlanillaService $planillas;
    private AsistenciaService $asistencia;

    public function __construct(PlanillaService $planillas, AsistenciaService $asistencia)
    {
        $this->planillas  = $planillas;
        $this->asistencia = $asistencia;
    }

    public function listar(Request $request): JsonResponse
    {
        $gestion = (int) $this->requireEjercicio($request);
        $agencia = $this->intParam($request, 'agencia');
        return $this->ok($this->planillas->listar($gestion, $agencia));
    }

    public function detalle(int $id): JsonResponse
    {
        $data = $this->planillas->detalle($id);
        if (!$data) return $this->error('Planilla no encontrada', 404);
        return $this->ok($data);
    }

    public function preview(Request $request): JsonResponse
    {
        $gestion = (int) $this->requireEjercicio($request);
        $mes     = (int) $request->query('mes', date('m'));
        $agencia = $this->intParam($request, 'agencia');

        if ($mes < 1 || $mes > 12) {
            return $this->error('Mes inválido', 422);
        }

        try {
            return $this->ok($this->planillas->preview($gestion, $mes, $agencia));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 400);
        }
    }

    public function calcularEmpleado(Request $request, int $idEmpleado): JsonResponse
    {
        $gestion = (int) $this->requireEjercicio($request);
        $mes     = (int) $request->query('mes', date('m'));

        try {
            return $this->ok($this->planillas->calcularEmpleado($gestion, $mes, $idEmpleado));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 400);
        }
    }

    public function asistenciaResumen(Request $request): JsonResponse
    {
        $gestion = (int) $this->requireEjercicio($request);
        $mes     = (int) $request->query('mes', date('m'));
        $agencia = $this->intParam($request, 'agencia');

        return $this->ok($this->asistencia->resumenMensual($gestion, $mes, $agencia));
    }

    public function asistenciaDetalle(Request $request, int $idEmpleado): JsonResponse
    {
        $gestion = (int) $this->requireEjercicio($request);
        $mes     = (int) $request->query('mes', date('m'));

        return $this->ok($this->asistencia->detalleEmpleado($idEmpleado, $gestion, $mes));
    }

    public function controlJornada(Request $request): JsonResponse
    {
        $gestion = (int) $this->requireEjercicio($request);
        $mes     = (int) $request->query('mes', date('m'));
        $agencia = $this->intParam($request, 'agencia');

        return $this->ok($this->asistencia->controlJornada($gestion, $mes, $agencia));
    }

    public function registrarAsistencia(Request $request): JsonResponse
    {
        try {
            return $this->ok($this->asistencia->registrar($request->all()));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }

    public function generar(Request $request): JsonResponse
    {
        $gestion = (int) $this->requireEjercicio($request);
        $mes     = (int) $request->input('mes', date('m'));
        $agencia = $this->intParam($request, 'agencia');

        if ($mes < 1 || $mes > 12) {
            return $this->error('Mes inválido', 422);
        }

        try {
            return $this->ok($this->planillas->generar($gestion, $mes, $agencia));
        } catch (\RuntimeException $e) {
            return $this->error($e->getMessage(), 400);
        }
    }
}
