<?php

namespace App\Modules\RRHH\Controllers;

use App\Modules\RRHH\Services\EmpleadoService;
use App\Modules\RRHH\Services\ParametrosService;
use App\Modules\Shared\Database\RawConnection;
use App\Modules\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RrhhController extends ApiController
{
    private EmpleadoService $empleados;
    private ParametrosService $parametros;

    public function __construct(EmpleadoService $empleados, ParametrosService $parametros)
    {
        $this->empleados  = $empleados;
        $this->parametros = $parametros;
    }

    public function dashboard(Request $request): JsonResponse
    {
        $agencia = $this->intParam($request, 'agencia');
        return $this->ok($this->empleados->dashboard($agencia));
    }

    public function empleados(Request $request): JsonResponse
    {
        $estado  = $request->query('estado');
        $agencia = $this->intParam($request, 'agencia');
        $buscar  = $request->query('buscar');

        return $this->ok($this->empleados->listar($estado, $agencia, $buscar));
    }

    public function empleadoDetalle(int $id): JsonResponse
    {
        $data = $this->empleados->detalle($id);
        if (!$data) return $this->error('Empleado no encontrado', 404);
        return $this->ok($data);
    }

    public function saldoVacaciones(Request $request, int $id): JsonResponse
    {
        $gestion = $this->requireEjercicio($request);
        return $this->ok($this->empleados->saldoVacaciones($id, (int) $gestion));
    }

    public function parametros(Request $request): JsonResponse
    {
        $gestion = $this->requireEjercicio($request);
        return $this->ok([
            'gestion'    => $gestion,
            'parametros' => $this->parametros->listar((int) $gestion),
            'bono_antiguedad' => $this->parametros->escalaBono((int) $gestion),
            'vacaciones' => $this->parametros->escalaVacaciones((int) $gestion),
            'feriados'   => $this->parametros->feriados((int) $gestion),
        ]);
    }

    public function gestiones(): JsonResponse
    {
        return $this->ok($this->parametros->gestiones());
    }

    public function feriados(Request $request): JsonResponse
    {
        $gestion = $this->requireEjercicio($request);
        $dep = $request->query('departamento');
        return $this->ok($this->parametros->feriados((int) $gestion, $dep));
    }

    public function crearParametro(Request $request): JsonResponse
    {
        $result = $this->parametros->crearParametro($request->all());
        if (isset($result['error'])) return $this->error($result['error'], 422);
        return $this->ok($result);
    }

    public function actualizarParametro(Request $request, int $id): JsonResponse
    {
        $result = $this->parametros->actualizarParametro($id, $request->all());
        if (isset($result['error'])) return $this->error($result['error'], 422);
        return $this->ok($result);
    }

    public function crearFeriado(Request $request): JsonResponse
    {
        $result = $this->parametros->crearFeriado($request->all());
        if (isset($result['error'])) return $this->error($result['error'], 422);
        return $this->ok($result);
    }

    public function actualizarFeriado(Request $request, int $id): JsonResponse
    {
        $result = $this->parametros->actualizarFeriado($id, $request->all());
        if (isset($result['error'])) return $this->error($result['error'], 422);
        return $this->ok($result);
    }

    public function eliminarFeriado(int $id): JsonResponse
    {
        $result = $this->parametros->eliminarFeriado($id);
        if (isset($result['error'])) return $this->error($result['error'], 422);
        return $this->ok($result);
    }

    public function catalogoCargos(): JsonResponse
    {
        return $this->ok(RawConnection::select(
            "SELECT id_cargo AS id, CONVERT(descripcion USING utf8mb4) AS nombre FROM cargo ORDER BY descripcion"
        ));
    }

    public function catalogoAgencias(): JsonResponse
    {
        return $this->ok(RawConnection::select(
            "SELECT id_agencia AS id, CONVERT(descripcion USING utf8mb4) AS nombre FROM agencia ORDER BY descripcion"
        ));
    }
}
