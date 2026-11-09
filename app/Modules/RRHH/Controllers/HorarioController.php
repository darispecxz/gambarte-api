<?php

namespace App\Modules\RRHH\Controllers;

use App\Modules\RRHH\Services\HorarioService;
use App\Modules\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HorarioController extends ApiController
{
    private HorarioService $horario;

    public function __construct(HorarioService $horario)
    {
        $this->horario = $horario;
    }

    public function listar(): JsonResponse
    {
        return $this->ok($this->horario->listarHorarios());
    }

    public function porNombre(string $nombre): JsonResponse
    {
        return $this->ok($this->horario->horariosPorNombre($nombre));
    }

    public function porContrato(Request $request, int $idContrato): JsonResponse
    {
        $fecha = $this->optionalFecha($request, 'fecha');
        return $this->ok($this->horario->horarioPorContrato($idContrato, $fecha));
    }

    public function asignaciones(Request $request): JsonResponse
    {
        $agencia = $this->intParam($request, 'agencia');
        return $this->ok($this->horario->asignacionesVigentes($agencia));
    }

    public function sinHorario(): JsonResponse
    {
        return $this->ok($this->horario->empleadosSinHorario());
    }

    public function resumenSemanal(Request $request, int $idContrato): JsonResponse
    {
        $fecha = $this->optionalFecha($request, 'fecha');
        return $this->ok($this->horario->resumenSemanal($idContrato, $fecha));
    }

    public function crear(Request $request): JsonResponse
    {
        $result = $this->horario->crear($request->all());
        if (isset($result['error'])) return $this->error($result['error'], 422);
        return $this->ok($result);
    }

    public function actualizar(Request $request, int $id): JsonResponse
    {
        $result = $this->horario->actualizar($id, $request->all());
        if (isset($result['error'])) return $this->error($result['error'], 422);
        return $this->ok($result);
    }

    public function desactivar(int $id): JsonResponse
    {
        $result = $this->horario->desactivar($id);
        if (isset($result['error'])) return $this->error($result['error'], 422);
        return $this->ok($result);
    }

    public function asignar(Request $request): JsonResponse
    {
        $result = $this->horario->asignar($request->all());
        if (isset($result['error'])) return $this->error($result['error'], 422);
        return $this->ok($result);
    }
}
