<?php

namespace App\Modules\Contabilidad\Controllers;

use App\Modules\Contabilidad\Services\CashAuditService;
use App\Modules\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashAuditController extends ApiController
{
    private CashAuditService $service;

    public function __construct(CashAuditService $service)
    {
        $this->service = $service;
    }

    public function report(Request $request): JsonResponse
    {
        return $this->ok($this->service->report($this->requireFecha($request), $this->intParam($request, 'agencia')));
    }

    public function detail(Request $request, int $idCaja): JsonResponse
    {
        $detail = $this->service->detail($this->requireFecha($request), $idCaja, $this->intParam($request, 'agencia'));
        return $detail ? $this->ok($detail) : $this->error('Caja no encontrada para la fecha.', 404);
    }

    public function pdf(Request $request)
    {
        $fecha = $this->requireFecha($request);
        $agencia = $this->intParam($request, 'agencia');
        $claims = $request->attributes->get('jwt_claims', []);
        $user = $claims['user'] ?? [];
        $name = trim((string) ($user['nombre'] ?? $user['name'] ?? ''));
        $login = trim((string) ($user['login'] ?? $claims['sub'] ?? ''));
        $generatedBy = $name !== '' ? $name.($login !== '' ? ' ('.strtoupper($login).')' : '') : strtoupper($login);
        $binary = $this->service->pdf($fecha, $agencia, $generatedBy);
        $name = 'Informe_Arqueo_Cajas_' . $fecha . ($agencia ? '_Agencia_' . $agencia : '') . '.pdf';
        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $name . '"',
            'Content-Length' => strlen($binary),
            'Cache-Control' => 'no-store',
        ]);
    }

    public function followup(Request $request, int $idCaja): JsonResponse
    {
        $data = $request->validate([
            'fecha' => 'required|date_format:Y-m-d',
            'estado' => 'required|in:en_investigacion,accion_pendiente,resuelto',
            'responsable' => 'required|string|max:160',
            'fechaCompromiso' => 'nullable|date_format:Y-m-d',
            'observacion' => 'required|string|max:2000',
        ]);
        $claims = $request->attributes->get('jwt_claims', []);
        try {
            return $this->ok($this->service->recordFollowup($data['fecha'], $idCaja, $data, (string) ($claims['user']['login'] ?? $claims['sub'] ?? 'desconocido')));
        } catch (\InvalidArgumentException $e) {
            return $this->error($e->getMessage(), 422);
        }
    }
}
