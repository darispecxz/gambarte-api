<?php

namespace App\Modules\Uif\Controllers;

use App\Modules\Shared\Http\ApiController;
use App\Modules\Uif\Services\UifService;
use App\Modules\Uif\Services\UifExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UifController extends ApiController
{
    private UifService  $service;
    private UifExporter $exporter;

    public function __construct(UifService $service, UifExporter $exporter)
    {
        $this->service  = $service;
        $this->exporter = $exporter;
        set_time_limit(120);
    }

    public function agencies(): JsonResponse
    {
        return $this->ok($this->service->agencies());
    }

    public function pccReport(Request $request): JsonResponse
    {
        $pcc = (int) $request->query('pcc');
        if (!in_array($pcc, [6, 8], true)) {
            return $this->error("Parametro 'pcc' debe ser 6 u 8.", 422);
        }

        $desde   = $this->optionalFecha($request, 'desde');
        $hasta   = $this->optionalFecha($request, 'hasta');
        $agencia = $this->intParam($request, 'agencia');

        $rows = $this->service->pccReport($pcc, $desde, $hasta, $agencia);

        return $this->ok([
            'pcc'     => $pcc,
            'desde'   => $desde,
            'hasta'   => $hasta,
            'agencia' => $agencia,
            'total'   => count($rows),
            'records' => $rows,
        ]);
    }

    public function cambios(Request $request): JsonResponse
    {
        $desde   = $this->optionalFecha($request, 'desde');
        $hasta   = $this->optionalFecha($request, 'hasta');
        $agencia = $this->intParam($request, 'agencia');

        $rows = $this->service->cambios($desde, $hasta, $agencia);

        return $this->ok([
            'desde'   => $desde,
            'hasta'   => $hasta,
            'agencia' => $agencia,
            'total'   => count($rows),
            'records' => $rows,
        ]);
    }

    public function giros(Request $request): JsonResponse
    {
        $desde   = $this->optionalFecha($request, 'desde');
        $hasta   = $this->optionalFecha($request, 'hasta');
        $agencia = $this->intParam($request, 'agencia');

        $rows = $this->service->giros($desde, $hasta, $agencia);

        return $this->ok([
            'desde'   => $desde,
            'hasta'   => $hasta,
            'agencia' => $agencia,
            'total'   => count($rows),
            'records' => $rows,
        ]);
    }

    public function remesas(Request $request): JsonResponse
    {
        $desde   = $this->optionalFecha($request, 'desde');
        $hasta   = $this->optionalFecha($request, 'hasta');
        $agencia = $this->intParam($request, 'agencia');

        $rows = $this->service->remesas($desde, $hasta, $agencia);

        return $this->ok([
            'desde'   => $desde,
            'hasta'   => $hasta,
            'agencia' => $agencia,
            'total'   => count($rows),
            'records' => $rows,
        ]);
    }

    public function usuarioFinanciero(Request $request): JsonResponse
    {
        $tipoDoc   = $this->intParam($request, 'tipo_doc');
        $documento = $request->query('documento');
        $expedicion = $this->intParam($request, 'expedicion');
        $nombres   = $request->query('nombres');
        $apePat    = $request->query('ape_pat');
        $apeMat    = $request->query('ape_mat');
        $agencia   = $this->intParam($request, 'agencia');
        $operacion = $this->intParam($request, 'operacion');
        $desde     = $this->optionalFecha($request, 'desde');
        $hasta     = $this->optionalFecha($request, 'hasta');

        $rows = $this->service->usuarioFinanciero(
            $tipoDoc, $documento, $expedicion,
            $nombres, $apePat, $apeMat,
            $agencia, $operacion, $desde, $hasta
        );

        return $this->ok([
            'total'   => count($rows),
            'records' => $rows,
        ]);
    }

    public function export(Request $request)
    {
        $report = trim($request->query('report', ''));
        if (!in_array($report, ['cambios', 'giros', 'remesas'], true)) {
            return $this->error("Parametro 'report' debe ser cambios, giros o remesas.", 422);
        }

        $desde   = $this->optionalFecha($request, 'desde');
        $hasta   = $this->optionalFecha($request, 'hasta');
        $agencia = $this->intParam($request, 'agencia');

        $result = $this->service->export($report, $desde, $hasta, $agencia);

        return response($result['binary'], 200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => "attachment; filename=\"{$result['filename']}\"",
            'Content-Length'      => strlen($result['binary']),
            'Cache-Control'       => 'no-store',
        ]);
    }

    public function pcc06Pdf(int $id)
    {
        $result = $this->exporter->pcc06Pdf($id);

        return response($result['binary'], 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$result['filename']}\"",
            'Content-Length'      => strlen($result['binary']),
            'Cache-Control'       => 'no-store',
        ]);
    }

    public function pcc08Pdf(int $operacion, int $id)
    {
        $result = $this->exporter->pcc08Pdf($operacion, $id);

        return response($result['binary'], 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$result['filename']}\"",
            'Content-Length'      => strlen($result['binary']),
            'Cache-Control'       => 'no-store',
        ]);
    }

    public function pccBlank(Request $request, int $type)
    {
        $claims = $request->attributes->get('jwt_claims', []);
        $idAgencia = $claims['id_agencia'] ?? 0;
        $result = $this->exporter->pccBlank($type, $idAgencia);

        return response($result['binary'], 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$result['filename']}\"",
            'Content-Length'      => strlen($result['binary']),
            'Cache-Control'       => 'no-store',
        ]);
    }

    public function reportPdf(int $id)
    {
        $result = $this->exporter->reportPdf($id);

        return response($result['binary'], 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "inline; filename=\"{$result['filename']}\"",
            'Content-Length'      => strlen($result['binary']),
            'Cache-Control'       => 'no-store',
        ]);
    }
}
