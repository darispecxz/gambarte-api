<?php

namespace App\Modules\Operaciones\Controllers;

use App\Modules\Operaciones\Services\DashboardService;
use App\Modules\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OperationsController extends ApiController
{
    private DashboardService $service;

    public function __construct(DashboardService $service)
    {
        $this->service = $service;
    }

    public function index(): JsonResponse
    {
        return $this->ok(['actions' => $this->service->actions()]);
    }

    public function show(Request $request, string $action): JsonResponse
    {
        $actions = $this->service->actions();
        if (!in_array($action, $actions, true)) {
            abort(422, "Valor inválido para 'action'. Permitidos: " . implode(', ', $actions) . '.');
        }

        [$desde, $hasta] = $this->resolveRango($request);

        $extra = array_filter([
            'moneda'    => $request->query('moneda'),
            'dias'      => $request->query('dias'),
            'desde_ant' => $this->validDate($request->query('desde_ant')),
            'hasta_ant' => $this->validDate($request->query('hasta_ant')),
        ], function ($v) { return $v !== null; });

        $data = $this->service->execute($action, $desde, $hasta, $extra);

        return $this->ok(['action' => $action, 'dateFrom' => $desde, 'dateTo' => $hasta, 'result' => $data]);
    }

    private function resolveRango(Request $request): array
    {
        $desde = $this->validDate($request->query('dateFrom'))
              ?? $this->validDatePartial($request->query('desde'))
              ?? date('Y-m-01');

        $hasta = $request->query('dateTo')
            ? ($this->validDate($request->query('dateTo')) . ' 23:59:59')
            : ($request->query('hasta') ?? date('Y-m-d') . ' 23:59:59');

        return [$desde, $hasta];
    }

    private function validDate(?string $s): ?string
    {
        if ($s && preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) && strtotime($s) !== false) {
            return $s;
        }
        return null;
    }

    private function validDatePartial(?string $s): ?string
    {
        if ($s && preg_match('/^\d{4}-\d{2}-\d{2}/', substr($s, 0, 10)) && strtotime(substr($s, 0, 10)) !== false) {
            return $s;
        }
        return null;
    }
}
