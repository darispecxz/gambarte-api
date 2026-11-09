<?php

namespace App\Modules\Discrepancias\Controllers;

use App\Modules\Discrepancias\Services\DiscrepancyService;
use App\Modules\Discrepancias\Services\ReconciliationEngine;
use App\Modules\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DiscrepancyController extends ApiController
{
    private DiscrepancyService $service;
    private ReconciliationEngine $engine;

    public function __construct(DiscrepancyService $service, ReconciliationEngine $engine)
    {
        $this->service = $service;
        $this->engine  = $engine;
    }

    public function index(Request $request): JsonResponse
    {
        $filters = [];
        foreach (['type', 'severity', 'status', 'operation_type', 'desde', 'hasta'] as $k) {
            if ($request->filled($k)) {
                $filters[$k] = trim($request->query($k));
            }
        }

        $rows = $this->service->list($filters);

        return $this->ok([
            'total'         => count($rows),
            'discrepancies' => $rows,
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $filters = [];
        if ($request->filled('desde')) $filters['desde'] = $request->query('desde');
        if ($request->filled('hasta')) $filters['hasta'] = $request->query('hasta');

        return $this->ok($this->service->summary($filters));
    }

    public function run(): JsonResponse
    {
        $created = $this->engine->run();

        return $this->ok([
            'created' => $created,
            'message' => "Reconciliación completada. {$created} discrepancias nuevas detectadas.",
        ]);
    }

    public function resolve(Request $request, int $id): JsonResponse
    {
        $body = $request->json()->all();

        if (empty($body['resolution_type']) || !isset($body['notes'])) {
            abort(422, 'Se requiere resolution_type y notes.');
        }

        $userId = isset($body['user_id']) ? (int) $body['user_id'] : 0;
        $ok = $this->service->resolve($id, $body['resolution_type'], $body['notes'], $userId);

        if (!$ok) {
            return $this->error('Discrepancia no encontrada o ya resuelta.', 404);
        }

        return $this->ok(null, 'Discrepancia resuelta.');
    }

    public function types(): JsonResponse
    {
        return $this->ok([
            'data_integrity' => [
                ['code' => 'DUPLICATE_SAME_SYSTEM', 'label' => 'Duplicado en mismo sistema'],
                ['code' => 'ORPHAN_OPERATION',      'label' => 'Operación huérfana'],
                ['code' => 'GHOST_OPERATION',        'label' => 'Operación fantasma'],
            ],
            'accounting' => [
                ['code' => 'ACCOUNTING_AMOUNT',  'label' => 'Monto contable diferente'],
                ['code' => 'ACCOUNTING_MISSING', 'label' => 'Asiento contable faltante'],
                ['code' => 'DEBIT_CREDIT_MISMATCH', 'label' => 'Debe ≠ Haber'],
            ],
            'exchange_rate' => [
                ['code' => 'RATE_DIFFERENT',          'label' => 'Tasa diferente a cotización'],
                ['code' => 'ROUNDING_INCONSISTENT', 'label' => 'Redondeo inconsistente'],
            ],
            'workflow' => [
                ['code' => 'VOID_WITHOUT_REVERSAL', 'label' => 'Anulado sin reverso contable'],
            ],
        ]);
    }
}
