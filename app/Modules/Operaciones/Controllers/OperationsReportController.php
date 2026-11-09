<?php

namespace App\Modules\Operaciones\Controllers;

use App\Modules\Operaciones\Services\OperationsReportService;
use App\Modules\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperationsReportController extends ApiController
{
    private OperationsReportService $service;

    public function __construct(OperationsReportService $service)
    {
        $this->service = $service;
    }

    public function cambios(Request $request): JsonResponse
    {
        $desde    = $this->requireFecha($request, 'desde');
        $hasta    = $this->optionalFecha($request, 'hasta');
        $agencia  = $this->intParam($request, 'agencia');
        $operacion = $this->intParam($request, 'operacion');

        $result = $this->service->cambios($desde, $hasta, $agencia, $operacion);

        return $this->ok([
            'desde'   => $desde,
            'hasta'   => $hasta ?: $desde,
            'total'   => count($result),
            'records' => $result,
        ]);
    }

    public function giros(Request $request): JsonResponse
    {
        $desde   = $this->requireFecha($request, 'desde');
        $hasta   = $this->optionalFecha($request, 'hasta');
        $agencia = $this->intParam($request, 'agencia');
        $estado  = $request->filled('estado') ? trim($request->query('estado')) : null;
        $codigo  = $this->intParam($request, 'codigo');

        $result = $this->service->giros($desde, $hasta, $agencia, $estado, $codigo);

        return $this->ok([
            'desde'   => $desde,
            'hasta'   => $hasta ?: $desde,
            'total'   => count($result),
            'records' => $result,
        ]);
    }

    public function remesas(Request $request): JsonResponse
    {
        $desde   = $this->requireFecha($request, 'desde');
        $hasta   = $this->optionalFecha($request, 'hasta');
        $agencia = $this->intParam($request, 'agencia');
        $estado  = $request->filled('estado') ? trim($request->query('estado')) : null;
        $subtipo = $request->filled('subtipo') ? trim($request->query('subtipo')) : null;
        $codigo  = $this->intParam($request, 'codigo');

        $result = $this->service->remesas($desde, $hasta, $agencia, $estado, $subtipo, $codigo);

        return $this->ok([
            'desde'   => $desde,
            'hasta'   => $hasta ?: $desde,
            'total'   => count($result),
            'records' => $result,
        ]);
    }

    public function remesasCgr(Request $request): JsonResponse
    {
        $desde   = $this->requireFecha($request, 'desde');
        $hasta   = $this->optionalFecha($request, 'hasta');
        $agencia = $this->intParam($request, 'agencia');
        $estado  = $request->filled('estado') ? trim($request->query('estado')) : null;
        $codigo  = $this->intParam($request, 'codigo');
        $correlativoPais = $this->intParam($request, 'correlativo_pais');

        $result = $this->service->remesasCgr($desde, $hasta, $agencia, $estado, $codigo, $correlativoPais);

        return $this->ok([
            'desde'   => $desde,
            'hasta'   => $hasta ?: $desde,
            'total'   => count($result),
            'records' => $result,
        ]);
    }

    public function detail(Request $request, string $type, int $id): JsonResponse
    {
        $result = null;

        switch ($type) {
            case 'cambio':
                $result = $this->service->cambioDetail($id);
                break;
            case 'giro':
                $result = $this->service->giroDetail($id);
                break;
            case 'remesa':
                $result = $this->service->remesaDetail($id);
                break;
            default:
                abort(422, "Tipo de operación inválido: {$type}");
        }

        if ($result === null) {
            abort(404, 'Operación no encontrada.');
        }

        return $this->ok($result);
    }
}
