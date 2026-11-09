<?php

namespace App\Modules\Contabilidad\Controllers;

use App\Modules\Contabilidad\Services\AccountingService;
use App\Modules\Shared\Http\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountingController extends ApiController
{
    private AccountingService $service;

    public function __construct(AccountingService $service)
    {
        $this->service = $service;
    }

    public function dailySummary(Request $request): JsonResponse
    {
        $fecha = $this->requireFecha($request);
        return $this->ok(['fecha' => $fecha, 'report' => $this->service->dailySummary($fecha)]);
    }

    public function cashierDetails(Request $request): JsonResponse
    {
        $fecha   = $this->requireFecha($request);
        $agencia = $this->intParam($request, 'agencia');

        return $this->ok([
            'fecha'   => $fecha,
            'agencia' => $agencia,
            'report'  => $this->service->cashierDetails($fecha, $agencia),
        ]);
    }

    public function movementSummary(Request $request): JsonResponse
    {
        $fecha = $this->requireFecha($request);
        return $this->ok(['fecha' => $fecha, 'report' => $this->service->movementSummary($fecha)]);
    }

    public function agencyClosings(Request $request): JsonResponse
    {
        $fecha = $this->requireFecha($request);
        return $this->ok(['fecha' => $fecha, 'report' => $this->service->agencyClosings($fecha)]);
    }

    public function agencies(): JsonResponse
    {
        return $this->ok($this->service->agencies());
    }

    public function ejercicios(): JsonResponse
    {
        return $this->ok($this->service->ejercicios());
    }

    public function subcuentas(Request $request): JsonResponse
    {
        $ej = $this->requireEjercicio($request);
        return $this->ok($this->service->subcuentas($ej));
    }

    public function balance(Request $request): JsonResponse
    {
        [$ej, $desde, $hasta] = $this->fechaRango($request);
        return $this->ok(['report' => $this->service->balance($ej, $desde, $hasta)]);
    }

    public function libroMayor(Request $request): JsonResponse
    {
        $cod = trim($request->query('codsubcuenta', ''));
        if ($cod === '') {
            abort(422, "Parámetro 'codsubcuenta' es requerido.");
        }
        [$ej, $desde, $hasta] = $this->fechaRango($request);
        $page = max(1, (int) $request->query('page', 1));

        return $this->ok(['report' => $this->service->libroMayor($cod, $ej, $desde, $hasta, $page)]);
    }

    public function asientoDetalle(Request $request): JsonResponse
    {
        $id = (int) $request->query('id', 0);
        if ($id <= 0) {
            abort(422, "Parámetro 'id' es requerido.");
        }

        $result = $this->service->asientoDetalle($id);
        if ($result === null) {
            abort(422, 'Asiento no encontrado.');
        }

        return $this->ok($result);
    }

    public function comprobantes(Request $request): JsonResponse
    {
        $desde  = trim($request->query('desde', date('Y') . '-01-01'));
        $hasta  = trim($request->query('hasta', date('Y-m-d')));
        $page   = max(1, (int) $request->query('page', 1));
        $tipo   = $request->query('tipo');
        $codigo = $request->query('codigo');
        $glosa  = $request->query('glosa');

        return $this->ok(['report' => $this->service->comprobantes($desde, $hasta, $page, 500, $tipo, $codigo, $glosa)]);
    }

    public function export(Request $request)
    {
        $report  = $request->query('report', '');
        $format  = $request->query('format', 'xlsx');

        $validReports = ['daily', 'cashier', 'movement', 'agency', 'comprobantes'];
        if (!in_array($report, $validReports, true)) {
            abort(422, "Parámetro 'report' inválido. Use: " . implode(', ', $validReports));
        }
        if ($format !== 'xlsx' && $format !== 'pdf') {
            abort(422, "Parámetro 'format' inválido. Use: xlsx o pdf.");
        }

        if ($report === 'comprobantes') {
            $desde  = trim($request->query('desde', date('Y') . '-01-01'));
            $hasta  = trim($request->query('hasta', date('Y-m-d')));
            $tipo   = $request->query('tipo');
            $codigo = $request->query('codigo');
            $glosa  = $request->query('glosa');
            $result = $this->service->exportComprobantes($format, $desde, $hasta, $tipo, $codigo, $glosa);
        } else {
            $fecha   = $this->requireFecha($request);
            $agencia = $this->intParam($request, 'agencia');
            $result  = $this->service->export($report, $format, $fecha, $agencia);
        }

        $contentType = $format === 'xlsx'
            ? 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
            : 'application/pdf';

        return response($result['binary'], 200, [
            'Content-Type'        => $contentType,
            'Content-Disposition' => "attachment; filename=\"{$result['filename']}\"",
            'Content-Length'      => strlen($result['binary']),
            'Cache-Control'       => 'no-store',
        ]);
    }
}
